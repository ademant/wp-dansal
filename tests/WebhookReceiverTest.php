<?php
/**
 * Inbound webhook receiver (#141, dansal #1370): POST /wp-json/wpd/v1/webhook.
 *
 * Authentication is entirely the HMAC signature dansal sends — no WP
 * capability check, since dansal isn't a logged-in user. These tests build
 * that signature independently of WPD_Webhook's own implementation (from the
 * documented algorithm in dansal API.md, not by importing
 * canonical_request_path()/apply_signing_headers()), so a divergence between
 * the two would actually be caught rather than the test just agreeing with
 * whatever the code happens to do.
 */

class WebhookReceiverTest extends WP_UnitTestCase {

	const SECRET = 'test-signing-secret-0123456789abcdef'; // gitleaks:allow -- test fixture, not a real credential.
	const ROUTE  = '/wpd/v1/webhook';

	/** @var array Captured [method, url, body] of every intercepted outbound request. */
	private $requests = array();

	public function set_up(): void {
		parent::set_up();
		global $wp_rest_server;
		$wp_rest_server = null;

		update_option(
			'wpd_settings',
			array(
				'base_url'                 => 'https://dansal.example',
				'api_key'                  => 'ak_test',
				'signing_secret_encrypted' => WPD_Secret::encrypt( self::SECRET ),
				'signing_secret'           => '***',
			)
		);
		$_SERVER['REQUEST_URI'] = self::ROUTE;
	}

