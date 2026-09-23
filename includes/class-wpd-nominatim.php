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
 * Transport is REST — `GET /wp-json/wpd/v1/nominatim/search` and
 * `/reverse`, registered on `rest_api_init`.
 */
class WPD_Nominatim {

	const ENDPOINT         = 'https://nominatim.openstreetmap.org/search';
	const REVERSE_ENDPOINT = 'https://nominatim.openstreetmap.org/reverse';

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
	}

	/**
	 * Both routes are `edit_posts`-gated via `permission_callback`; callers
	 * must include the standard `X-WP-Nonce` header (wp.apiFetch does this
	 * automatically for logged-in admin requests). The response shape is
	 * the raw payload.
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
