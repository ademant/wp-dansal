<?php
/**
 * Seed suite for WPD_Frontend::tile_config() (#118).
 *
 * The API key must never end up in a URL the browser can see, so these
 * pin down the routing: a usable dansal connection points the map at our
 * own ajax_tile() proxy instead of embedding the key, and anything short
 * of "usable" (no connection, or a dead key) falls back to public OSM
 * tiles rather than silently forwarding a query param dansal would reject.
 */

class TileConfigTest extends WP_UnitTestCase {

	public function tearDown(): void {
		delete_option( 'wpd_settings' );
		parent::tearDown();
	}

	public function test_falls_back_to_osm_when_no_connection_configured() {
		update_option( 'wpd_settings', array( 'base_url' => '', 'api_key' => '' ) );

		$tiles = wpd_plugin()->frontend->tile_config();

		$this->assertSame( 'https://tile.openstreetmap.org/{z}/{x}/{y}.png', $tiles['urlTemplate'] );
	}

	public function test_falls_back_to_osm_when_key_is_dead() {
		update_option(
			'wpd_settings',
			array(
				'base_url'     => 'https://dansal.example',
				'api_key'      => 'ak_test',
				'api_key_dead' => true,
			)
		);

		$tiles = wpd_plugin()->frontend->tile_config();

		$this->assertSame( 'https://tile.openstreetmap.org/{z}/{x}/{y}.png', $tiles['urlTemplate'] );
	}

	public function test_usable_connection_points_at_local_proxy_not_dansal() {
		update_option(
			'wpd_settings',
			array(
				'base_url'     => 'https://dansal.example',
				'api_key'      => 'ak_test',
				'api_key_dead' => false,
			)
		);

		$tiles = wpd_plugin()->frontend->tile_config();

		$this->assertStringContainsString( 'admin-ajax.php', $tiles['urlTemplate'] );
		$this->assertStringContainsString( 'action=wpd_tile', $tiles['urlTemplate'] );
		$this->assertStringContainsString( 'z={z}&x={x}&y={y}', $tiles['urlTemplate'] );
		// The whole point of #118: the key must not be sitting in the URL
		// the browser renders into public page HTML.
		$this->assertStringNotContainsString( 'ak_test', $tiles['urlTemplate'] );
		$this->assertStringNotContainsString( 'dansal.example', $tiles['urlTemplate'] );
	}

	public function test_configured_tile_url_overrides_the_dansal_proxy() {
		update_option(
			'wpd_settings',
			array(
				'base_url'          => 'https://dansal.example',
				'api_key'           => 'ak_test',
				'api_key_dead'      => false,
				'tile_url_template' => 'https://tiles.example/{z}/{x}/{y}.png',
			)
		);

		$tiles = wpd_plugin()->frontend->tile_config();

		$this->assertSame( 'https://tiles.example/{z}/{x}/{y}.png', $tiles['urlTemplate'] );
	}
}
