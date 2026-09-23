<?php
/**
 * Per-user rate limit on POST /wpd/v1/entities (#133) — nothing previously
 * stopped a logged-in user from spam-creating musician/instructor records on
 * the org's shared dansal account.
 */

class EntityRateLimitTest extends WP_UnitTestCase {

	private $user_id;

	public function set_up(): void {
		parent::set_up();
		global $wp_rest_server;
		$wp_rest_server = null;

		update_option(
			'wpd_settings',
			array(
				'base_url' => 'https://dansal.example',
				'api_key'  => 'ak_test',
			)
		);
		set_transient( WPD_Api_Client::TOKEN_TRANSIENT, 'session-token', HOUR_IN_SECONDS );

		$this->user_id = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $this->user_id );

		add_filter(
			'pre_http_request',
			static function () {
				static $next_id = 1000;
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode(
						array(
							'id'       => $next_id++,
							'bandname' => 'A Band',
						)
					),
				);
			}
		);
	}

	public function tear_down(): void {
		delete_option( 'wpd_settings' );
		delete_transient( WPD_Api_Client::TOKEN_TRANSIENT );
		delete_transient( WPD_CPT_Event::ENTITY_CREATE_RATE_TRANSIENT . $this->user_id );
		remove_all_filters( 'pre_http_request' );
		remove_all_filters( 'wpd_entity_create_rate_limit' );
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	private function create_request() {
		$request = new WP_REST_Request( 'POST', '/wpd/v1/entities' );
		$request->set_body_params(
			array(
				'type' => 'musician',
				'name' => 'A Band',
			)
		);
		return rest_do_request( $request );
	}

	public function test_requests_within_the_limit_succeed() {
		add_filter( 'wpd_entity_create_rate_limit', fn() => 3 );

		for ( $i = 0; $i < 3; $i++ ) {
			$response = $this->create_request();
			$this->assertSame( 200, $response->get_status(), "request $i should succeed" );
		}
	}

	public function test_exceeding_the_limit_returns_429() {
		add_filter( 'wpd_entity_create_rate_limit', fn() => 3 );

		for ( $i = 0; $i < 3; $i++ ) {
			$this->create_request();
		}
		$response = $this->create_request();

		$this->assertSame( 429, $response->get_status() );
		$this->assertSame( 'wpd_entity_rate_limited', $response->get_data()['code'] );
	}

	public function test_the_limit_is_per_user_not_global() {
		add_filter( 'wpd_entity_create_rate_limit', fn() => 1 );

		$this->create_request();
		$blocked = $this->create_request();
		$this->assertSame( 429, $blocked->get_status() );

		$other_user = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $other_user );
		$response = $this->create_request();

		$this->assertSame( 200, $response->get_status(), 'a different user has their own quota' );
		delete_transient( WPD_CPT_Event::ENTITY_CREATE_RATE_TRANSIENT . $other_user );
	}

	public function test_rejected_requests_never_reach_dansal() {
		add_filter( 'wpd_entity_create_rate_limit', fn() => 1 );
		$this->create_request();

		$calls = 0;
		add_filter(
			'pre_http_request',
			function () use ( &$calls ) {
				++$calls;
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode(
                        array(
							'id' => 1,
							'bandname' => 'A Band',
                        )
                    ),
				);
			}
		);

		$this->create_request();

		$this->assertSame( 0, $calls, 'the rate-limited request short-circuits before any dansal call' );
	}
}
