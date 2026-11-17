<?php
/**
 * #167: dansal-side changes on an event are only a conflict when their
 * content differs from WordPress. A newer changed_at alone is adopted
 * silently, a same-content 412 retries, and a forced save overwrites dansal.
 */

class EventSyncConflictTest extends WP_UnitTestCase {

	/** @var array Captured [method, url, body, headers] of every intercepted request. */
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
		set_transient( WPD_Api_Client::TOKEN_TRANSIENT, 'session-token', HOUR_IN_SECONDS );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
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
	 * Intercepts dansal. $handler receives ($method, $path, $headers) and
	 * returns [status, decoded body].
	 */
	private function mock_dansal( callable $handler ) {
		$this->requests = array();
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( $handler ) {
				$method  = isset( $args['method'] ) ? $args['method'] : 'GET';
				$path    = (string) wp_parse_url( $url, PHP_URL_PATH );
				$headers = isset( $args['headers'] ) && is_array( $args['headers'] ) ? $args['headers'] : array();
				$this->requests[] = array(
					'method'  => $method,
					'path'    => $path,
					'body'    => isset( $args['body'] ) ? json_decode( $args['body'], true ) : null,
					'headers' => $headers,
				);
				list( $status, $body ) = $handler( $method, $path, $headers );
				return array(
					'response' => array( 'code' => $status ),
					'body'     => null === $body ? '' : wp_json_encode( $body ),
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

	/**
	 * A published event synced to dansal, with the same content dansal will
	 * report back, and a stored baseline.
	 */
	private function make_synced_event( $dansal_id, $changed_at ) {
		$id = self::factory()->post->create(
			array(
				'post_type'    => WPD_CPT_Event::POST_TYPE,
				'post_status'  => 'publish',
				'post_title'   => 'Bal',
				'post_content' => '',
			)
		);
		update_post_meta( $id, WPD_CPT_Event::META_DANSAL_ID, $dansal_id );
		update_post_meta( $id, WPD_CPT_Event::META_LAST_PULLED_CHANGED_AT, $changed_at );
		return $id;
	}

	/** dansal's view of the event that matches make_synced_event(). */
	private function remote_event( $dansal_id, $changed_at, array $overrides = array() ) {
		return array_merge(
			array(
				'id'           => $dansal_id,
				'title'        => 'Bal',
				'description'  => '',
				'start_time'   => '',
				'end_time'     => '',
				'is_cancelled' => false,
				'is_published' => true,
				'no_live_music' => false,
				'location_id'  => null,
				'booking_url'  => '',
				'attributes'   => array(
					'wheelchair' => false,
					'bar'        => false,
					'kitchen'    => false,
				),
				'instructors'  => array(),
				'changed_at'   => $changed_at,
				'changed_by'   => 'admin',
			),
			$overrides
		);
	}

	private function sync( $post_id, $force = false ) {
		return $this->call( wpd_plugin()->cpt_event, 'sync_to_dansal', array( $post_id, $force ) );
	}

	private function reconcile( $post_id, array $event ) {
		return $this->call( wpd_plugin()->cpt_event, 'reconcile_dansal_event', array( $post_id, $event ) );
	}

	private function has_pending( $post_id ) {
		return (bool) get_post_meta( $post_id, '_wpd_pending_pull', true );
	}

	private function stored_notices() {
		$notices = get_transient( 'wpd_admin_notices_' . get_current_user_id() );
		return is_array( $notices ) ? $notices : array();
	}

	private function patch_requests() {
		return array_values(
            array_filter(
                $this->requests,
                static function ( $r ) {
					return 'PATCH' === $r['method'];
				}
            )
        );
	}

	// ---- edit screen / pull: newer changed_at --------------------------------

	public function test_newer_changed_at_with_same_content_is_adopted_without_a_hint() {
		$event = $this->make_synced_event( 701, 100 );

		$this->reconcile( $event, $this->remote_event( 701, '1970-01-01T00:03:20Z', array( 'changed_by' => 'someone' ) ) );

		$this->assertSame( 200, (int) get_post_meta( $event, WPD_CPT_Event::META_LAST_PULLED_CHANGED_AT, true ), 'baseline adopted' );
		$this->assertSame( 'someone', get_post_meta( $event, WPD_CPT_Event::META_LAST_PULLED_CHANGED_BY, true ) );
		$this->assertFalse( $this->has_pending( $event ), 'same content must not produce a pending update' );
	}

	public function test_newer_changed_at_with_different_title_becomes_a_pending_update() {
		$event = $this->make_synced_event( 702, 100 );

		$this->reconcile( $event, $this->remote_event( 702, '1970-01-01T00:03:20Z', array( 'title' => 'Bal (abgesagt)' ) ) );

		$this->assertTrue( $this->has_pending( $event ), 'a real content difference must be offered as a pending update' );
		$this->assertSame( 100, (int) get_post_meta( $event, WPD_CPT_Event::META_LAST_PULLED_CHANGED_AT, true ), 'baseline stays until the user syncs' );
	}

	public function test_unchanged_changed_at_is_ignored() {
		$event = $this->make_synced_event( 703, 200 );

		$this->reconcile( $event, $this->remote_event( 703, '1970-01-01T00:03:20Z', array( 'title' => 'Other' ) ) );

		$this->assertFalse( $this->has_pending( $event ), 'changed_at equal to the baseline means nothing new on dansal' );
	}

	// ---- save: 412 handling ---------------------------------------------------

	public function test_412_with_same_content_retries_and_saves_without_an_error() {
		$event     = $this->make_synced_event( 704, 100 );
		$patches   = 0;
		$this->mock_dansal(
			function ( $method, $path, $headers ) use ( &$patches ) {
				if ( 'PATCH' === $method ) {
					++$patches;
					if ( 1 === $patches ) {
						return array( 412, array( 'error' => 'If-Match mismatch' ) );
					}
					return array( 200, $this->remote_event( 704, '1970-01-01T00:03:20Z' ) );
				}
				if ( 'GET' === $method ) {
					return array( 200, $this->remote_event( 704, '1970-01-01T00:03:20Z' ) );
				}
				return array( 200, array() );
			}
		);

		$this->sync( $event );

		$this->assertSame( 2, count( $this->patch_requests() ), 'one retry after the 412' );
		$this->assertSame( '"200"', $this->patch_requests()[1]['headers']['If-Match'], 'retry uses the adopted changed_at' );
		$this->assertFalse( $this->has_pending( $event ) );
		$this->assertSame( array(), $this->stored_notices(), 'no error notice for a non-conflict' );
	}

	public function test_412_with_different_content_stashes_the_update_and_keeps_the_edit_unsaved() {
		$event = $this->make_synced_event( 705, 100 );
		$this->mock_dansal(
			function ( $method ) {
				if ( 'PATCH' === $method ) {
					return array( 412, array( 'error' => 'If-Match mismatch' ) );
				}
				return array( 200, $this->remote_event( 705, '1970-01-01T00:03:20Z', array( 'title' => 'Dansal-Titel' ) ) );
			}
		);

		$this->sync( $event );

		$this->assertTrue( $this->has_pending( $event ), 'the dansal version must be stashed for the hint' );
		$this->assertCount( 1, $this->patch_requests(), 'no retry when the content differs' );
		$this->assertNotSame( array(), $this->stored_notices(), 'the unsaved edit is reported' );
	}

	public function test_force_sends_if_match_star_and_clears_the_pending_update() {
		$event = $this->make_synced_event( 706, 100 );
		update_post_meta( $event, '_wpd_pending_pull', 200 );
		set_transient( 'wpd_event_pending_pull_' . $event, $this->remote_event( 706, '1970-01-01T00:03:20Z' ), DAY_IN_SECONDS );
		$this->mock_dansal(
			static function ( $method ) {
				if ( 'PATCH' === $method ) {
					return array(
						200,
						array(
							'id' => 706,
							'changed_at' => '1970-01-01T00:05:00Z',
							'changed_by' => 'admin',
						),
					);
				}
				return array( 200, array() );
			}
		);

		$this->sync( $event, true );

		$this->assertSame( '*', $this->patch_requests()[0]['headers']['If-Match'] );
		$this->assertFalse( $this->has_pending( $event ), 'a successful force clears the hint' );
		$this->assertSame( 300, (int) get_post_meta( $event, WPD_CPT_Event::META_LAST_PULLED_CHANGED_AT, true ), 'baseline follows the forced write' );
	}

	public function test_clearing_the_location_adopts_the_baseline_before_the_patch() {
		$event = $this->make_synced_event( 707, 100 );
		update_post_meta( $event, WPD_CPT_Event::META_LAST_SYNCED_LOCATION, 300 );
		update_post_meta( $event, WPD_CPT_Event::META_LAST_SYNCED_PUBLISHED, '1' );
		$this->mock_dansal(
			function ( $method ) {
				if ( 'DELETE' === $method ) {
					return array( 204, null ); // Our own write bumps changed_at on dansal.
				}
				if ( 'GET' === $method ) {
					return array( 200, $this->remote_event( 707, '1970-01-01T00:03:20Z' ) );
				}
				if ( 'PATCH' === $method ) {
					return array(
						200,
						array(
							'id' => 707,
							'changed_at' => '1970-01-01T00:03:20Z',
							'changed_by' => 'admin',
						),
					);
				}
				return array( 200, array() );
			}
		);

		$this->sync( $event );

		$this->assertSame( '"200"', $this->patch_requests()[0]['headers']['If-Match'], 'the PATCH must not carry the stale ETag from before our DELETE' );
		$this->assertSame( array(), $this->stored_notices() );
	}

	public function test_if_match_uses_the_etag_form_dansal_returns() {
		$event = $this->make_synced_event( 708, 200 );
		$this->mock_dansal(
			static function ( $method ) {
				if ( 'PATCH' === $method ) {
					return array( 200, array( 'id' => 708, 'changed_at' => '1970-01-01T00:03:20Z', 'changed_by' => 'admin' ) );
				}
				return array( 200, array() );
			}
		);

		$this->sync( $event );

		$this->assertSame( '"200"', $this->patch_requests()[0]['headers']['If-Match'], 'dansal compares If-Match exactly with its strong-form ETag' );
	}

	public function test_crlf_in_wordpress_and_lf_from_dansal_are_the_same_content() {
		$event = $this->make_synced_event( 709, 100 );
		wp_update_post( array( 'ID' => $event, 'post_content' => "Zeile eins.\r\n\r\nZeile zwei." ) );

		$this->reconcile( $event, $this->remote_event( 709, '1970-01-01T00:03:20Z', array( 'description' => "Zeile eins.\n\nZeile zwei." ) ) );

		$this->assertFalse( $this->has_pending( $event ), 'line endings alone must not produce a pending update' );
		$this->assertSame( 200, (int) get_post_meta( $event, WPD_CPT_Event::META_LAST_PULLED_CHANGED_AT, true ) );
	}

	public function test_description_sent_to_dansal_has_no_carriage_returns() {
		$event = $this->make_synced_event( 710, 100 );
		wp_update_post( array( 'ID' => $event, 'post_content' => "Zeile eins.\r\nZeile zwei." ) );

		$payload = $this->call( wpd_plugin()->cpt_event, 'build_payload', array( $event ) );

		$this->assertSame( "Zeile eins.\nZeile zwei.", $payload['description'] );
	}
}
