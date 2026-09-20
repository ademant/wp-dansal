<?php
/**
 * The plugin's own front-end endpoints on the REST API (#123): mini-calendar
 * month arrows, [dansal_nearby] refresh and map tiles. They replace
 * admin-ajax.php calls whose nonces expired inside full-page-cached HTML.
 */

class RestEndpointsTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		// Fresh REST server so routes register against this test's state.
		global $wp_rest_server;
		$wp_rest_server = null;
		update_option(
			'wpd_settings',
			array(
				'base_url' => 'https://dansal.example',
				'api_key'  => 'ak_test',
			)
		);
	}

	public function tear_down(): void {
		delete_option( 'wpd_settings' );
		remove_all_filters( 'pre_http_request' );
		global $wp_rest_server;
		$wp_rest_server = null;

		// The tile disk cache lives in uploads/, which the test DB rollback
		// doesn't touch — clear it so one test's tile can't answer another's.
		$cache = trailingslashit( wp_upload_dir()['basedir'] ) . 'wpd-tiles';
		if ( is_dir( $cache ) ) {
			$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $cache, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
			foreach ( $files as $file ) {
				$file->isDir() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
			rmdir( $cache ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		}
		parent::tear_down();
	}

	public function test_routes_are_registered_and_public() {
		$routes = rest_get_server()->get_routes( 'wpd/v1' );

		$this->assertArrayHasKey( '/wpd/v1/mini-calendar', $routes );
		$this->assertArrayHasKey( '/wpd/v1/nearby', $routes );
		$this->assertArrayHasKey( '/wpd/v1/tiles/(?P<z>\d+)/(?P<x>\d+)/(?P<y>\d+)', $routes );
	}

	public function test_mini_calendar_returns_rendered_html_without_a_nonce() {
		$request = new WP_REST_Request( 'GET', '/wpd/v1/mini-calendar' );
		$request->set_query_params(
			array(
				'month' => 3,
				'year'  => 2027,
			)
		);

		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'html', $data );
		$this->assertStringContainsString( 'wpd-mini-calendar', $data['html'] );
	}

	public function test_mini_calendar_rejects_an_out_of_range_month() {
		$request = new WP_REST_Request( 'GET', '/wpd/v1/mini-calendar' );
		$request->set_query_params(
			array(
				'month' => 13,
				'year'  => 2027,
			)
		);

		$this->assertSame( 400, rest_do_request( $request )->get_status() );
	}

	public function test_nearby_needs_coordinates() {
		$request = new WP_REST_Request( 'POST', '/wpd/v1/nearby' );
		$request->set_body_params( array( 'radius_km' => 10 ) );

		$this->assertSame( 400, rest_do_request( $request )->get_status() );
	}

	public function test_nearby_clamps_hostile_values_like_the_ajax_twin() {
		$frontend = wpd_plugin()->frontend;
		$ref      = new ReflectionMethod( $frontend, 'nearby_atts' );
		$ref->setAccessible( true );

		$atts = $ref->invoke(
			$frontend,
			array(
				'lat'       => '50.9',
				'lon'       => '6.9',
				'radius_km' => '99999',
				'limit'     => '99999',
				'view'      => '<script>',
				'tag'       => 'Bal Folk!',
			)
		);

		$this->assertSame( 500.0, $atts['radius_km'] );
		$this->assertSame( 100, $atts['limit'] );
		$this->assertSame( 'map+list', $atts['view'] );
		$this->assertSame( 'balfolk', $atts['tag'] );
		$this->assertNull(
            $ref->invoke(
                $frontend,
                array(
					'lat' => 'x',
					'lon' => '1',
                )
            )
        );
	}

	public function test_tile_route_serves_the_image_bytes_with_image_headers() {
		add_filter(
			'pre_http_request',
			static function () {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => "\x89PNG-fake-tile",
				);
			}
		);
		$request  = new WP_REST_Request( 'GET', '/wpd/v1/tiles/3/4/5' );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'image/png', $response->get_headers()['Content-Type'] );
		$this->assertStringContainsString( 'max-age', $response->get_headers()['Cache-Control'] );

		ob_start();
		$served = wpd_plugin()->frontend->serve_tile_raw( false, $response, $request );
		$out    = ob_get_clean();
		$this->assertTrue( $served );
		$this->assertSame( "\x89PNG-fake-tile", $out );
	}

	public function test_tile_route_rejects_coordinates_outside_the_tile_grid() {
		$this->assertSame( 400, rest_do_request( new WP_REST_Request( 'GET', '/wpd/v1/tiles/2/4/0' ) )->get_status(), 'x >= 2^z' );
		$this->assertSame( 400, rest_do_request( new WP_REST_Request( 'GET', '/wpd/v1/tiles/23/0/0' ) )->get_status(), 'z > 22' );
	}

	public function test_tile_route_reports_502_when_no_source_works() {
		add_filter(
			'pre_http_request',
			static function () {
				return new WP_Error( 'down', 'simulated' );
			}
		);

		$this->assertSame( 502, rest_do_request( new WP_REST_Request( 'GET', '/wpd/v1/tiles/3/4/5' ) )->get_status() );
	}

	public function test_error_responses_are_left_to_the_rest_server_to_render() {
		$request = new WP_REST_Request( 'GET', '/wpd/v1/tiles/2/4/0' );
		$error   = rest_do_request( $request );

		$this->assertFalse( wpd_plugin()->frontend->serve_tile_raw( false, $error, $request ) );
	}

	public function test_other_routes_are_never_treated_as_tiles() {
		$request  = new WP_REST_Request( 'GET', '/wp/v2/posts' );
		$response = new WP_REST_Response( 'not-a-tile', 200 );

		$this->assertFalse( wpd_plugin()->frontend->serve_tile_raw( false, $response, $request ) );
	}

	public function test_legacy_admin_ajax_endpoints_are_still_registered() {
		$this->assertNotFalse( has_action( 'wp_ajax_nopriv_wpd_tile' ) );
		$this->assertNotFalse( has_action( 'wp_ajax_nopriv_wpd_mini_calendar' ) );
		$this->assertNotFalse( has_action( 'wp_ajax_nopriv_wpd_nearby' ) );
	}
}
