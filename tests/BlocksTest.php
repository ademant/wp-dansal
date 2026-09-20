<?php
/**
 * Blocks (#123): thin server-side-rendered wrappers around the shortcodes.
 *
 * The property worth pinning is that a block and its shortcode can't disagree:
 * the block's attributes are mapped onto the shortcode's and rendered by the
 * same WPD_Frontend method.
 */

class BlocksTest extends WP_UnitTestCase {

	public function tear_down(): void {
		delete_option( 'wpd_settings' );
		parent::tear_down();
	}

	private function slugs() {
		return array( 'events', 'locations', 'nearby', 'festivals', 'calendar-embed' );
	}

	public function test_every_block_is_registered_with_its_server_side_metadata() {
		foreach ( $this->slugs() as $slug ) {
			$type = WP_Block_Type_Registry::get_instance()->get_registered( 'wp-dansal/' . $slug );
			$this->assertNotNull( $type, "wp-dansal/$slug is registered" );
			$this->assertNotEmpty( $type->attributes, "$slug declares attributes" );
			$this->assertTrue( is_callable( $type->render_callback ) );
		}
	}

	public function test_every_block_json_attribute_reaches_a_shortcode_attribute() {
		foreach ( $this->slugs() as $slug ) {
			$json = json_decode( file_get_contents( WPD_PLUGIN_DIR . "blocks/$slug/block.json" ), true );
			$sc   = WPD_Blocks::shortcode_atts_for(
				$slug,
				array_map(
					static function ( $a ) {
						return 'boolean' === $a['type'] ? true : ( 'number' === $a['type'] ? 5 : 'x' );
					},
					$json['attributes']
				)
			);
			$this->assertCount( count( $json['attributes'] ), $sc, "$slug: every attribute in block.json maps to a shortcode attribute" );
		}
	}

	public function test_attribute_mapping_converts_types_and_drops_empty_values() {
		$atts = WPD_Blocks::shortcode_atts_for(
			'events',
			array(
				'view'      => 'calendar',
				'limit'     => 12,
				'tag'       => '',
				'showPast'  => true,
				'showTypes' => false,
				'radiusKm'  => '25',
				'unknown'   => 'ignored',
			)
		);

		$this->assertSame(
			array(
				'view'       => 'calendar',
				'limit'      => 12,
				'show_past'  => 1,
				'show_types' => 0,
				'radius_km'  => '25',
			),
			$atts
		);
	}

	public function test_a_zero_or_missing_limit_falls_back_to_the_shortcode_default() {
		$this->assertArrayNotHasKey( 'limit', WPD_Blocks::shortcode_atts_for( 'events', array( 'limit' => 0 ) ) );
		$this->assertArrayNotHasKey( 'limit', WPD_Blocks::shortcode_atts_for( 'events', array( 'limit' => 'abc' ) ) );
	}

	public function test_block_output_contains_exactly_what_the_shortcode_renders() {
		$block     = do_blocks( '<!-- wp:wp-dansal/events {"view":"mini"} /-->' );
		$shortcode = do_shortcode( '[dansal_events view="mini"]' );

		$this->assertNotSame( '', $shortcode );
		$this->assertStringContainsString( $shortcode, $block );
		$this->assertStringContainsString( 'wp-block-wp-dansal-events', $block );
	}

	public function test_locations_block_renders_the_locations_shortcode() {
		$block     = do_blocks( '<!-- wp:wp-dansal/locations /-->' );
		$shortcode = do_shortcode( '[dansal_locations]' );

		$this->assertStringContainsString( $shortcode, $block );
	}

	public function test_calendar_embed_block_builds_the_iframe_from_its_attributes() {
		update_option( 'wpd_settings', array( 'base_url' => 'https://dansal.example' ) );

		$block = do_blocks( '<!-- wp:wp-dansal/calendar-embed {"org":"3","height":"500"} /-->' );

		$this->assertStringContainsString( '<iframe', $block );
		$this->assertStringContainsString( 'org=3', $block );
	}

	public function test_widget_equivalents_render_the_same_markup_as_the_legacy_widgets() {
		// Upcoming Events widget == [dansal_events view="simple" limit show_types]
		// and Mini Calendar widget == [dansal_events view="mini"]; the editor's
		// block variations preset exactly those attributes.
		$widget = wpd_plugin()->frontend->shortcode_events(
			array(
				'view'       => 'simple',
				'limit'      => 5,
				'show_types' => 1,
			)
		);
		$block  = do_blocks( '<!-- wp:wp-dansal/events {"view":"simple","limit":5,"showTypes":true} /-->' );

		$this->assertStringContainsString( $widget, $block );
	}

	public function test_editor_script_and_style_are_registered_and_shipped() {
		$this->assertTrue( wp_script_is( 'wpd-blocks-editor', 'registered' ) );
		$this->assertTrue( wp_style_is( 'wpd-blocks-editor-style', 'registered' ) );
		$this->assertFileExists( WPD_PLUGIN_DIR . 'assets/js/blocks-editor.js' );
		$this->assertStringContainsString( ' blocks ', file_get_contents( WPD_PLUGIN_DIR . 'Makefile' ), 'blocks/ must be in the release zip' );
	}
}
