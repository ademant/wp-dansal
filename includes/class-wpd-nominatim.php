<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Search-only wrapper around OpenStreetMap's Nominatim, used to turn a
 * free-text venue search into coordinates + osm_id/osm_type when creating a
 * dansal location. Public JS never talks to Nominatim directly; requests go
 * through this proxy so we can set the User-Agent Nominatim's usage policy
 * requires (https://operations.osmfoundation.org/policies/nominatim/) and
 * keep API traffic server-side.
 *
 * The current transport is REST (`GET /wp-json/wpd/v1/nominatim/search` and
 * `/reverse`, registered on `rest_api_init`); the older `wp_ajax_*` handlers
 * (`wpd_nominatim_search`, `wpd_nominatim_reverse`) remain live as a bridge
 * for one release so any custom JS still hitting admin-ajax.php keeps
 * working, and will be removed in the release after this one (#130).
 */
class WPD_Nominatim {

	const ENDPOINT         = 'https://nominatim.openstreetmap.org/search';
	const REVERSE_ENDPOINT = 'https://nominatim.openstreetmap.org/reverse';

	public function __construct() {
		// Legacy admin-ajax endpoints (#130): kept live as bridges to the
		// REST routes below for one release, so any custom JS still calling
		// them via admin-ajax.php keeps working during transition. Slated
		// for removal — see class-level docblock.
		add_action( 'wp_ajax_wpd_nominatim_search', array( $this, 'ajax_search' ) );
		add_action( 'wp_ajax_wpd_nominatim_reverse', array( $this, 'ajax_reverse' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
	}

	/**
	 * Registers the REST counterparts of the two admin-ajax endpoints
	 * (#130). Both are `edit_posts`-gated, so `permission_callback` uses
	 * `current_user_can`; the caller must include the standard `X-WP-Nonce`
	 * header (wp.apiFetch does this automatically for logged-in admin
	 * requests). The response shape is the raw payload — the AJAX bridge
	 * still wraps it in `{success, data}` for legacy JS callers.
	 */
	public function register_rest_routes() {
		register_rest_route(
			'wpd/v1',
			'/nominatim/search',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_search' ),
				'permission_callback' => static function () {
					return current_user_can( 'edit_posts' );
				},
				'args'                => array(
					'q' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => static function ( $v ) {
							return is_string( $v ) && strlen( trim( $v ) ) >= 3;
						},
					),
				),
			)
		);
		register_rest_route(
			'wpd/v1',
			'/nominatim/reverse',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_reverse' ),
				'permission_callback' => static function () {
					return current_user_can( 'edit_posts' );
				},
				'args'                => array(
					'lat' => array(
						'type'     => 'number',
						'required' => true,
					),
					'lng' => array(
						'type'     => 'number',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * REST: text query → list of normalized places. Wraps search() for
	 * error → WP_Error translation; the raw list is returned directly, no
	 * {success, data} envelope (that's the AJAX bridge's convention, not
	 * REST's).
	 */
	public function rest_search( WP_REST_Request $request ) {
		$results = $this->search( $request->get_param( 'q' ) );
		if ( is_wp_error( $results ) ) {
			return new WP_Error(
				$results->get_error_code(),
				$results->get_error_message(),
				array( 'status' => 502 )
			);
		}
		return rest_ensure_response( $results );
	}

	/**
	 * REST: lat/lng → a single normalized place.
	 */
	public function rest_reverse( WP_REST_Request $request ) {
		$place = $this->reverse( (float) $request->get_param( 'lat' ), (float) $request->get_param( 'lng' ) );
		if ( is_wp_error( $place ) ) {
			return new WP_Error(
				$place->get_error_code(),
				$place->get_error_message(),
				array( 'status' => 502 )
			);
		}
		return rest_ensure_response( $place );
	}

	/**
	 * Legacy admin-ajax bridge, superseded by GET /wp-json/wpd/v1/nominatim/search
	 * (#130). Removed in the release after the one that adds this deprecation.
	 */
	public function ajax_search() {
		check_ajax_referer( 'wpd_nominatim_search' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'wp-dansal' ) ), 403 );
		}

		$q = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
		if ( strlen( $q ) < 3 ) {
			wp_send_json_error( array( 'message' => __( 'Search term too short.', 'wp-dansal' ) ) );
		}

		$results = $this->search( $q );
		if ( is_wp_error( $results ) ) {
			wp_send_json_error( array( 'message' => $results->get_error_message() ) );
		}

		wp_send_json_success( $results );
	}

	/**
	 * Legacy admin-ajax bridge, superseded by GET /wp-json/wpd/v1/nominatim/reverse
	 * (#130). Removed in the release after the one that adds this deprecation.
	 */
	public function ajax_reverse() {
		check_ajax_referer( 'wpd_nominatim_search' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'wp-dansal' ) ), 403 );
		}

		$lat = isset( $_GET['lat'] ) && is_numeric( $_GET['lat'] ) ? (float) $_GET['lat'] : null;
		$lng = isset( $_GET['lng'] ) && is_numeric( $_GET['lng'] ) ? (float) $_GET['lng'] : null;
		if ( null === $lat || null === $lng ) {
			wp_send_json_error( array( 'message' => __( 'Missing coordinates.', 'wp-dansal' ) ) );
		}

		$place = $this->reverse( $lat, $lng );
		if ( is_wp_error( $place ) ) {
			wp_send_json_error( array( 'message' => $place->get_error_message() ) );
		}

		wp_send_json_success( $place );
	}

	/**
	 * @return array|WP_Error List of normalized place results.
	 */
	public function search( $query ) {
		$url = add_query_arg(
			array(
				'q'              => rawurlencode( $query ),
				'format'         => 'jsonv2',
				'addressdetails' => 1,
				'limit'          => 8,
			),
			self::ENDPOINT
		);

		$response = $this->request( $url );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'wpd_nominatim_bad_response', __( 'Unexpected Nominatim response.', 'wp-dansal' ) );
		}

		return array_map( array( $this, 'normalize_place' ), $data );
	}

	/**
	 * @return array|WP_Error Single normalized place for the given coordinates.
	 */
	public function reverse( $lat, $lng ) {
		$url = add_query_arg(
			array(
				'lat'            => $lat,
				'lon'            => $lng,
				'format'         => 'jsonv2',
				'addressdetails' => 1,
			),
			self::REVERSE_ENDPOINT
		);

		$response = $this->request( $url );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$place = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $place ) || isset( $place['error'] ) ) {
			return new WP_Error( 'wpd_nominatim_bad_response', __( 'Unexpected Nominatim response.', 'wp-dansal' ) );
		}

		return $this->normalize_place( $place );
	}

	/**
	 * @return array|WP_Error The raw wp_remote_get() response, already
	 *                        checked for transport/HTTP errors.
	 */
	private function request( $url ) {
		$contact = wpd_plugin()->settings->get_nominatim_email();
		$url     = add_query_arg( array( 'email' => rawurlencode( $contact ) ), $url );

		$response = wp_remote_get(
            $url,
            array(
				'timeout' => WPD_Api_Client::timeout( '/nominatim' ),
				'headers' => array(
					'User-Agent' => 'wp-dansal-plugin/' . WPD_VERSION . ' (' . home_url() . '; ' . $contact . ')',
					'Accept'     => 'application/json',
				),
            )
        );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			/* translators: %d: HTTP status code returned by Nominatim. */
			return new WP_Error( 'wpd_nominatim_http_' . $code, sprintf( __( 'Nominatim returned HTTP %d', 'wp-dansal' ), $code ) );
		}

		return $response;
	}

	/**
	 * @param array $place Raw Nominatim place object (jsonv2 format), from
	 *                      either /search (one entry) or /reverse (the body).
	 * @return array Normalized shape shared by search() and reverse().
	 */
	private function normalize_place( array $place ) {
		$addr = isset( $place['address'] ) ? $place['address'] : array();
		return array(
			'display_name' => isset( $place['display_name'] ) ? $place['display_name'] : '',
			'name'         => isset( $place['name'] ) && $place['name'] ? $place['name'] : ( isset( $place['display_name'] ) ? strtok( $place['display_name'], ',' ) : '' ),
			'lat'          => isset( $place['lat'] ) ? (float) $place['lat'] : null,
			'lng'          => isset( $place['lon'] ) ? (float) $place['lon'] : null,
			'osm_id'       => isset( $place['osm_id'] ) ? (int) $place['osm_id'] : null,
			'osm_type'     => isset( $place['osm_type'] ) ? $place['osm_type'] : '',
			'address'      => isset( $addr['road'] ) ? trim( $addr['road'] . ' ' . ( $addr['house_number'] ?? '' ) ) : '',
			'town'         => $addr['city'] ?? ( $addr['town'] ?? ( $addr['village'] ?? ( $addr['municipality'] ?? '' ) ) ),
			'zipcode'      => $addr['postcode'] ?? '',
			'country'      => $addr['country'] ?? '',
			'country_code' => isset( $addr['country_code'] ) ? strtoupper( $addr['country_code'] ) : '',
		);
	}
}
