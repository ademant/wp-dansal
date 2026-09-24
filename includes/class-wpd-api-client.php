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

	const TOKEN_TRANSIENT      = 'wpd_dansal_session_token';
	const RENEW_LOCK           = 'wpd_apikey_renew_lock';
	const TILE_TOKEN_TRANSIENT = 'wpd_dansal_tile_token';

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
	 * Rotate the publisher's HMAC signing secret via
	 * `POST /api/v1/apikeys/rotate-signing-secret` (dansal #1366). Uses
	 * the raw api_key as the bearer — same auth model as renew_apikey.
	 * dansal responds with `{ signing_secret: "..." }` and hard-swaps
	 * server-side, so we store the new secret unconditionally on 200.
	 *
	 * @return true|WP_Error
	 */
	public function rotate_signing_secret() {
		$api_key = $this->settings->get_api_key();
		if ( '' === $api_key ) {
			return new WP_Error( 'wpd_no_api_key', __( 'No dansal API key configured.', 'wp-dansal' ) );
		}
		$url  = $this->settings->get_base_url() . '/api/v1/apikeys/rotate-signing-secret';
		$args = array(
			'method'  => 'POST',
			'timeout' => self::timeout( '/api/v1/apikeys/rotate-signing-secret' ),
			'headers' => array(
				'Authorization' => 'Bearer ' . $api_key,
				'Accept'        => 'application/json',
			),
		);
		$response = wp_remote_request( $url, $args );
		$response = $this->maybe_retry_after( $response, $url, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 === $code && is_array( $body ) && ! empty( $body['signing_secret'] ) ) {
			$this->settings->record_signing_secret( (string) $body['signing_secret'] );
			return true;
		}
		if ( 404 === $code ) {
			return new WP_Error( 'wpd_signing_unsupported', __( 'This dansal server does not support signing-secret rotation. Upgrade dansal to a build that includes the rotate-signing-secret endpoint.', 'wp-dansal' ) );
		}
		$message = is_array( $body ) && ! empty( $body['error'] ) ? $body['error'] : sprintf( 'HTTP %d', $code );
		return new WP_Error( 'wpd_rotate_signing_failed', $message );
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
	public function request( $method, $path, $body = null, $query = array(), $extra_headers = array() ) {
		$token = $this->get_session_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$result = $this->do_request( $method, $path, $body, $query, $token, $extra_headers );

		// Token may have just expired/been invalidated (e.g. IP change) — re-exchange once and retry.
		if ( is_wp_error( $result ) && 'wpd_http_401' === $result->get_error_code() ) {
			$token = $this->get_session_token( true );
			if ( is_wp_error( $token ) ) {
				return $token;
			}
			$result = $this->do_request( $method, $path, $body, $query, $token, $extra_headers );
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

	private function do_request( $method, $path, $body, $query, $token, $extra_headers = array() ) {
		$url = $this->settings->get_base_url() . $path;
		if ( ! empty( $query ) ) {
			$url = add_query_arg( array_map( 'rawurlencode', $query ), $url );
		}

		$args = array(
			'method'  => $method,
			'timeout' => self::timeout( $path ),
			'headers' => array_merge(
				array(
					'Authorization' => 'Bearer ' . $token,
					'Accept'        => 'application/json',
				),
				is_array( $extra_headers ) ? $extra_headers : array()
			),
		);

		if ( null !== $body ) {
			// dansal requires RFC 7396 merge-patch content-type on PATCH; other
			// methods get plain JSON.
			$args['headers']['Content-Type'] = ( 'PATCH' === $method ) ? 'application/merge-patch+json' : 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}

		$this->apply_signing_headers( $method, $path, $query, $args );

		$response = wp_remote_request( $url, $args );
		$response = $this->maybe_retry_after( $response, $url, $args );
		return $this->handle_response( $response );
	}

	/**
	 * HMAC request signing per dansal #1366. When a signing secret is
	 * stored, every authenticated write carries three headers dansal
	 * verifies before running any handler code — a leaked Bearer token
	 * alone can't replay a request without also holding the signing
	 * secret. Canonical payload is five ASCII lines joined by real
	 * newlines (the previous code used `'\n'` in a PHP single-quoted
	 * string, i.e. a literal backslash-n — never matched anything on
	 * the server side):
	 *
	 *   <METHOD>
	 *   <path>[?<canonical query>]      ← keys alphabetized, RFC3986 encoded
	 *   <unix ts>
	 *   <lowercase-hex sha256(body)>    ← empty string when no body
	 *   <32-hex-char CSPRNG nonce>
	 *
	 * dansal recomputes the same form from parsed query params (never
	 * the literal wire bytes), so wire ordering is irrelevant — both
	 * sides ksort() before hashing.
	 */
	private function apply_signing_headers( $method, $path, $query, &$args ) {
		$signing_secret = $this->settings->get_signing_secret();
		if ( empty( $signing_secret ) ) {
			return;
		}
		$canonical_path = $path;
		if ( ! empty( $query ) ) {
			$sorted = $query;
			ksort( $sorted );
			$canonical_path .= '?' . http_build_query( $sorted, '', '&', PHP_QUERY_RFC3986 );
		}
		$body_str = isset( $args['body'] ) ? $args['body'] : '';
		$body_sha = hash( 'sha256', $body_str );
		$ts       = (string) time();
		$nonce    = bin2hex( random_bytes( 16 ) );
		$payload  = $method . "\n" . $canonical_path . "\n" . $ts . "\n" . $body_sha . "\n" . $nonce;
		$sig      = hash_hmac( 'sha256', $payload, $signing_secret );
		$args['headers']['X-Wpd-Timestamp'] = $ts;
		$args['headers']['X-Wpd-Nonce']     = $nonce;
		$args['headers']['X-Wpd-Signature'] = $sig;
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

	/**
	 * Walk every page of a list endpoint. dansal's list endpoints
	 * document `limit` (default 100, max 1000), `offset`, `X-Total-Count`,
	 * and a strong `ETag` on the response. Hard-capped at 5000 rows
	 * (`wpd_full_sync_cap` filter) so a runaway org can never stall an
	 * admin page load.
	 *
	 * #136:
	 *  - `limit=1000` (was 500) halves the round-trips.
	 *  - Stop-condition uses `X-Total-Count` when the server sends it,
	 *    with the short-page fallback for older servers.
	 *  - When the caller supplies `$if_none_match` (last-seen ETag),
	 *    the first request sends `If-None-Match`. A `304 Not Modified`
	 *    short-circuits the walk and returns `null` — signalling "no
	 *    change since last pull" so the caller can skip the diff loop.
	 *
	 * @param string       $path
	 * @param array        $query
	 * @param string|null  $if_none_match Optional weak/strong ETag from a
	 *                                    previous fetch.
	 * @return array|null|WP_Error Row list, `null` on 304, or the first
	 *                             WP_Error hit. When a caller passes
	 *                             `$out_etag` (by-reference), the server's
	 *                             response ETag is written to it.
	 */
	public function get_all_pages( $path, $query = array(), $if_none_match = null, &$out_etag = null ) {
		$limit  = 1000;
		$cap    = (int) apply_filters( 'wpd_full_sync_cap', 5000, $path );
		$offset = 0;
		$out    = array();
		$total  = null;
		$out_etag = null;
		while ( true ) {
			$headers = array();
			if ( 0 === $offset && null !== $if_none_match && '' !== $if_none_match ) {
				$headers['If-None-Match'] = $if_none_match;
			}
			$response = $this->request_raw(
                'GET',
                $path,
                null,
                array_merge(
                    $query,
                    array(
						'limit' => $limit,
						'offset' => $offset,
                    )
                ),
                $headers
            );
			if ( is_wp_error( $response ) ) {
				return $response;
			}
			$status = (int) $response['status'];
			if ( 304 === $status && 0 === $offset ) {
				// dansal confirmed the caller's ETag; nothing to do.
				return null;
			}
			$page = is_array( $response['body'] ) ? $response['body'] : array();
			if ( 0 === $offset ) {
				$out_etag = $response['headers']['etag'] ?? '';
				$total_hdr = $response['headers']['x-total-count'] ?? '';
				if ( '' !== $total_hdr && ctype_digit( (string) $total_hdr ) ) {
					$total = (int) $total_hdr;
				}
			}
			if ( empty( $page ) ) {
				break;
			}
			$out = array_merge( $out, $page );
			$got = count( $out );
			// Stop when the server told us how many rows there are, or
			// when the current page is short (older servers without
			// X-Total-Count), or when we hit the runaway cap.
			if ( null !== $total && $got >= $total ) {
				break;
			}
			if ( count( $page ) < $limit || $got >= $cap ) {
				break;
			}
			$offset += $limit;
		}
		return $out;
	}

	/**
	 * Internal counterpart of request() that keeps response headers and
	 * status code available to the caller — get_all_pages() needs
	 * `X-Total-Count` and `ETag`, and #135's If-Match/412 handling wants
	 * the raw 412 status without header stripping. Handles the same
	 * session-token refresh dance as request().
	 *
	 * @return array{status:int,headers:array<string,string>,body:mixed}|WP_Error
	 */
	private function request_raw( $method, $path, $body, $query, $headers = array() ) {
		$token = $this->get_session_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$response = $this->do_request_raw( $method, $path, $body, $query, $token, $headers );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( 401 === $response['status'] ) {
			$token = $this->get_session_token( true );
			if ( is_wp_error( $token ) ) {
				return $token;
			}
			$response = $this->do_request_raw( $method, $path, $body, $query, $token, $headers );
			if ( is_wp_error( $response ) ) {
				return $response;
			}
		}
		return $response;
	}

	/**
	 * Low-level counterpart of do_request(): performs the HTTP call and
	 * returns `{status, headers, body}` without translating non-2xx into
	 * WP_Error (except for transport errors). Callers decide how to
	 * interpret 304/412/etc.
	 */
	private function do_request_raw( $method, $path, $body, $query, $token, $headers = array() ) {
		$url = $this->settings->get_base_url() . $path;
		if ( ! empty( $query ) ) {
			$url = add_query_arg( array_map( 'rawurlencode', $query ), $url );
		}
		$args = array(
			'method'  => $method,
			'timeout' => self::timeout( $path ),
			'headers' => array_merge(
				array(
					'Authorization' => 'Bearer ' . $token,
					'Accept'        => 'application/json',
				),
				is_array( $headers ) ? $headers : array()
			),
		);
		if ( null !== $body ) {
			$args['headers']['Content-Type'] = ( 'PATCH' === $method ) ? 'application/merge-patch+json' : 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}
		$this->apply_signing_headers( $method, $path, $query, $args );
		$response = wp_remote_request( $url, $args );
		$response = $this->maybe_retry_after( $response, $url, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$raw_headers = wp_remote_retrieve_headers( $response );
		$flat        = array();
		if ( is_object( $raw_headers ) && method_exists( $raw_headers, 'getAll' ) ) {
			foreach ( $raw_headers->getAll() as $k => $v ) {
				$flat[ strtolower( $k ) ] = is_array( $v ) ? implode( ', ', $v ) : (string) $v;
			}
		} elseif ( is_array( $raw_headers ) ) {
			foreach ( $raw_headers as $k => $v ) {
				$flat[ strtolower( $k ) ] = is_array( $v ) ? implode( ', ', $v ) : (string) $v;
			}
		}
		$raw  = wp_remote_retrieve_body( $response );
		$body = '' !== $raw ? json_decode( $raw, true ) : null;
		return array(
			'status'  => (int) wp_remote_retrieve_response_code( $response ),
			'headers' => $flat,
			'body'    => $body,
		);
	}

	public function post( $path, $body ) {
		return $this->request( 'POST', $path, $body );
	}

	public function patch( $path, $body, $extra_headers = array() ) {
		return $this->request( 'PATCH', $path, $body, array(), $extra_headers );
	}

	public function put( $path, $body, $extra_headers = array() ) {
		return $this->request( 'PUT', $path, $body, array(), $extra_headers );
	}

	public function delete( $path ) {
		return $this->request( 'DELETE', $path );
	}

	/**
	 * Multipart file upload (dansal's image endpoints, e.g. POST
	 * /api/v1/images/{event_id}, expect multipart/form-data — every other
	 * endpoint in this client is JSON, so this is kept separate from
	 * do_request() rather than teaching it a second body encoding).
	 * WP core has no built-in multipart helper, so the body is hand-built
	 * per RFC 2388 and sent as a raw string.
	 *
	 * @param string $file_path  Absolute path to the file to upload.
	 * @param string $field_name Multipart field name the server expects.
	 * @return array|WP_Error Decoded JSON body on success.
	 */
	public function post_multipart( $path, $file_path, $field_name = 'image' ) {
		$token = $this->get_session_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$result = $this->do_multipart_request( $path, $file_path, $field_name, $token );
		if ( is_wp_error( $result ) && 'wpd_http_401' === $result->get_error_code() ) {
			$token = $this->get_session_token( true );
			if ( is_wp_error( $token ) ) {
				return $token;
			}
			$result = $this->do_multipart_request( $path, $file_path, $field_name, $token );
		}
		return $result;
	}

	private function do_multipart_request( $path, $file_path, $field_name, $token ) {
		$contents = is_readable( $file_path ) ? file_get_contents( $file_path ) : false;
		if ( false === $contents ) {
			return new WP_Error( 'wpd_image_unreadable', __( 'Image file is not readable.', 'wp-dansal' ) );
		}

		$boundary  = wp_generate_password( 24, false );
		$filename  = basename( $file_path );
		$filetype  = wp_check_filetype( $filename );
		$mime_type = ! empty( $filetype['type'] ) ? $filetype['type'] : 'application/octet-stream';

		$body  = "--{$boundary}\r\n";
		$body .= 'Content-Disposition: form-data; name="' . $field_name . '"; filename="' . $filename . '"' . "\r\n";
		$body .= "Content-Type: {$mime_type}\r\n\r\n";
		$body .= $contents . "\r\n";
		$body .= "--{$boundary}--\r\n";

		$url  = $this->settings->get_base_url() . $path;
		$args = array(
			'method'  => 'POST',
			'timeout' => self::timeout( $path ),
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Accept'        => 'application/json',
				'Content-Type'  => 'multipart/form-data; boundary=' . $boundary,
			),
			'body'    => $body,
		);

		$response = wp_remote_request( $url, $args );
		$response = $this->maybe_retry_after( $response, $url, $args );
		return $this->handle_response( $response );
	}

	/**
	 * Probe whether the dansal server supports self-revoke of the current
	 * publisher API key (DELETE /api/v1/apikeys/current — see dansal #869).
	 * Uses HTTP OPTIONS + Allow header per RFC 7231 §4.3.7. Callers get a
	 * plain bool: true when Allow lists DELETE, false otherwise (route
	 * missing, older dansal, transport error). Never throws.
	 */
	public function apikey_delete_supported() {
		$url = $this->settings->get_base_url() . '/api/v1/apikeys/current';
		$response = wp_remote_request(
			$url,
			array(
				'method'  => 'OPTIONS',
				'timeout' => self::timeout( '/api/v1/apikeys/current' ),
				'headers' => array( 'Accept' => 'application/json' ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return false;
		}
		$allow = strtoupper( (string) wp_remote_retrieve_header( $response, 'allow' ) );
		if ( '' === $allow ) {
			return false;
		}
		$methods = array_map( 'trim', explode( ',', $allow ) );
		return in_array( 'DELETE', $methods, true );
	}

	/**
	 * Revoke the current publisher API key via DELETE /api/v1/apikeys/current.
	 * Server-side counterpart to admin-triggered disconnect and uninstall
	 * cleanup. Callers should call apikey_delete_supported() first and fall
	 * back to a manual-cleanup hint when this returns wpd_apikey_revoke_unsupported.
	 *
	 * @return true|WP_Error
	 */
	public function revoke_apikey() {
		$api_key = $this->settings->get_api_key();
		if ( '' === $api_key ) {
			return new WP_Error( 'wpd_no_api_key', __( 'No dansal API key configured.', 'wp-dansal' ) );
		}
		$url = $this->settings->get_base_url() . '/api/v1/apikeys/current';
		$args = array(
			'method'  => 'DELETE',
			'timeout' => self::timeout( '/api/v1/apikeys/current' ),
			'headers' => array(
				'Authorization' => 'Bearer ' . $api_key,
				'Accept'        => 'application/json',
			),
		);
		$response = wp_remote_request( $url, $args );
		$response = $this->maybe_retry_after( $response, $url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 204 === $code || ( $code >= 200 && $code < 300 ) ) {
			return true;
		}
		if ( 401 === $code ) {
			// Server already considers the key invalid — treat as revoked.
			return true;
		}
		if ( 404 === $code || 405 === $code ) {
			return new WP_Error( 'wpd_apikey_revoke_unsupported', __( 'This dansal server does not support self-revoke of API keys.', 'wp-dansal' ) );
		}
		$body    = json_decode( wp_remote_retrieve_body( $response ), true );
		$message = is_array( $body ) && ! empty( $body['error'] ) ? $body['error'] : sprintf( 'HTTP %d', $code );
		return new WP_Error( 'wpd_apikey_revoke_failed', $message );
	}

	/**
	 * Fetch one OSM tile through dansal's tile proxy (#109, #111, #118, #120).
	 *
	 * Two auth paths, tried in order:
	 *   1. The publisher API key as an `Authorization: Bearer` header — the
	 *      only auth path dansal's proxy accepts for a real API key (see
	 *      dansal WEB.md, "Map Tile Proxy"). Requires this site to be a
	 *      connected publisher.
	 *   2. dansal's public tile token (#120, dansal #1287) as a `?t=` query
	 *      param, for sites with no API key configured at all (e.g. a
	 *      read-only display of nearby events, never publishing its own).
	 *      Not a secret — every dansal_web page already embeds this value in
	 *      plain HTML — so it's safe to fetch and use even without a
	 *      publisher relationship.
	 *
	 * Both happen here, server-side, in WPD_Frontend::ajax_tile() — a
	 * browser-rendered `<img>`/`L.tileLayer()` request can't attach a custom
	 * header at all, and the API key specifically must never be echoed into
	 * a URL the browser sees.
	 *
	 * @param int $z Zoom level.
	 * @param int $x Tile column.
	 * @param int $y Tile row.
	 * @return string|WP_Error Raw tile image bytes, or WP_Error on failure.
	 */
	public function fetch_tile( $z, $x, $y ) {
		// /tiles/* is served by dansal_web, not the API host — web_url falls
		// back to base_url when unset, so single-host setups are unaffected (#122).
		$web_url = $this->settings->get_web_url();
		if ( '' === $web_url ) {
			return new WP_Error( 'wpd_no_connection', __( 'No dansal instance configured.', 'wp-dansal' ) );
		}

		$api_key = $this->settings->get_api_key();
		if ( '' !== $api_key && ! $this->settings->is_api_key_dead() ) {
			$result = $this->request_tile( $web_url, $z, $x, $y, array( 'Authorization' => 'Bearer ' . $api_key ) );
			if ( ! is_wp_error( $result ) || 'wpd_tile_http_401' !== $result->get_error_code() ) {
				return $result;
			}
			// dansal rejected the key outright — same handling as every other
			// endpoint in this client, so the renew cron/admin notice picks it up.
			// The public token would still have worked for this very request, so
			// fall through to it instead of dropping straight to a raw-OSM fetch.
			$this->settings->mark_apikey_dead();
		}

		// No usable API key — fall back to dansal's public tile token rather
		// than giving up straight to WPD_Frontend::ajax_tile()'s own
		// last-resort raw-OSM fetch, so this site's map still benefits from
		// dansal's own cached tile proxy instead of every wp-dansal install
		// hitting OSM redundantly on its own.
		return $this->fetch_tile_with_public_token( $web_url, $z, $x, $y );
	}

	/**
	 * Token-authenticated tile GET. dansal has no token-rotation UI (an admin
	 * edits site_settings by hand), but when it does happen every cached copy
	 * would otherwise 401 until the transient expires — so a 401 here drops the
	 * cached token and retries once with a freshly fetched one (#122).
	 *
	 * @return string|WP_Error
	 */
	private function fetch_tile_with_public_token( $web_url, $z, $x, $y ) {
		$token = $this->get_public_tile_token( $web_url );
		if ( '' === $token ) {
			return new WP_Error( 'wpd_no_tile_auth', __( 'No usable dansal API key or public tile token.', 'wp-dansal' ) );
		}

		$result = $this->request_tile( $web_url, $z, $x, $y, array(), $token );
		if ( is_wp_error( $result ) && 'wpd_tile_http_401' === $result->get_error_code() ) {
			delete_transient( self::TILE_TOKEN_TRANSIENT );
			$fresh = $this->get_public_tile_token( $web_url );
			if ( '' !== $fresh && $fresh !== $token ) {
				return $this->request_tile( $web_url, $z, $x, $y, array(), $fresh );
			}
		}
		return $result;
	}

	/**
	 * Shared GET to dansal's tile proxy — fetch_tile()'s two auth paths
	 * differ only in how the request is authenticated.
	 *
	 * @param string $web_url Dansal web (dansal_web) base URL, already known non-empty.
	 * @param int    $z Zoom level.
	 * @param int    $x Tile column.
	 * @param int    $y Tile row.
	 * @param array  $headers Extra request headers (e.g. Authorization).
	 * @param string $token   Public tile token to send as `?t=`, or '' for none.
	 * @return string|WP_Error
	 */
	private function request_tile( $web_url, $z, $x, $y, array $headers, $token = '' ) {
		$url = trailingslashit( $web_url ) . "tiles/osm/{$z}/{$x}/{$y}.png";
		if ( '' !== $token ) {
			$url = add_query_arg( 't', $token, $url );
		}
		$response = wp_remote_get(
			$url,
			array(
				'timeout' => self::timeout( '/tiles/osm' ),
				'headers' => $headers,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			/* translators: %d: HTTP status code returned by the tile proxy. */
			return new WP_Error( 'wpd_tile_http_' . $code, sprintf( __( 'Tile proxy returned HTTP %d', 'wp-dansal' ), $code ) );
		}

		return wp_remote_retrieve_body( $response );
	}

	/**
	 * Fetch + cache dansal's public tile-proxy token (#120, dansal #1287).
	 * Not a secret — every dansal_web page already embeds it in plain HTML
	 * (view-source readable); this just gives a server-side caller like this
	 * one a way to get it without an API key or a publisher relationship.
	 * Empty string means "no token available" (no web/base URL configured, the
	 * fetch failed, or this dansal instance predates #1287's endpoint) —
	 * callers fall back accordingly, same as an unset API key.
	 *
	 * A failed fetch is still cached, just briefly, so an older dansal
	 * instance without this route (or one that's temporarily down) doesn't
	 * get hit on every single tile request.
	 *
	 * @param string $web_url Dansal web (dansal_web) base URL, already known non-empty.
	 * @return string
	 */
	private function get_public_tile_token( $web_url ) {
		$cached = get_transient( self::TILE_TOKEN_TRANSIENT );
		if ( false !== $cached ) {
			return (string) $cached;
		}

		$url      = trailingslashit( $web_url ) . 'tiles/token';
		$response = wp_remote_get( $url, array( 'timeout' => self::timeout( '/tiles/token' ) ) );

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			set_transient( self::TILE_TOKEN_TRANSIENT, '', 5 * MINUTE_IN_SECONDS );
			return '';
		}

		$body  = json_decode( wp_remote_retrieve_body( $response ), true );
		$token = is_array( $body ) && ! empty( $body['token'] ) ? (string) $body['token'] : '';

		set_transient( self::TILE_TOKEN_TRANSIENT, $token, DAY_IN_SECONDS );
		return $token;
	}
}
