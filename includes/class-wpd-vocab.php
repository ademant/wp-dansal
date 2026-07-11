<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Closed vocabularies dansal validates on write. Values here mirror
 * dansal's own admin UI (cmd/dansal_web/templates/*.html) and its
 * server-side enum validation on POST/PUT/PATCH.
 *
 * Hardcoded for now; #42 swaps the source underneath for a cached
 * fetch of GET /api/v1/vocabulary without changing the callers.
 */
class WPD_Vocab {

	/**
	 * Slug => translation key. Slug is what dansal accepts on the wire; the
	 * label is what the WP edit screen renders as the option text.
	 *
	 * @param string $key food | drink | floor_condition | parking
	 * @return array<string,string>
	 */
	public static function options( $key ) {
		$defaults = array(
			'food'            => array(
				'sold'    => __( 'Sold', 'wp-dansal' ),
				'potluck' => __( 'Potluck', 'wp-dansal' ),
				'none'    => __( 'None', 'wp-dansal' ),
			),
			'drink'           => array(
				'alcohol' => __( 'Alcohol', 'wp-dansal' ),
				'soft'    => __( 'Soft drinks', 'wp-dansal' ),
				'none'    => __( 'None', 'wp-dansal' ),
			),
			'floor_condition' => array(
				'parquet'  => __( 'Parquet', 'wp-dansal' ),
				'stone'    => __( 'Stone', 'wp-dansal' ),
				'tiles'    => __( 'Tiles', 'wp-dansal' ),
				'grass'    => __( 'Grass', 'wp-dansal' ),
				'sand'     => __( 'Sand / gravel', 'wp-dansal' ),
				'pavement' => __( 'Pavement', 'wp-dansal' ),
			),
			'parking'         => array(
				'none' => __( 'No parking', 'wp-dansal' ),
				'free' => __( 'Free parking', 'wp-dansal' ),
				'paid' => __( 'Paid parking', 'wp-dansal' ),
			),
		);
		$out = isset( $defaults[ $key ] ) ? $defaults[ $key ] : array();
		return (array) apply_filters( "wpd_{$key}_options", $out );
	}

	/**
	 * Return $value if it's in the vocabulary, else '' (empty string is the
	 * dansal-side "unset / inherit-from-venue" signal for all four fields).
	 * Whitelist runs after filters, so a filter can't sneak an off-vocab
	 * value past dansal's server-side validator.
	 *
	 * @param string $key food | drink | floor_condition | parking
	 * @param mixed  $value Raw value from $_POST.
	 * @return string
	 */
	public static function sanitize( $key, $value ) {
		$value = is_scalar( $value ) ? (string) $value : '';
		if ( '' === $value ) {
			return '';
		}
		$options = self::options( $key );
		return array_key_exists( $value, $options ) ? $value : '';
	}
}
