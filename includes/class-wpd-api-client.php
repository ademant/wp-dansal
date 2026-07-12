<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thin HTTP client for the dansal REST API.
 *
 * Auth model (see dansal API.md, "Building a third-party integration on a
 * publisher account"): the plugin holds a long-lived publisher API key
 * (ak_...) in settings, and exchanges it for a short-lived, IP-pinned
 * session token via POST /api/v1/publishers/token. The token is cached in a
 * transient until shortly before it expires; requests that come back 401
 * transparently re-exchange and retry once.
 */
class WPD_Api_Client {

	const TOKEN_TRANSIENT = 'wpd_dansal_session_token';
	const RENEW_LOCK      = 'wpd_apikey_renew_lock';

	/** @var WPD_Settings */
	private $settings;

	public function __construct( WPD_Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Rotate the publisher API key via POST /api/v1/apikeys/renew.
	 *
	 * dansal semantics (API.md, API Keys → Renewal):
	 *   200 → new key issued, old one invalidated immediately
	 *   400 → key has no expires_at (nothing to renew)
	 *   401 → key already expired (admin must re-run connect-link)
	 *
	 * Persists the outcome via WPD_Settings so cron doesn't retry a
	 * no-expiry key or a dead one on every tick. Guarded by a short
	 * transient lock so two concurrent tick+admin-triggered renewals
	 * can't double-invalidate the current key.
	 *
	 * @return true|WP_Error
	 */
	public function renew_apikey() {
		$api_key = $this->settings->get_api_key();
		if ( '' === $api_key ) {
			return new WP_Error( 'wpd_no_api_key', __( 'No dansal API key configured.', 'wp-dansal' ) );
		}
		if ( ! get_transient( self::RENEW_LOCK ) ) {
			set_transient( self::RENEW_LOCK, 1, 30 );
		} else {
			return new WP_Error( 'wpd_renew_locked', __( 'API key renewal already in progress.', 'wp-dansal' ) );
		}

		$url  = $this->settings->get_base_url() . '/api/v1/apikeys/renew';
		$args = array(
			'method'  => 'POST',
			'timeout' => self::timeout( '/api/v1/apikeys/renew' ),
			'headers' => array(
				'Authorization' => 'Bearer ' . $api_key,
				'Accept'        => 'application/json',
			),
		);
		$response = wp_remote_request( $url, $args );
		$response = $this->maybe_retry_after( $response, $url, $args );

		delete_transient( self::RENEW_LOCK );

		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 === $code && ! empty( $body['api_key'] ) ) {
			$expires_at = ! empty( $body['expires_at'] ) ? strtotime( (string) $body['expires_at'] ) : 0;
			$this->settings->record_apikey_renewed( $body['api_key'], $expires_at ? $expires_at : null );
			return true;
		}
		if ( 400 === $code ) {
			$this->settings->mark_apikey_no_expiry();
			return new WP_Error( 'wpd_apikey_no_expiry', __( 'Publisher API key has no expiry; renewal not applicable.', 'wp-dansal' ) );
		}
		if ( 401 === $code ) {
			$this->settings->mark_apikey_dead();
			return new WP_Error( 'wpd_apikey_dead', __( 'Publisher API key has expired. Re-run the connect-link flow to restore the connection.', 'wp-dansal' ) );
		}
		$message = is_array( $body ) && ! empty( $body['error'] ) ? $body['error'] : sprintf( 'HTTP %d', $code );
		return new WP_Error( 'wpd_apikey_renew_failed', $message );
	}

	/**
	 * True when the stored expires_at is inside the renewal lead-time window
	 * (default 7 days). Filter `wpd_apikey_renew_leadtime` to tune (seconds).
	 */
	public function apikey_should_renew() {
		if ( $this->settings->get_api_key_no_expiry() || $this->settings->is_api_key_dead() ) {
			return false;
		}
		$exp = $this->settings->get_api_key_expires_at();
		if ( $exp <= 0 ) {
			// Never checked — attempt once so we discover whether it has an
			// expiry (200) or not (400 → mark no-expiry).
			return true;
		}
		$leadtime = (int) apply_filters( 'wpd_apikey_renew_leadtime', 7 * DAY_IN_SECONDS );
		return ( $exp - time() ) < $leadtime;
	}

	/**
	 * All outbound HTTP calls run through this — 10 seconds by default so a
	 * slow dansal can't stall a page load. Filter `wpd_http_timeout` (int
	 * seconds, string $path) lets site owners tune per-endpoint.
	 */
	public static function timeout( $path = '' ) {
		return (int) apply_filters( 'wpd_http_timeout', 10, $path );
	}

	/**
	 * Get a valid session token, exchanging the API key if needed.
	 *
	 * @param bool $force Bypass the cache and force a fresh exchange.
	 * @return string|WP_Error
	 */
	public function get_session_token( $force = false ) {
		if ( ! $force ) {
			$cached = get_transient( self::TOKEN_TRANSIENT );
			if ( $cached ) {
				return $cached;
			}
		}

		$api_key = $this->settings->get_api_key();
		if ( '' === $api_key ) {
			return new WP_Error( 'wpd_no_api_key', __( 'No dansal API key configured.', 'wp-dansal' ) );
		}

		$token_url  = $this->settings->get_base_url() . '/api/v1/publishers/token';
		$token_args = array(
			'method'  => 'POST',
			'timeout' => self::timeout( '/api/v1/publishers/token' ),
			'headers' => array(
				'Authorization' => 'Bearer ' . $api_key,
				'Accept'        => 'application/json',
			),
		);
		$response   = wp_remote_request( $token_url, $token_args );
		$response   = $this->maybe_retry_after( $response, $token_url, $token_args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 || empty( $body['token'] ) ) {
			$message = is_array( $body ) && ! empty( $body['error'] ) ? $body['error'] : sprintf( 'HTTP %d', $code );
			return new WP_Error( 'wpd_token_exchange_failed', $message );
		}

		$token = $body['token'];

		$ttl = HOUR_IN_SECONDS;
		if ( ! empty( $body['expires_at'] ) ) {
			$expires = strtotime( $body['expires_at'] );
			if ( $expires ) {
				// Refresh a little before actual expiry so we don't race it.
				$ttl = max( 30, $expires - time() - 60 );
			}
		}
		set_transient( self::TOKEN_TRANSIENT, $token, $ttl );

		return $token;
	}

	/**
	 * Authenticated request against the dansal API.
	 *
	 * @param string $method GET|POST|PATCH|DELETE
	 * @param string $path   e.g. '/api/v1/events'
	 * @param array|null $body Associative array, JSON-encoded.
	 * @param array  $query  Query string params.
	 * @return array|WP_Error Decoded JSON body on success.
	 */
	public function request( $method, $path, $body = null, $query = array() ) {
		$token = $this->get_session_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$result = $this->do_request( $method, $path, $body, $query, $token );

		// Token may have just expired/been invalidated (e.g. IP change) — re-exchange once and retry.
		if ( is_wp_error( $result ) && 'wpd_http_401' === $result->get_error_code() ) {
			$token = $this->get_session_token( true );
			if ( is_wp_error( $token ) ) {
				return $token;
			}
			$result = $this->do_request( $method, $path, $body, $query, $token );
			// If we still get a 401, mark the stored publisher API key dead so
			// the admin reconnect notice surfaces immediately instead of
			// continuing to retry silently.
			if ( is_wp_error( $result ) && 'wpd_http_401' === $result->get_error_code() ) {
				try {
					if ( method_exists( $this->settings, 'mark_apikey_dead' ) ) {
						$this->settings->mark_apikey_dead();
					}
				} catch ( Exception $e ) {
					unset( $e ); // Non-fatal — fall through to return the error.
				}
			}
		}

		return $result;
	}

	private function do_request( $method, $path, $body, $query, $token ) {
		$url = $this->settings->get_base_url() . $path;
		if ( ! empty( $query ) ) {
			$url = add_query_arg( array_map( 'rawurlencode', $query ), $url );
		}

		$args = array(
			'method'  => $method,
			'timeout' => self::timeout( $path ),
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Accept'        => 'application/json',
			),
		);

		if ( null !== $body ) {
			// dansal requires RFC 7396 merge-patch content-type on PATCH; other
			// methods get plain JSON.
			$args['headers']['Content-Type'] = ( 'PATCH' === $method ) ? 'application/merge-patch+json' : 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}

		// Optional HMAC request signing when configured in settings. The
		// signature covers method, path, timestamp and body to prevent replay
		// and tampering. Servers must agree on the same scheme.
		$hmac_secret = $this->settings->get( 'hmac_secret' );
		if ( ! empty( $hmac_secret ) ) {
			$ts = (string) time();
			$body_str = isset( $args['body'] ) ? $args['body'] : '';
			$payload = $method . '\n' . $path . '\n' . $ts . '\n' . $body_str;
			$sig = hash_hmac( 'sha256', $payload, $hmac_secret );
			$args['headers']['X-WPD-Timestamp']   = $ts;
			$args['headers']['X-WPD-Signature']   = $sig;
		}

		$response = wp_remote_request( $url, $args );
		$response = $this->maybe_retry_after( $response, $url, $args );
		return $this->handle_response( $response );
	}

	/**
	 * Bounded single retry on 429/503 honoring Retry-After. On any other
	 * status (or a WP_Error transport failure), returns the response as-is
	 * so handle_response() converts it normally.
	 *
	 * @param array|WP_Error $response
	 * @return array|WP_Error
	 */
	private function maybe_retry_after( $response, $url, $args ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 429 !== $code && 503 !== $code ) {
			return $response;
		}
		$header = trim( (string) wp_remote_retrieve_header( $response, 'retry-after' ) );
		if ( '' !== $header && ctype_digit( $header ) ) {
			$delay = max( 1, min( 30, (int) $header ) );
		} else {
			$delay = 429 === $code ? 2 : 5;
		}
		sleep( $delay );
		return wp_remote_request( $url, $args );
	}

	/**
	 * Unauthenticated GET, for public endpoints (info, vocabulary, public
	 * event/location listings).
	 *
	 * @return array|WP_Error
	 */
	public function get_public( $path, $query = array() ) {
		$url = $this->settings->get_base_url() . $path;
		if ( ! empty( $query ) ) {
			$url = add_query_arg( array_map( 'rawurlencode', $query ), $url );
		}

		$args     = array(
			'method'  => 'GET',
			'timeout' => self::timeout( $path ),
			'headers' => array( 'Accept' => 'application/json' ),
		);
		$response = wp_remote_request( $url, $args );
		$response = $this->maybe_retry_after( $response, $url, $args );

		return $this->handle_response( $response );
	}

	private function handle_response( $response ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$body = json_decode( $raw, true );

		if ( $code < 200 || $code >= 300 ) {
			$field = is_array( $body ) && ! empty( $body['field'] ) ? (string) $body['field'] : '';
			$error = is_array( $body ) && ! empty( $body['error'] ) ? (string) $body['error'] : '';
			if ( '' !== $error && '' !== $field ) {
				/* translators: 1: field name from dansal validation error, 2: error message. */
				$message = sprintf( __( '%1$s: %2$s', 'wp-dansal' ), $field, $error );
			} elseif ( '' !== $error ) {
				$message = $error;
			} else {
				$message = $raw ? $raw : sprintf( 'HTTP %d', $code );
			}
			return new WP_Error(
				'wpd_http_' . $code,
				$message,
				array(
					'status' => $code,
					'field'  => '' !== $field ? $field : null,
					'body'   => is_array( $body ) ? $body : null,
				)
			);
		}

		return is_array( $body ) ? $body : array();
	}

	public function get( $path, $query = array() ) {
		return $this->request( 'GET', $path, null, $query );
	}

	public function post( $path, $body ) {
		return $this->request( 'POST', $path, $body );
	}

	public function patch( $path, $body ) {
		return $this->request( 'PATCH', $path, $body );
	}

	public function put( $path, $body ) {
		return $this->request( 'PUT', $path, $body );
	}

	public function delete( $path ) {
		return $this->request( 'DELETE', $path );
	}
}
