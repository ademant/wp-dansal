<?php
/**
 * Front-end script loading (#123): deferred on WordPress 6.3+, in the footer
 * everywhere.
 */

class AssetsTest extends WP_UnitTestCase {

	public function test_frontend_scripts_are_enqueued_deferred_in_the_footer() {
		// [dansal_locations] enqueues Leaflet and the map script.
		do_shortcode( '[dansal_locations]' );

		foreach ( array( 'wpd-leaflet', 'wpd-map' ) as $handle ) {
			$this->assertTrue( wp_script_is( $handle, 'enqueued' ), "$handle is enqueued" );
			$this->assertSame( 'defer', wp_scripts()->get_data( $handle, 'strategy' ), "$handle is deferred" );
			$this->assertSame( 1, wp_scripts()->get_data( $handle, 'group' ), "$handle prints in the footer" );
		}
	}

	public function test_script_args_helper_is_footer_plus_defer() {
		$this->assertSame(
			array(
				'strategy'  => 'defer',
				'in_footer' => true,
			),
			wpd_footer_script_args()
		);
	}

	public function test_deferred_output_carries_the_defer_attribute() {
		do_shortcode( '[dansal_locations]' );

		ob_start();
		wp_print_footer_scripts();
		$html = ob_get_clean();

		// Attribute order differs between WordPress versions, so find the tag
		// first and look for `defer` anywhere inside it.
		preg_match_all( '/<script\b[^>]*>/', $html, $tags );
		$map_tags = array_filter(
			$tags[0],
			static function ( $tag ) {
				return false !== strpos( $tag, 'frontend-map.js' );
			}
		);
		$this->assertCount( 1, $map_tags );
		$this->assertMatchesRegularExpression( '/\sdefer[\s=>]/', reset( $map_tags ) );
	}
}
