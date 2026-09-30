<?php
/**
 * #143: a legacy-synced published event (no baseline meta from #134) must
 * not lose its edits. dansal's publishEvent 404s for a publisher/user caller
 * on an already-published event (`WHERE id=? AND is_published=0`), and
 * sync_to_dansal() used to treat any error from that call as fatal —
 * aborting before the PATCH that actually carries the edit.
 */

class EventPublishSyncTest extends WP_UnitTestCase {

	/** @var array Captured [method, url, body] of every intercepted request. */
	private $requests = array();

	public function set_up(): void {
		parent::set_up();
		update_option(
			'wpd_settings',
			array(
				'base_url' => 'https://dansal.example',
				'api_key'  => 'ak_test',
				'org_id'   => 7,
			)
		);
		// Skip the API-key -> session-token exchange in authenticated calls.
		set_transient( WPD_Api_Client::TOKEN_TRANSIENT, 'session-token', HOUR_IN_SECONDS );
		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user );
	}

	public function tear_down(): void {
		delete_option( 'wpd_settings' );
		delete_transient( WPD_Api_Client::TOKEN_TRANSIENT );
		delete_transient( 'wpd_admin_notices_' . get_current_user_id() );
		remove_all_filters( 'pre_http_request' );
		$this->requests = array();
		parent::tear_down();
	}

	/**
	 * Intercept dansal. $routes maps "METHOD /path" to an array body (200) or
	 * an int (that status, empty error body).
	 */
	private function mock_dansal( array $routes ) {
		$this->requests = array();
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( $routes ) {
				$method = isset( $args['method'] ) ? $args['method'] : 'GET';
				$path   = (string) wp_parse_url( $url, PHP_URL_PATH );
				$this->requests[] = array(
					'method' => $method,
					'url'    => $url,
					'body'   => isset( $args['body'] ) ? json_decode( $args['body'], true ) : null,
				);
				$key = $method . ' ' . $path;
				if ( ! array_key_exists( $key, $routes ) ) {
					return array(
						'response' => array( 'code' => 404 ),
						'body'     => '{"error":"not found"}',
					);
				}
				$r = $routes[ $key ];
				if ( is_int( $r ) ) {
					return array(
						'response' => array( 'code' => $r ),
						'body'     => '{"error":"simulated"}',
					);
				}
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( $r ),
				);
			},
			10,
			3
		);
	}

	private function call( $object, $method, array $args = array() ) {
		$ref = new ReflectionMethod( $object, $method );
		$ref->setAccessible( true );
		return $ref->invokeArgs( $object, $args );
	}

	/** A published event already synced to dansal, with no #134 baseline meta — as if pulled before #134 shipped. */
	private function make_legacy_published_event( $dansal_id = 507 ) {
		$id = self::factory()->post->create(
			array(
				'post_type'   => WPD_CPT_Event::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Tanzabend',
			)
		);
		update_post_meta( $id, WPD_CPT_Event::META_DANSAL_ID, $dansal_id );
		return $id;
	}

	private function sync( $event_post_id ) {
		return $this->call( wpd_plugin()->cpt_event, 'sync_to_dansal', array( $event_post_id ) );
	}

	private function stored_notices() {
		$notices = get_transient( 'wpd_admin_notices_' . get_current_user_id() );
		return is_array( $notices ) ? $notices : array();
	}

	// ---- #143 ------------------------------------------------------------

	public function test_a_404_from_publish_still_sends_the_patch_and_records_the_baseline() {
		$event = $this->make_legacy_published_event( 507 );
		$this->mock_dansal(
			array(
				'POST /api/v1/events/507/publish'  => 404,
				'PATCH /api/v1/events/507'          => array( 'id' => 507 ),
				'PUT /api/v1/events/507/timetable'  => array(),
			)
		);

		$this->sync( $event );

		$this->assertSame( '1', get_post_meta( $event, WPD_CPT_Event::META_LAST_SYNCED_PUBLISHED, true ), 'baseline recorded, which only happens after the PATCH succeeded' );
		$patch_sent = false;
		foreach ( $this->requests as $r ) {
			if ( 'PATCH' === $r['method'] ) {
				$patch_sent = true;
			}
		}
		$this->assertTrue( $patch_sent, 'the edit-carrying PATCH must still be sent after a 404 from /publish' );
		$this->assertSame( array(), $this->stored_notices(), 'a 404 treated as "already published" is not an error notice' );
	}

	public function test_a_non_404_error_from_publish_still_aborts() {
		$event = $this->make_legacy_published_event( 508 );
		$this->mock_dansal(
			array(
				'POST /api/v1/events/508/publish' => 502,
				'PATCH /api/v1/events/508'         => array( 'id' => 508 ),
			)
		);

		$this->sync( $event );

		$this->assertSame( '', get_post_meta( $event, WPD_CPT_Event::META_LAST_SYNCED_PUBLISHED, true ), 'no baseline recorded: the sync aborted' );
		foreach ( $this->requests as $r ) {
			$this->assertNotSame( 'PATCH', $r['method'], 'a real /publish failure must still abort before the PATCH' );
		}
		$this->assertNotSame( array(), $this->stored_notices(), 'a real failure is still surfaced as an admin notice' );
	}

	public function test_a_pulled_event_records_the_publish_and_cancel_baseline() {
		$event = self::factory()->post->create( array( 'post_type' => WPD_CPT_Event::POST_TYPE ) );

		$this->call(
			wpd_plugin()->cpt_event,
			'write_event_post',
			array(
				$event,
				array(
					'id'           => 509,
					'title'        => 'Bal',
					'is_published' => true,
					'is_cancelled' => false,
				),
			)
		);

		$this->assertSame( '1', get_post_meta( $event, WPD_CPT_Event::META_LAST_SYNCED_PUBLISHED, true ) );
		$this->assertSame( '', get_post_meta( $event, WPD_CPT_Event::META_LAST_SYNCED_CANCELLED, true ) );
	}
}
