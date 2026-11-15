<?php
/**
 * #154: a malformed wpd_tile_url_template must not reach the browser. The
 * tile config falls back to the same-origin tile route unless the template is
 * an http(s) URL carrying all three Leaflet placeholders.
 */

class TileTemplateValidationTest extends WP_UnitTestCase {

	private function url_template_with_filter( $value ) {
		add_filter(
			'wpd_tile_url_template',
			static function () use ( $value ) {
				return $value;
			}
		);
		$config = wpd_plugin()->frontend->tile_config();
		remove_all_filters( 'wpd_tile_url_template' );
		return $config['urlTemplate'];
	}

	public function test_a_valid_https_template_is_used_as_configured() {
		$this->assertSame(
			'https://tiles.example/{z}/{x}/{y}.png',
			$this->url_template_with_filter( 'https://tiles.example/{z}/{x}/{y}.png' )
		);
	}

	public function test_a_javascript_url_falls_back_to_the_same_origin_route() {
		$this->assertSame(
			rest_url( 'wpd/v1/tiles/{z}/{x}/{y}' ),
			$this->url_template_with_filter( 'javascript:alert(1)//{z}{x}{y}' )
		);
	}

	public function test_a_template_missing_a_placeholder_falls_back() {
		$this->assertSame(
			rest_url( 'wpd/v1/tiles/{z}/{x}/{y}' ),
			$this->url_template_with_filter( 'https://tiles.example/{z}/{x}.png' )
		);
	}

	public function test_an_empty_template_falls_back() {
		$this->assertSame(
			rest_url( 'wpd/v1/tiles/{z}/{x}/{y}' ),
			$this->url_template_with_filter( '' )
		);
	}
}
