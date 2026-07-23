<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Closed vocabularies dansal validates on write. Slug lists are fetched from
 * dansal's public /api/v1/vocabulary endpoint and cached; on failure or before
 * the first fetch we fall back to a hardcoded list that mirrors what dansal
 * shipped when this plugin version was cut. Labels are always plugin-side
 * (via __()) so translators can localize; a slug dansal added but the plugin
 * hasn't shipped a label for renders as the raw slug.
 */
class WPD_Vocab {

	const TRANSIENT = 'wpd_vocab_' . WPD_VERSION;
	const FAIL_TTL  = 300;     // 5 minutes, so a broken dansal server doesn't hammer WP on every edit-screen load.
	const OK_TTL    = 3600;    // 1 hour.

	/**
	 * Slug => translated label for a vocabulary field. Merges the fetched
	 * slug list (or the hardcoded fallback) with the plugin's own label map;
	 * unknown slugs display as the raw slug.
	 *
	 * @param string $key food | drink | floor_condition | parking
	 * @return array<string,string>
	 */
	public static function options( $key ) {
		$labels = self::label_map();
		$slugs  = self::valid_slugs( $key );
		$out    = array();
		foreach ( $slugs as $slug ) {
			$out[ $slug ] = isset( $labels[ $key ][ $slug ] ) ? $labels[ $key ][ $slug ] : $slug;
		}
		return (array) apply_filters( "wpd_{$key}_options", $out );
	}

	/**
	 * Return $value if it's in the vocabulary, else '' (dansal-side
	 * "unset / inherit-from-venue" signal). Whitelist runs after filters so
	 * a filter can't sneak an off-vocab value past dansal's validator.
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

	/**
	 * Translated label for one vocabulary slug, or the raw slug if unknown.
	 * Use this in frontend templates instead of echoing the stored meta
	 * value, so users see "Parkett" instead of "parquet".
	 *
	 * @param string $key food | drink | floor_condition | parking
	 * @param string $slug
	 */
	public static function label( $key, $slug ) {
		$slug = (string) $slug;
		if ( '' === $slug ) {
			return '';
		}
		$options = self::options( $key );
		return isset( $options[ $slug ] ) ? $options[ $slug ] : $slug;
	}

	public static function flush() {
		delete_transient( self::TRANSIENT );
	}

	/**
	 * Fetched slug list for one key, else hardcoded fallback.
	 *
	 * @return string[]
	 */
	private static function valid_slugs( $key ) {
		$vocab = self::fetch();
		if ( isset( $vocab[ $key ] ) && is_array( $vocab[ $key ] ) && ! empty( $vocab[ $key ] ) ) {
			return $vocab[ $key ];
		}
		return array_keys( isset( self::label_map()[ $key ] ) ? self::label_map()[ $key ] : array() );
	}

	/**
	 * Fetch and cache the full vocabulary payload from dansal. Returns an
	 * associative array of $key => string[] slugs. On failure caches an
	 * empty array for FAIL_TTL so we don't hammer a broken server.
	 */
	private static function fetch() {
		$cached = get_transient( self::TRANSIENT );
		if ( false !== $cached ) {
			return is_array( $cached ) ? $cached : array();
		}

		// wpd_plugin() is set up by the main file; guard so uninstall / cli
		// contexts that call sanitize() don't fatal.
		if ( ! function_exists( 'wpd_plugin' ) ) {
			return array();
		}
		$api = wpd_plugin()->api;
		if ( ! $api ) {
			return array();
		}

		$result = $api->get_public( '/api/v1/vocabulary' );
		if ( is_wp_error( $result ) || ! is_array( $result ) ) {
			set_transient( self::TRANSIENT, array(), self::FAIL_TTL );
			return array();
		}

		$normalized = array();
		foreach ( $result as $vocab_key => $items ) {
			if ( ! is_array( $items ) ) {
				continue;
			}
			$slugs = array();
			foreach ( $items as $item ) {
				if ( is_string( $item ) ) {
					$slugs[] = $item;
				} elseif ( is_array( $item ) && ! empty( $item['slug'] ) ) {
					$slugs[] = (string) $item['slug'];
				}
			}
			if ( ! empty( $slugs ) ) {
				$normalized[ $vocab_key ] = $slugs;
			}
		}

		set_transient( self::TRANSIENT, $normalized, self::OK_TTL );
		return $normalized;
	}

	/**
	 * Slug => translated label. The set of slugs here is also the hardcoded
	 * fallback used when dansal is unreachable or hasn't been fetched yet.
	 */
	private static function label_map() {
		return array(
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
	}
}

add_action( 'wpd_flush_caches', array( 'WPD_Vocab', 'flush' ) );
