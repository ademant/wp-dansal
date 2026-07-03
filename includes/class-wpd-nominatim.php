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

	const ENDPOINT = 'https://nominatim.openstreetmap.org/search';

	public function __construct() {
		add_action( 'wp_ajax_wpd_nominatim_search', array( $this, 'ajax_search' ) );
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
	 * @return array|WP_Error List of normalized place results.
	 */
	public function search( $query ) {
		$contact = wpd_plugin()->settings->get_nominatim_email();

		$url = add_query_arg(
			array(
				'q'              => rawurlencode( $query ),
				'format'         => 'jsonv2',
				'addressdetails' => 1,
				'limit'          => 8,
				'email'          => rawurlencode( $contact ),
			),
			self::ENDPOINT
		);

		$response = wp_remote_get(
            $url,
            array(
				'timeout' => 15,
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

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'wpd_nominatim_bad_response', __( 'Unexpected Nominatim response.', 'wp-dansal' ) );
		}

		$out = array();
		foreach ( $data as $place ) {
			$addr  = isset( $place['address'] ) ? $place['address'] : array();
			$out[] = array(
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

		return $out;
	}
}
