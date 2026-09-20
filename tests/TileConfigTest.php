<?php
/**
 * Seed suite for WPD_Frontend::tile_config() (#118, #120).
 *
 * The API key must never end up in a URL the browser can see, so tile_config()
 * always points the map at our own ajax_tile() proxy instead — regardless of
 * connection/key state (#120: ajax_tile()/WPD_Api_Client::fetch_tile() handle
 * every case server-side already — API key, then dansal's public tile token,
 * then a same-origin raw-OSM fetch as the last resort — so tile_config()
 * itself no longer needs to pre-decide whether the connection is "usable").
 */

class TileConfigTest extends WP_UnitTestCase {

	public function tearDown(): void {
		delete_option( 'wpd_settings' );
		parent::tearDown();
	}

	public function test_no_connection_still_points_at_local_proxy() {
		update_option(
            'wpd_settings',
            array(
				'base_url' => '',
				'api_key' => '',
            )
        );

		$tiles = wpd_plugin()->frontend->tile_config();

		// ajax_tile() itself falls back to a same-origin raw OSM fetch when
		// there's no dansal connection at all — but the browser-facing
		// urlTemplate stays same-origin either way, never a third-party host.
		$this->assertStringContainsString( 'admin-ajax.php', $tiles['urlTemplate'] );
		$this->assertStringContainsString( 'action=wpd_tile', $tiles['urlTemplate'] );
	}

	public function test_dead_key_still_points_at_local_proxy() {
		update_option(
			'wpd_settings',
			array(
				'base_url'     => 'https://dansal.example',
				'api_key'      => 'ak_test',
				'api_key_dead' => true,
			)
		);

		$tiles = wpd_plugin()->frontend->tile_config();

		$this->assertStringContainsString( 'admin-ajax.php', $tiles['urlTemplate'] );
		$this->assertStringContainsString( 'action=wpd_tile', $tiles['urlTemplate'] );
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
