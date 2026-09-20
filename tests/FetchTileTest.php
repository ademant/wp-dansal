<?php
/**
 * Seed suite for WPD_Api_Client::fetch_tile() (#118, #120).
 *
 * Pins down the auth-path priority a real tile request goes through: the
 * publisher API key (Authorization: Bearer) first when usable, dansal's
 * public tile token (?t=) as a fallback when it isn't, and a clean WP_Error
 * (never a crash) when neither is available — ajax_tile() is the caller
 * responsible for turning that last case into a same-origin raw-OSM fetch.
 */

class FetchTileTest extends WP_UnitTestCase {

	/** @var array Captured (url, args) of every wp_remote_get() the test intercepted. */
	private $requests = array();

	public function tearDown(): void {
		delete_option( 'wpd_settings' );
		delete_transient( WPD_Api_Client::TILE_TOKEN_TRANSIENT );
		remove_all_filters( 'pre_http_request' );
		$this->requests = array();
		parent::tearDown();
	}

	/**
	 * Intercepts every wp_remote_get()/wp_remote_request() so no test here
	 * makes a real network call. $responder maps a requested URL to a fake
	 * WP HTTP response array (or false to simulate a WP_Error/timeout).
	 */
	private function mock_http( callable $responder ) {
		$this->requests = array();
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( $responder ) {
				$this->requests[] = array(
					'url'  => $url,
					'args' => $args,
				);
				$response = $responder( $url, $args );
				if ( false === $response ) {
					return new WP_Error( 'wpd_test_http_error', 'simulated failure' );
				}
				return $response;
			},
			10,
			3
		);
	}

	private static function http_ok( $body ) {
		return array(
			'response' => array( 'code' => 200 ),
			'body'     => $body,
		);
	}

	public function test_no_base_url_returns_error_without_any_request() {
		update_option(
            'wpd_settings',
            array(
				'base_url' => '',
				'api_key' => '',
            )
        );
		$this->mock_http(
            function () {
                return self::http_ok( 'unused' );
            }
        );

		$result = wpd_plugin()->api->fetch_tile( 1, 2, 3 );

		$this->assertWPError( $result );
		$this->assertSame( 'wpd_no_connection', $result->get_error_code() );
		$this->assertCount( 0, $this->requests );
	}

	public function test_usable_key_authenticates_with_bearer_header_not_query_param() {
		update_option(
			'wpd_settings',
			array(
				'base_url'     => 'https://dansal.example',
				'api_key'      => 'ak_test',
				'api_key_dead' => false,
			)
		);
		$this->mock_http(
            function () {
                return self::http_ok( 'tile-bytes' );
            }
        );

		$result = wpd_plugin()->api->fetch_tile( 1, 2, 3 );

		$this->assertSame( 'tile-bytes', $result );
		$this->assertCount( 1, $this->requests );
		$this->assertSame( 'https://dansal.example/tiles/osm/1/2/3.png', $this->requests[0]['url'] );
		$this->assertSame( 'Bearer ak_test', $this->requests[0]['args']['headers']['Authorization'] );
	}

	public function test_401_from_key_path_marks_key_dead_and_falls_back_to_public_token() {
		update_option(
			'wpd_settings',
			array(
				'base_url'     => 'https://dansal.example',
				'api_key'      => 'ak_test',
				'api_key_dead' => false,
			)
		);
		$this->mock_http(
			function ( $url, $args ) {
				if ( false !== strpos( $url, '/tiles/token' ) ) {
					return self::http_ok( wp_json_encode( array( 'token' => 'pub_tok_123' ) ) );
				}
				if ( isset( $args['headers']['Authorization'] ) ) {
					return array(
						'response' => array( 'code' => 401 ),
						'body'     => '',
					);
				}
				return self::http_ok( 'tile-bytes' );
			}
		);

		$result = wpd_plugin()->api->fetch_tile( 1, 2, 3 );

		// The rejected key is flagged, but the same request still gets served
		// through the public token instead of failing over to raw OSM (#122).
		$this->assertTrue( wpd_plugin()->settings->is_api_key_dead() );
		$this->assertSame( 'tile-bytes', $result );
		$this->assertSame( 'https://dansal.example/tiles/osm/1/2/3.png?t=pub_tok_123', end( $this->requests )['url'] );
	}

	public function test_non_401_error_from_key_path_does_not_mark_key_dead_or_fall_back() {
		update_option(
			'wpd_settings',
			array(
				'base_url'     => 'https://dansal.example',
				'api_key'      => 'ak_test',
				'api_key_dead' => false,
			)
		);
		$this->mock_http(
			function () {
				return array(
					'response' => array( 'code' => 502 ),
					'body'     => '',
				);
			}
		);

		$result = wpd_plugin()->api->fetch_tile( 1, 2, 3 );

		$this->assertWPError( $result );
		$this->assertSame( 'wpd_tile_http_502', $result->get_error_code() );
		$this->assertFalse( wpd_plugin()->settings->is_api_key_dead() );
		$this->assertCount( 1, $this->requests );
	}

	public function test_tiles_are_requested_from_web_url_when_it_differs_from_base_url() {
		update_option(
			'wpd_settings',
			array(
				'base_url' => 'https://api.dansal.example',
				'web_url'  => 'https://www.dansal.example',
				'api_key'  => '',
			)
		);
		$this->mock_http(
			function ( $url ) {
				if ( false !== strpos( $url, '/tiles/token' ) ) {
					return self::http_ok( wp_json_encode( array( 'token' => 'pub_tok_123' ) ) );
				}
				return self::http_ok( 'tile-bytes' );
			}
		);

		wpd_plugin()->api->fetch_tile( 1, 2, 3 );

		$this->assertSame( 'https://www.dansal.example/tiles/token', $this->requests[0]['url'] );
		$this->assertSame( 'https://www.dansal.example/tiles/osm/1/2/3.png?t=pub_tok_123', $this->requests[1]['url'] );
	}

	public function test_web_url_only_is_enough_to_fetch_tiles() {
		update_option(
			'wpd_settings',
			array(
				'base_url' => '',
				'web_url'  => 'https://www.dansal.example',
				'api_key'  => '',
			)
		);
		$this->mock_http(
			function ( $url ) {
				if ( false !== strpos( $url, '/tiles/token' ) ) {
					return self::http_ok( wp_json_encode( array( 'token' => 'pub_tok_123' ) ) );
				}
				return self::http_ok( 'tile-bytes' );
			}
		);

		$this->assertSame( 'tile-bytes', wpd_plugin()->api->fetch_tile( 1, 2, 3 ) );
	}

	public function test_rotated_public_token_is_refetched_and_retried_once_on_401() {
		update_option(
			'wpd_settings',
			array(
				'base_url' => 'https://dansal.example',
				'api_key'  => '',
			)
		);
		set_transient( WPD_Api_Client::TILE_TOKEN_TRANSIENT, 'old_token', DAY_IN_SECONDS );
		$this->mock_http(
			function ( $url ) {
				if ( false !== strpos( $url, '/tiles/token' ) ) {
					return self::http_ok( wp_json_encode( array( 'token' => 'new_token' ) ) );
				}
				if ( false !== strpos( $url, 't=old_token' ) ) {
					return array(
						'response' => array( 'code' => 401 ),
						'body'     => '',
					);
				}
				return self::http_ok( 'tile-bytes' );
			}
		);

		$result = wpd_plugin()->api->fetch_tile( 1, 2, 3 );

		$this->assertSame( 'tile-bytes', $result );
		$this->assertSame( 'new_token', get_transient( WPD_Api_Client::TILE_TOKEN_TRANSIENT ) );
		$this->assertCount( 3, $this->requests ); // stale-token tile, token refetch, retry.
	}

	public function test_still_401_after_token_refresh_gives_up_instead_of_looping() {
		update_option(
			'wpd_settings',
			array(
				'base_url' => 'https://dansal.example',
				'api_key'  => '',
			)
		);
		set_transient( WPD_Api_Client::TILE_TOKEN_TRANSIENT, 'old_token', DAY_IN_SECONDS );
		$this->mock_http(
			function ( $url ) {
				if ( false !== strpos( $url, '/tiles/token' ) ) {
					return self::http_ok( wp_json_encode( array( 'token' => 'new_token' ) ) );
				}
				return array(
					'response' => array( 'code' => 401 ),
					'body'     => '',
				);
			}
		);

		$result = wpd_plugin()->api->fetch_tile( 1, 2, 3 );

		$this->assertWPError( $result );
		$this->assertSame( 'wpd_tile_http_401', $result->get_error_code() );
		$this->assertCount( 3, $this->requests );
	}

	public function test_no_key_falls_back_to_public_token_as_query_param() {
		update_option(
            'wpd_settings',
            array(
				'base_url' => 'https://dansal.example',
				'api_key' => '',
            )
        );
		$this->mock_http(
			function ( $url ) {
				if ( false !== strpos( $url, '/tiles/token' ) ) {
					return self::http_ok( wp_json_encode( array( 'token' => 'pub_tok_123' ) ) );
				}
				return self::http_ok( 'tile-bytes' );
			}
		);

		$result = wpd_plugin()->api->fetch_tile( 1, 2, 3 );

		$this->assertSame( 'tile-bytes', $result );
		$this->assertCount( 2, $this->requests );
		$this->assertSame( 'https://dansal.example/tiles/token', $this->requests[0]['url'] );
		$this->assertSame( 'https://dansal.example/tiles/osm/1/2/3.png?t=pub_tok_123', $this->requests[1]['url'] );
		$this->assertArrayNotHasKey( 'Authorization', $this->requests[1]['args']['headers'] );
	}

	public function test_public_token_fetch_is_cached_across_calls() {
		update_option(
            'wpd_settings',
            array(
				'base_url' => 'https://dansal.example',
				'api_key' => '',
            )
        );
		$this->mock_http(
			function ( $url ) {
				if ( false !== strpos( $url, '/tiles/token' ) ) {
					return self::http_ok( wp_json_encode( array( 'token' => 'pub_tok_123' ) ) );
				}
				return self::http_ok( 'tile-bytes' );
			}
		);

		wpd_plugin()->api->fetch_tile( 1, 2, 3 );
		wpd_plugin()->api->fetch_tile( 4, 5, 6 );

		// Two tile fetches, but the token lookup itself only happens once.
		$token_requests = array_filter(
			$this->requests,
			function ( $r ) {
				return false !== strpos( $r['url'], '/tiles/token' ); }
		);
		$this->assertCount( 1, $token_requests );
	}

	public function test_no_key_and_no_public_token_returns_error_without_crashing() {
		update_option(
            'wpd_settings',
            array(
				'base_url' => 'https://dansal.example',
				'api_key' => '',
            )
        );
		$this->mock_http(
			function ( $url ) {
				if ( false !== strpos( $url, '/tiles/token' ) ) {
					// Older dansal instance without #1287's endpoint yet.
					return array(
						'response' => array( 'code' => 404 ),
						'body' => 'not found',
					);
				}
				return self::http_ok( 'tile-bytes' );
			}
		);

		$result = wpd_plugin()->api->fetch_tile( 1, 2, 3 );

		$this->assertWPError( $result );
		$this->assertSame( 'wpd_no_tile_auth', $result->get_error_code() );
		// Never even attempts the tile itself once there's no way to auth it.
		$this->assertCount( 1, $this->requests );
	}
}