	public function tear_down(): void {
		delete_option( 'wpd_settings' );
		// Leave the key present (as a real request always would) rather than
		// unset — WP core's own cron.php reads it unconditionally elsewhere.
		$_SERVER['REQUEST_URI'] = '/';
		remove_all_filters( 'pre_http_request' );
		remove_all_filters( 'wpd_webhook_max_skew_seconds' );
		$this->requests = array();
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	/**
	 * Independent re-derivation of dansal API.md's canonical payload and
	 * HMAC — deliberately not calling any WPD_Webhook code.
	 */
	private function sign( $method, $path, $ts, $body, $nonce, $secret = self::SECRET ) {
		$payload = $method . "\n" . $path . "\n" . $ts . "\n" . hash( 'sha256', $body ) . "\n" . $nonce;
		return hash_hmac( 'sha256', $payload, $secret );
	}

	private function signed_request( array $overrides = array() ) {
		$defaults = array(
			'path'  => self::ROUTE,
			'ts'    => (string) time(),
			'nonce' => bin2hex( random_bytes( 16 ) ),
			'body'  => wp_json_encode(
				array(
					'event'       => 'event.update',
					'resource'    => 'event',
					'resource_id' => 42,
					'action'      => 'update',
					'delivery_id' => 'd1',
				)
			),
			'secret' => self::SECRET,
		);
		$args = array_merge( $defaults, $overrides );

		$request = new WP_REST_Request( 'POST', self::ROUTE );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( $args['body'] );
		if ( ! isset( $overrides['no_headers'] ) ) {
			$request->set_header( 'x-wpd-timestamp', $args['ts'] );
			$request->set_header( 'x-wpd-nonce', $args['nonce'] );
			$sig = isset( $overrides['signature'] )
				? $overrides['signature']
				: $this->sign( 'POST', $args['path'], $args['ts'], $args['body'], $args['nonce'], $args['secret'] );
			$request->set_header( 'x-wpd-signature', $sig );
		}
		return $request;
	}

	private function mock_event_fetch( array $event = array() ) {
		$this->requests = array();
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( $event ) {
				$this->requests[] = $url;
				// pull_event_by_dansal_id() exchanges the API key for a session
				// token before its actual GET — answer that call too.
				if ( false !== strpos( $url, '/publishers/token' ) ) {
					return array(
						'response' => array( 'code' => 200 ),
						'body'     => wp_json_encode( array( 'token' => 'session-token' ) ),
					);
				}
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode(
						array_merge(
							array(
								'id'         => 42,
								'title'      => 'Balfolk',
								'changed_at' => gmdate( 'c' ),
							),
							$event
						)
					),
				);
			},
			10,
			3
		);
	}

	// ---- route registration ------------------------------------------------

	public function test_route_is_registered_public_post() {
		$routes = rest_get_server()->get_routes( 'wpd/v1' );
		$this->assertArrayHasKey( self::ROUTE, $routes );
	}

	// ---- signature verification --------------------------------------------

	public function test_valid_signature_is_accepted() {
		$this->mock_event_fetch();
		$response = rest_do_request( $this->signed_request() );

		$this->assertSame( 202, $response->get_status() );
		$this->assertTrue( $response->get_data()['ok'] );
	}

	public function test_missing_headers_are_rejected() {
		$response = rest_do_request( $this->signed_request( array( 'no_headers' => true ) ) );

		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( 'wpd_webhook_missing_headers', $response->get_data()['code'] );
	}

	public function test_stale_timestamp_is_rejected() {
		$response = rest_do_request( $this->signed_request( array( 'ts' => (string) ( time() - 400 ) ) ) );

		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( 'wpd_webhook_stale', $response->get_data()['code'] );
	}

	public function test_timestamp_within_a_filtered_wider_skew_is_accepted() {
		add_filter( 'wpd_webhook_max_skew_seconds', fn() => 600 );
		$this->mock_event_fetch();

		$response = rest_do_request( $this->signed_request( array( 'ts' => (string) ( time() - 400 ) ) ) );

		$this->assertSame( 202, $response->get_status() );
	}

	public function test_malformed_nonce_is_rejected() {
		$response = rest_do_request( $this->signed_request( array( 'nonce' => 'not-hex!' ) ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'wpd_webhook_bad_nonce', $response->get_data()['code'] );
	}

	public function test_replayed_nonce_is_rejected_on_the_second_delivery() {
		$this->mock_event_fetch();
		$request = $this->signed_request();

		$first  = rest_do_request( clone $request );
		$second = rest_do_request( clone $request );

		$this->assertSame( 202, $first->get_status() );
		$this->assertSame( 401, $second->get_status() );
		$this->assertSame( 'wpd_webhook_replay', $second->get_data()['code'] );
	}

	public function test_wrong_secret_produces_a_signature_mismatch() {
		$response = rest_do_request( $this->signed_request( array( 'secret' => 'wrong-secret' ) ) );

		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( 'wpd_webhook_bad_signature', $response->get_data()['code'] );
	}

	public function test_a_tampered_body_fails_verification() {
		$request = $this->signed_request();
		// Signature was computed over the original body; swap it after
		// signing, exactly like an on-the-wire tamper would.
		$request->set_body(
            wp_json_encode(
                array(
					'event' => 'event.update',
					'resource' => 'event',
					'resource_id' => 999,
					'action' => 'update',
                )
            )
        );

		$response = rest_do_request( $request );

		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( 'wpd_webhook_bad_signature', $response->get_data()['code'] );
	}

	public function test_signature_computed_over_the_wrong_path_is_rejected() {
		// Simulates a delivery signed for a different registered URL (e.g.
		// the plain-permalink ?rest_route= form) being replayed against this one.
		$response = rest_do_request( $this->signed_request( array( 'path' => '/some/other/path' ) ) );

		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( 'wpd_webhook_bad_signature', $response->get_data()['code'] );
	}

	public function test_query_string_is_part_of_the_signed_path() {
		// Mirrors dansal's own documented case: a site without pretty
		// permalinks registers /?rest_route=/wpd/v1/webhook, so the query
		// is signed too.
		$_SERVER['REQUEST_URI'] = '/index.php?rest_route=' . rawurlencode( self::ROUTE );
		$this->mock_event_fetch();

		$response = rest_do_request( $this->signed_request( array( 'path' => '/index.php?rest_route=' . rawurlencode( self::ROUTE ) ) ) );

		$this->assertSame( 202, $response->get_status() );
	}

	public function test_no_signing_secret_configured_rejects_everything() {
		update_option(
            'wpd_settings',
            array(
				'base_url' => 'https://dansal.example',
				'api_key' => 'ak_test',
            )
        );

		$response = rest_do_request( $this->signed_request() );

		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( 'wpd_webhook_not_configured', $response->get_data()['code'] );
	}

	// ---- payload routing ----------------------------------------------------

	public function test_ping_is_acknowledged_without_triggering_a_pull() {
		$this->requests = array();
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				$this->requests[] = $url;
				return array(
					'response' => array( 'code' => 200 ),
					'body' => '{}',
				);
			},
			10,
			3
		);

		$body    = wp_json_encode(
            array(
				'event' => 'ping',
				'delivery_id' => 'd1',
            )
        );
		$ts      = (string) time();
		$nonce   = bin2hex( random_bytes( 16 ) );
		$request = new WP_REST_Request( 'POST', self::ROUTE );
		$request->set_body( $body );
		$request->set_header( 'x-wpd-timestamp', $ts );
		$request->set_header( 'x-wpd-nonce', $nonce );
		$request->set_header( 'x-wpd-signature', $this->sign( 'POST', self::ROUTE, $ts, $body, $nonce ) );

		$response = rest_do_request( $request );

		$this->assertSame( 202, $response->get_status() );
		$this->assertTrue( $response->get_data()['ok'] );
		$this->assertCount( 0, $this->requests, 'a ping never triggers an event re-fetch' );
	}

	public function test_delete_action_is_acknowledged_without_a_local_delete() {
		$post_id = self::factory()->post->create(
            array(
				'post_type' => WPD_CPT_Event::POST_TYPE,
				'post_status' => 'publish',
            )
        );
		update_post_meta( $post_id, WPD_CPT_Event::META_DANSAL_ID, 42 );
		$this->requests = array();
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				$this->requests[] = $url;
				return array(
					'response' => array( 'code' => 200 ),
					'body' => '{}',
				);
			},
			10,
			3
		);

		$body    = wp_json_encode(
            array(
				'event' => 'event.delete',
				'resource' => 'event',
				'resource_id' => 42,
				'action' => 'delete',
            )
        );
		$response = rest_do_request( $this->build_signed_body_request( $body ) );

		$this->assertSame( 202, $response->get_status() );
		$this->assertCount( 0, $this->requests, 'delete never re-fetches' );
		$this->assertNotNull( get_post( $post_id ), 'the local post is never deleted based on a webhook' );
	}

	public function test_non_event_resource_is_acknowledged_and_ignored() {
		$this->requests = array();
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				$this->requests[] = $url;
				return array(
					'response' => array( 'code' => 200 ),
					'body' => '{}',
				);
			},
			10,
			3
		);

		$body     = wp_json_encode(
            array(
				'event' => 'location.update',
				'resource' => 'location',
				'resource_id' => 1,
				'action' => 'update',
            )
        );
		$response = rest_do_request( $this->build_signed_body_request( $body ) );

		$this->assertSame( 202, $response->get_status() );
		$this->assertCount( 0, $this->requests, 'a future non-event resource never 4xxs, and never triggers an event pull' );
	}

	public function test_missing_body_is_rejected_as_malformed() {
		$response = rest_do_request( $this->build_signed_body_request( 'not json' ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'wpd_webhook_bad_payload', $response->get_data()['code'] );
	}

	public function test_event_create_fetches_and_creates_a_new_local_post() {
		$this->mock_event_fetch(
            array(
				'id' => 42,
				'is_published' => true,
            )
        );
		$body     = wp_json_encode(
            array(
				'event' => 'event.create',
				'resource' => 'event',
				'resource_id' => 42,
				'action' => 'create',
            )
        );
		$response = rest_do_request( $this->build_signed_body_request( $body ) );

		$this->assertSame( 202, $response->get_status() );
		$this->assertTrue( $response->get_data()['ok'] );
		$this->assertSame( 'created', $response->get_data()['result'] );
		$this->assertNotSame( 0, WPD_CPT_Event::find_post_id_by_dansal_id( 42 ) );
	}

	/** @return WP_REST_Request A validly-signed request carrying $body. */
	private function build_signed_body_request( $body ) {
		$ts      = (string) time();
		$nonce   = bin2hex( random_bytes( 16 ) );
		$request = new WP_REST_Request( 'POST', self::ROUTE );
		$request->set_body( $body );
		$request->set_header( 'x-wpd-timestamp', $ts );
		$request->set_header( 'x-wpd-nonce', $nonce );
		$request->set_header( 'x-wpd-signature', $this->sign( 'POST', self::ROUTE, $ts, $body, $nonce ) );
		return $request;
	}
}
