<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Block-editor counterparts of the plugin's shortcodes (#123).
 *
 * Each block is a thin, server-side-rendered wrapper: its attributes are mapped
 * onto the matching shortcode's attributes and rendered by the very same
 * WPD_Frontend method, so a block and its shortcode can never disagree about
 * what they show. The shortcodes stay fully supported — classic themes, the
 * Shortcode block and existing content keep working untouched.
 *
 * The editor UI is a hand-written script (assets/js/blocks-editor.js) that only
 * uses the `wp.*` globals WordPress ships, so the plugin still needs no build
 * step.
 */
class WPD_Blocks {

	/**
	 * Block slug => [ shortcode method, block attribute => [ shortcode attribute, kind ] ].
	 * Kind is 'string', 'int' or 'bool'; an empty string is left out so the
	 * shortcode's own default applies.
	 */
	private static function definitions() {
		return array(
			'events'         => array(
				'method' => 'shortcode_events',
				'map'    => array(
					'view'          => array( 'view', 'string' ),
					'limit'         => array( 'limit', 'int' ),
					'tag'           => array( 'tag', 'string' ),
					'type'          => array( 'type', 'string' ),
					'location'      => array( 'location', 'string' ),
					'showPast'      => array( 'show_past', 'bool' ),
					'showTypes'     => array( 'show_types', 'bool' ),
					'org'           => array( 'org', 'string' ),
					'country'       => array( 'country', 'string' ),
					'excludeOwnOrg' => array( 'exclude_own_org', 'bool' ),
					'bbox'          => array( 'bbox', 'string' ),
					'lat'           => array( 'lat', 'string' ),
					'lon'           => array( 'lon', 'string' ),
					'radiusKm'      => array( 'radius_km', 'string' ),
				),
			),
			'locations'      => array(
				'method' => 'shortcode_locations',
				'map'    => array(
					'tag'      => array( 'tag', 'string' ),
					'country'  => array( 'country', 'string' ),
					'location' => array( 'location', 'string' ),
				),
			),
			'nearby'         => array(
				'method' => 'shortcode_nearby',
				'map'    => array(
					'radiusKm'      => array( 'radius_km', 'int' ),
					'view'          => array( 'view', 'string' ),
					'limit'         => array( 'limit', 'int' ),
					'tag'           => array( 'tag', 'string' ),
					'type'          => array( 'type', 'string' ),
					'excludeOwnOrg' => array( 'exclude_own_org', 'bool' ),
					'showCancelled' => array( 'show_cancelled', 'bool' ),
					'lat'           => array( 'lat', 'string' ),
					'lon'           => array( 'lon', 'string' ),
				),
			),
			'festivals'      => array(
				'method' => 'shortcode_festivals',
				'map'    => array(
					'view'     => array( 'view', 'string' ),
					'limit'    => array( 'limit', 'int' ),
					'showPast' => array( 'show_past', 'bool' ),
					'tag'      => array( 'tag', 'string' ),
					'org'      => array( 'org', 'string' ),
					'country'  => array( 'country', 'string' ),
					'bbox'     => array( 'bbox', 'string' ),
					'lat'      => array( 'lat', 'string' ),
					'lon'      => array( 'lon', 'string' ),
					'radiusKm' => array( 'radius_km', 'string' ),
				),
			),
			'calendar-embed' => array(
				'method' => 'shortcode_calendar_embed',
				'map'    => array(
					'org'      => array( 'org', 'string' ),
					'location' => array( 'location', 'string' ),
					'from'     => array( 'from', 'string' ),
					'to'       => array( 'to', 'string' ),
					'tag'      => array( 'tag', 'string' ),
					'lang'     => array( 'lang', 'string' ),
					'width'    => array( 'width', 'string' ),
					'height'   => array( 'height', 'string' ),
				),
			),
		);
	}

	/** @var WPD_Frontend */
	private $frontend;

	public function __construct( WPD_Frontend $frontend ) {
		$this->frontend = $frontend;
		add_action( 'init', array( $this, 'register' ) );
	}

	public function register() {
		wp_register_script(
			'wpd-blocks-editor',
			WPD_PLUGIN_URL . 'assets/js/blocks-editor.js',
			array( 'wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-i18n', 'wp-server-side-render' ),
			wpd_asset_ver( 'assets/js/blocks-editor.js' ),
			true
		);
		wp_set_script_translations( 'wpd-blocks-editor', 'wp-dansal', WPD_PLUGIN_DIR . 'languages' );
		// The same stylesheet the shortcodes use, so the editor preview looks like the site.
		wp_register_style( 'wpd-blocks-editor-style', WPD_PLUGIN_URL . 'assets/css/frontend.css', array(), wpd_asset_ver( 'assets/css/frontend.css' ) );

		foreach ( array_keys( self::definitions() ) as $slug ) {
			register_block_type(
				WPD_PLUGIN_DIR . 'blocks/' . $slug,
				array(
					'render_callback' => function ( $attributes ) use ( $slug ) {
						return $this->render( $slug, is_array( $attributes ) ? $attributes : array() );
					},
				)
			);
		}
	}

	/**
	 * Block attributes -> shortcode attributes. Booleans become 1/0 and empty
	 * strings are dropped so the shortcode's own defaults apply; the values
	 * themselves are validated by the shortcode handler exactly as they are
	 * for hand-written shortcodes.
	 *
	 * @param string $slug       Block slug (a key of definitions()).
	 * @param array  $attributes Block attributes.
	 * @return array Shortcode attributes.
	 */
	public static function shortcode_atts_for( $slug, array $attributes ) {
		$defs = self::definitions();
		$atts = array();
		if ( ! isset( $defs[ $slug ] ) ) {
			return $atts;
		}
		foreach ( $defs[ $slug ]['map'] as $block_attr => $target ) {
			if ( ! isset( $attributes[ $block_attr ] ) ) {
				continue;
			}
			list( $name, $kind ) = $target;
			$value               = $attributes[ $block_attr ];
			if ( 'bool' === $kind ) {
				$atts[ $name ] = ! empty( $value ) && 'false' !== $value ? 1 : 0;
			} elseif ( 'int' === $kind ) {
				if ( is_numeric( $value ) && (int) $value > 0 ) {
					$atts[ $name ] = (int) $value;
				}
			} elseif ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
				$atts[ $name ] = (string) $value;
			}
		}
		return $atts;
	}

	public function render( $slug, array $attributes ) {
		$defs = self::definitions();
		if ( ! isset( $defs[ $slug ] ) ) {
			return '';
		}
		$html = call_user_func( array( $this->frontend, $defs[ $slug ]['method'] ), self::shortcode_atts_for( $slug, $attributes ) );

		return sprintf( '<div %1$s>%2$s</div>', get_block_wrapper_attributes(), $html );
	}
}
