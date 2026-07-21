<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Search-only wrapper around OpenStreetMap's Nominatim, used to turn a
 * free-text venue search into coordinates + osm_id/osm_type when creating a
 * dansal location. Public JS never talks to Nominatim directly; requests go
 * through this AJAX proxy so we can set the User-Agent Nominatim's usage
 * policy requires (https://operations.osmfoundation.org/policies/nominatim/)
 * and keep API traffic server-side.
 */
class WPD_Nominatim {

	const ENDPOINT         = 'https://nominatim.openstreetmap.org/search';
	const REVERSE_ENDPOINT = 'https://nominatim.openstreetmap.org/reverse';

	public function __construct() {
		add_action( 'wp_ajax_wpd_nominatim_search', array( $this, 'ajax_search' ) );
		add_action( 'wp_ajax_wpd_nominatim_reverse', array( $this, 'ajax_reverse' ) );
	}

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
	 * AJAX: lat/lng → a single normalized place, same shape as one entry from
	 * search(). Shares the 'wpd_nominatim_search' nonce action — it's the same
	 * edit_posts-gated, read-only Nominatim lookup, just a different endpoint.
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
