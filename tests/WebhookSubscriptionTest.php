<?php
/**
 * Outbound webhook subscription management (#141): the admin-triggered
 * Register/Test/Unregister/Status REST routes in WPD_Settings, and capturing
 * `publisher_user_id` from connect-link redemption (the only place dansal
 * ever hands it to us).
 */

class WebhookSubscriptionTest extends WP_UnitTestCase {

	private $user_id;

	public function set_up(): void {
		parent::set_up();
		global $wp_rest_server;
		$wp_rest_server = null;

		$this->user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->user_id );

		update_option(
			'wpd_settings',
			array(
				'base_url'                 => 'https://dansal.example',
				'api_key'                  => 'ak_test',
				'publisher_user_id'        => 7,
				'signing_secret_encrypted' => WPD_Secret::encrypt( 'sekret' ),
				'signing_secret'           => '***',
			)
		);
		set_transient( WPD_Api_Client::TOKEN_TRANSIENT, 'session-token', HOUR_IN_SECONDS );
	}

	public function tear_down(): void {
		delete_option( 'wpd_settings' );
		delete_transient( WPD_Api_Client::TOKEN_TRANSIENT );
		remove_all_filters( 'pre_http_request' );
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	private function mock_http( callable $responder ) {
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( $responder ) {
				return $responder( $url, $args );
			},
			10,
			3
		);
	}

	private function request( $method, $path, array $params = array() ) {
		$request = new WP_REST_Request( $method, $path );
		foreach ( $params as $k => $v ) {
			$request->set_param( $k, $v );
		}
		return rest_do_request( $request );
	}

	// ---- routes are gated ---------------------------------------------------

	public function test_routes_require_manage_options() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->assertSame( 403, $this->request( 'POST', '/wpd/v1/webhook-subscription' )->get_status() );
		$this->assertSame( 403, $this->request( 'GET', '/wpd/v1/webhook-subscription' )->get_status() );
		$this->assertSame( 403, $this->request( 'DELETE', '/wpd/v1/webhook-subscription' )->get_status() );
		$this->assertSame( 403, $this->request( 'POST', '/wpd/v1/webhook-subscription/test' )->get_status() );
	}

	// ---- register -------------------------------------------------------------

	public function test_register_creates_a_subscription_and_stores_the_id() {
		$this->mock_http(
			function ( $url ) {
				$this->assertStringContainsString( '/publishers/7/webhooks', $url );
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode(
                        array(
							'id' => 55,
							'active' => true,
                        )
                    ),
				);
			}
		);

		$response = $this->request( 'POST', '/wpd/v1/webhook-subscription' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 55, wpd_plugin()->settings->get_webhook_id() );
	}

	public function test_register_sends_this_sites_own_rest_url() {
		$seen_body = null;
		$this->mock_http(
			function ( $url, $args ) use ( &$seen_body ) {
				if ( false !== strpos( $url, '/webhooks' ) ) {
					$seen_body = json_decode( $args['body'], true );
				}
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode(
                        array(
							'id' => 1,
							'active' => true,
                        )
                    ),
				);
			}
		);

		$this->request( 'POST', '/wpd/v1/webhook-subscription' );

		$this->assertSame( rest_url( 'wpd/v1/webhook' ), $seen_body['url'] );
		$this->assertSame( '*', $seen_body['event_types'] );
	}

	public function test_register_refuses_when_already_registered() {
		$opts               = wpd_plugin()->settings->get_all();
		$opts['webhook_id'] = 99;
		update_option( 'wpd_settings', $opts );

		$response = $this->request( 'POST', '/wpd/v1/webhook-subscription' );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'wpd_webhook_already_registered', $response->get_data()['code'] );
	}

	public function test_register_refuses_without_a_known_publisher_id() {
		$opts                       = wpd_plugin()->settings->get_all();
		$opts['publisher_user_id'] = 0;
		update_option( 'wpd_settings', $opts );

		$response = $this->request( 'POST', '/wpd/v1/webhook-subscription' );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'wpd_webhook_no_publisher_id', $response->get_data()['code'] );
	}

	public function test_register_refuses_without_a_signing_secret() {
		$opts                             = wpd_plugin()->settings->get_all();
		$opts['signing_secret_encrypted'] = '';
		$opts['signing_secret']           = '';
		update_option( 'wpd_settings', $opts );

		$response = $this->request( 'POST', '/wpd/v1/webhook-subscription' );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'wpd_webhook_no_signing_secret', $response->get_data()['code'] );
	}

	// ---- unregister -------------------------------------------------------------

	public function test_unregister_deletes_remotely_and_clears_the_stored_id() {
		$opts               = wpd_plugin()->settings->get_all();
		$opts['webhook_id'] = 55;
		update_option( 'wpd_settings', $opts );
		$deleted = false;
		$this->mock_http(
			function ( $url, $args ) use ( &$deleted ) {
				if ( 'DELETE' === $args['method'] ) {
					$deleted = true;
					$this->assertStringContainsString( '/publishers/7/webhooks/55', $url );
				}
				return array(
					'response' => array( 'code' => 204 ),
					'body' => '',
				);
			}
		);

		$response = $this->request( 'DELETE', '/wpd/v1/webhook-subscription' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $deleted );
		$this->assertSame( 0, wpd_plugin()->settings->get_webhook_id() );
	}

	public function test_unregister_treats_a_remote_404_as_success() {
		$opts               = wpd_plugin()->settings->get_all();
		$opts['webhook_id'] = 55;
		update_option( 'wpd_settings', $opts );
		$this->mock_http(
			function () {
				return array(
					'response' => array( 'code' => 404 ),
					'body' => '{"error":"not found"}',
				);
			}
		);

		$response = $this->request( 'DELETE', '/wpd/v1/webhook-subscription' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 0, wpd_plugin()->settings->get_webhook_id() );
	}

	public function test_unregister_refuses_when_nothing_is_registered() {
		$response = $this->request( 'DELETE', '/wpd/v1/webhook-subscription' );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'wpd_webhook_not_registered', $response->get_data()['code'] );
	}

	// ---- test ping -------------------------------------------------------------

	public function test_test_endpoint_proxies_dansals_ping_result() {
		$opts               = wpd_plugin()->settings->get_all();
		$opts['webhook_id'] = 55;
		update_option( 'wpd_settings', $opts );
		$this->mock_http(
			function ( $url ) {
				$this->assertStringContainsString( '/publishers/7/webhooks/55/test', $url );
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode(
                        array(
							'ok' => true,
							'http_status' => 202,
                        )
                    ),
				);
			}
		);

		$response = $this->request( 'POST', '/wpd/v1/webhook-subscription/test' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['ok'] );
	}

	// ---- status -------------------------------------------------------------

	public function test_status_reports_not_registered_when_nothing_is_stored() {
		$response = $this->request( 'GET', '/wpd/v1/webhook-subscription' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertFalse( $response->get_data()['registered'] );
	}

	public function test_status_reflects_the_live_dansal_row() {
		$opts               = wpd_plugin()->settings->get_all();
		$opts['webhook_id'] = 55;
		update_option( 'wpd_settings', $opts );
		$this->mock_http(
			function () {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode(
						array(
							array(
								'id' => 1,
								'active' => true,
							),
							array(
								'id' => 55,
								'active' => false,
								'last_error' => 'timeout',
								'last_delivery_at' => '2026-01-01T00:00:00Z',
							),
						)
					),
				);
			}
		);

		$response = $this->request( 'GET', '/wpd/v1/webhook-subscription' );

		$data = $response->get_data();
		$this->assertTrue( $data['registered'] );
		$this->assertFalse( $data['active'] );
		$this->assertSame( 'timeout', $data['last_error'] );
	}

	public function test_status_clears_a_stale_id_no_longer_present_dansal_side() {
		$opts               = wpd_plugin()->settings->get_all();
		$opts['webhook_id'] = 55;
		update_option( 'wpd_settings', $opts );
		$this->mock_http(
			function () {
				return array(
					'response' => array( 'code' => 200 ),
					'body' => wp_json_encode( array() ),
				);
			}
		);

		$response = $this->request( 'GET', '/wpd/v1/webhook-subscription' );

		$this->assertFalse( $response->get_data()['registered'] );
		$this->assertSame( 0, wpd_plugin()->settings->get_webhook_id(), 'the stale local pointer is cleared' );
	}

	// ---- connect-link capture -------------------------------------------------

	public function test_connect_link_captures_the_publisher_user_id() {
		update_option( 'wpd_settings', array() ); // Start disconnected.
		$this->mock_http(
			function ( $url, $args ) {
				if ( false !== strpos( $url, '/invites/' ) ) {
					// redeem_connect_link() requires its random challenge
					// echoed back before it'll accept any credentials.
					$sent      = json_decode( $args['body'], true );
					$challenge = $sent['user_metadata']['challenge'] ?? '';
					return array(
						'response' => array( 'code' => 201 ),
						'body'     => wp_json_encode(
							array(
								'api_key'   => 'ak_new',
								'user_id'   => 123,
								'org_id'    => 9,
								'base_url'  => 'https://dansal.example',
								'org_name'  => 'Test Org',
								'challenge' => $challenge,
							)
						),
					);
				}
				// The post-redemption "prove it works" token exchange.
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( array( 'token' => 'tok' ) ),
				);
			}
		);

		$response = $this->request( 'POST', '/wpd/v1/connection/link', array( 'connect_url' => 'https://dansal.example/api/v1/invites/abc/publisher' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 123, wpd_plugin()->settings->get_publisher_user_id() );
	}

	public function test_disconnect_clears_the_publisher_id_and_webhook_id() {
		$opts                       = wpd_plugin()->settings->get_all();
		$opts['webhook_id']         = 55;
		$opts['publisher_user_id'] = 7;
		update_option( 'wpd_settings', $opts );
		$this->mock_http(
			function () {
				return array(
					'response' => array( 'code' => 404 ),
					'body' => '',
				); // apikey_delete_supported() probe -> unsupported.
			}
		);

		wpd_plugin()->settings->clear_credentials();

		$this->assertSame( 0, wpd_plugin()->settings->get_webhook_id() );
		$this->assertSame( 0, wpd_plugin()->settings->get_publisher_user_id() );
	}
}
