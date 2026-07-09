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

	/** @var WPD_Settings */
	private $settings;

	public function __construct( WPD_Settings $settings ) {
		$this->settings = $settings;
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

		$response = wp_remote_post(
			$this->settings->get_base_url() . '/api/v1/publishers/token',
			array(
				'timeout' => self::timeout( '/api/v1/publishers/token' ),
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Accept'        => 'application/json',
				),
			)
		);

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

		$response = wp_remote_request( $url, $args );
		return $this->handle_response( $response );
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

		$response = wp_remote_get(
            $url,
            array(
				'timeout' => self::timeout( $path ),
				'headers' => array( 'Accept' => 'application/json' ),
            )
        );

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
			$message = is_array( $body ) && ! empty( $body['error'] ) ? $body['error'] : ( $raw ? $raw : sprintf( 'HTTP %d', $code ) );
			return new WP_Error( 'wpd_http_' . $code, $message, array( 'status' => $code ) );
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
