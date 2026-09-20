<?php
/**
 * Rooms as child locations (#121).
 *
 * Dansal models a room as an ordinary location with `parent_id` set, and an
 * event's `location_id` points at whichever level was chosen — there is no
 * `room_id` anymore. These tests pin down the pieces that used to lose data:
 * an event pointing at a room (or any location not in the org's list) must
 * resolve to a local post instead of blanking the stored link, and a room's
 * inherited address must never be pushed back onto it.
 */

// Tests drive the ajax/save handlers by setting the request superglobals directly.
// phpcs:disable WordPress.Security.NonceVerification

class RoomModelTest extends WP_UnitTestCase {

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

		// The plugin is a per-request singleton; its "already failed to fetch"
		// memo would otherwise leak from one test into the next.
		$memo = new ReflectionProperty( WPD_CPT_Location::class, 'unresolvable' );
		$memo->setAccessible( true );
		$memo->setValue( wpd_plugin()->cpt_location, array() );
	}

	public function tear_down(): void {
		delete_option( 'wpd_settings' );
		delete_option( WPD_CPT_Event::OPTION_ROOMS_MIGRATED );
		delete_transient( WPD_Api_Client::TOKEN_TRANSIENT );
		delete_transient( 'wpd_rooms_migration_backoff' );
		remove_all_filters( 'pre_http_request' );
		$this->requests = array();
		parent::tear_down();
	}

	// ---- fixtures -------------------------------------------------------

	private static function building_json( array $children = array() ) {
		$b = array(
			'id'        => 36,
			'location'  => 'VHS-Studienhaus',
			'address'   => 'Am Studienhaus 1',
			'zipcode'   => '50667',
			'town'      => 'Köln',
			'country'   => 'Germany',
			'latitude'  => 50.93,
			'longitude' => 6.95,
		);
		if ( $children ) {
			$b['children'] = $children;
		}
		return $b;
	}

	private static function room_json( $id = 135, $name = 'Raum 316' ) {
		// Address/coordinates are inherited from the building at read time.
		return array(
			'id'              => $id,
			'location'        => $name,
			'parent_id'       => 36,
			'address'         => 'Am Studienhaus 1',
			'zipcode'         => '50667',
			'town'            => 'Köln',
			'latitude'        => 50.93,
			'longitude'       => 6.95,
			'floor_condition' => 'parquet',
			'capacity'        => 30,
		);
	}

	/**
	 * Intercept dansal. $routes maps "METHOD /path" to an array body (200), an
	 * int (that status, empty body) or a callable.
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

	private function make_building( $dansal_id = 36, $title = 'VHS-Studienhaus' ) {
		$id = self::factory()->post->create(
			array(
				'post_type'   => WPD_CPT_Location::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => $title,
			)
		);
		update_post_meta( $id, WPD_CPT_Location::META_DANSAL_ID, $dansal_id );
		return $id;
	}

	private function make_room( $building_dansal_id = 36, $dansal_id = 135, $title = 'Raum 316' ) {
		$id = self::factory()->post->create(
			array(
				'post_type'   => WPD_CPT_Location::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => $title,
			)
		);
		update_post_meta( $id, WPD_CPT_Location::META_DANSAL_ID, $dansal_id );
		update_post_meta( $id, WPD_CPT_Location::META_PARENT_DANSAL_ID, $building_dansal_id );
		return $id;
	}

	private function make_event( $dansal_id = 500 ) {
		$id = self::factory()->post->create(
			array(
				'post_type'   => WPD_CPT_Event::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Balfolk',
			)
		);
		update_post_meta( $id, WPD_CPT_Event::META_DANSAL_ID, $dansal_id );
		return $id;
	}

	private function pull_event( $event_post_id, array $event ) {
		$event = array_merge(
			array(
				'id'           => 500,
				'title'        => 'Balfolk',
				'is_published' => true,
			),
			$event
		);
		$this->call( wpd_plugin()->cpt_event, 'write_event_post', array( $event_post_id, $event ) );
	}

	// ---- event pull: the data-loss bug -----------------------------------

	public function test_event_pointing_at_a_room_fetches_it_on_demand_and_links_it() {
		$this->mock_dansal(
			array(
				'GET /api/v1/locations/135' => self::room_json(),
				'GET /api/v1/locations/36'  => self::building_json( array( self::room_json() ) ),
			)
		);
		$event = $this->make_event();

		$this->pull_event( $event, array( 'location_id' => 135 ) );

		$room_post = WPD_CPT_Location::find_post_id_by_dansal_id( 135 );
		$this->assertNotSame( 0, $room_post, 'the room was imported as a local location post' );
		$this->assertSame( $room_post, (int) get_post_meta( $event, '_wpd_location_post_id', true ) );
		$this->assertSame( 135, (int) get_post_meta( $event, WPD_CPT_Event::META_LAST_SYNCED_LOCATION, true ) );
		// The room knows its building, and the building was imported too.
		$this->assertSame( 36, WPD_CPT_Location::parent_dansal_id( $room_post ) );
		$this->assertNotSame( 0, WPD_CPT_Location::parent_post_id( $room_post ) );
		// Inherited address/coordinates came along with the room.
		$this->assertSame( 'Am Studienhaus 1', get_post_meta( $room_post, '_wpd_address', true ) );
		$this->assertSame( 30, (int) get_post_meta( $room_post, '_wpd_capacity', true ) );
	}

	public function test_unresolvable_location_keeps_the_existing_link_and_baseline() {
		$building = $this->make_building();
		$event    = $this->make_event();
		update_post_meta( $event, '_wpd_location_post_id', $building );
		update_post_meta( $event, WPD_CPT_Event::META_LAST_SYNCED_LOCATION, 36 );
		$this->mock_dansal( array( 'GET /api/v1/locations/135' => 502 ) );

		$this->pull_event( $event, array( 'location_id' => 135 ) );

		// Before #121 this wrote '' here, and the next save then issued
		// DELETE /events/{id}/location.
		$this->assertSame( $building, (int) get_post_meta( $event, '_wpd_location_post_id', true ) );
		$this->assertSame( 36, (int) get_post_meta( $event, WPD_CPT_Event::META_LAST_SYNCED_LOCATION, true ) );
	}

	public function test_dansal_reporting_no_location_clears_the_link() {
		$building = $this->make_building();
		$event    = $this->make_event();
		update_post_meta( $event, '_wpd_location_post_id', $building );
		update_post_meta( $event, WPD_CPT_Event::META_LAST_SYNCED_LOCATION, 36 );
		$this->mock_dansal( array() );

		$this->pull_event( $event, array( 'location_id' => 0 ) );

		$this->assertSame( '', get_post_meta( $event, '_wpd_location_post_id', true ) );
		$this->assertSame( 0, (int) get_post_meta( $event, WPD_CPT_Event::META_LAST_SYNCED_LOCATION, true ) );
	}

	public function test_a_failed_lookup_is_not_repeated_within_the_request() {
		$event_a = $this->make_event( 500 );
		$event_b = $this->make_event( 501 );
		$this->mock_dansal( array( 'GET /api/v1/locations/135' => 502 ) );

		$this->pull_event( $event_a, array( 'location_id' => 135 ) );
		$this->pull_event(
            $event_b,
            array(
				'id' => 501,
				'location_id' => 135,
            )
        );

		$this->assertCount( 1, $this->requests );
	}

	// ---- event push ------------------------------------------------------

	public function test_event_push_payload_has_no_room_id_and_points_at_the_room() {
		$room  = $this->make_room();
		$event = $this->make_event();
		update_post_meta( $event, '_wpd_location_post_id', $room );
		// Stale pre-#121 value must not leak into the payload.
		update_post_meta( $event, '_wpd_room_id', 9 );

		$payload = $this->call( wpd_plugin()->cpt_event, 'build_payload', array( $event ) );

		$this->assertArrayNotHasKey( 'room_id', $payload );
		$this->assertSame( 135, $payload['location_id'] );
	}

	// ---- location pull ---------------------------------------------------

	public function test_pulling_a_building_imports_its_embedded_rooms() {
		$this->mock_dansal( array() );

		$this->call(
			wpd_plugin()->cpt_location,
			'pull_one_location',
			array( self::building_json( array( self::room_json( 135, 'Raum 316' ), self::room_json( 136, 'Saal' ) ) ) )
		);

		$building = WPD_CPT_Location::find_post_id_by_dansal_id( 36 );
		$this->assertNotSame( 0, $building );
		$this->assertSame( 0, WPD_CPT_Location::parent_dansal_id( $building ), 'a building carries no parent marker' );
		$rooms = WPD_CPT_Location::room_posts( $building );
		$this->assertSame( array( 'Raum 316', 'Saal' ), wp_list_pluck( $rooms, 'post_title' ) );
		$this->assertCount( 0, $this->requests, 'children came embedded — no extra requests needed' );
	}

	public function test_pulling_a_room_row_first_also_imports_its_building() {
		$this->mock_dansal( array( 'GET /api/v1/locations/36' => self::building_json( array( self::room_json() ) ) ) );

		$this->call( wpd_plugin()->cpt_location, 'pull_one_location', array( self::room_json() ) );

		$room = WPD_CPT_Location::find_post_id_by_dansal_id( 135 );
		$this->assertNotSame( 0, WPD_CPT_Location::parent_post_id( $room ) );
		$this->assertSame( 'VHS-Studienhaus — Raum 316', WPD_CPT_Location::label( $room ) );
	}

	public function test_room_lists_are_reimported_and_deleted_rooms_pruned() {
		$building = $this->make_building();
		$gone     = $this->make_room( 36, 200, 'Old room' );
		$used     = $this->make_room( 36, 201, 'Used old room' );
		$event    = $this->make_event();
		update_post_meta( $event, '_wpd_location_post_id', $used );
		$this->mock_dansal( array( 'GET /api/v1/locations/36/children' => array( self::room_json( 135, 'Raum 316' ) ) ) );

		$rooms = wpd_plugin()->cpt_location->fetch_rooms_for_post( $building );

		$names = wp_list_pluck( $rooms, 'name' );
		$this->assertContains( 'Raum 316', $names );
		$this->assertNull( get_post( $gone ), 'a room dansal no longer lists is removed' );
		$this->assertNotNull( get_post( $used ), 'but never while an event still points at it' );
	}

	public function test_unreachable_children_endpoint_falls_back_to_local_rooms() {
		$building = $this->make_building();
		$this->make_room();
		$this->mock_dansal( array( 'GET /api/v1/locations/36/children' => 502 ) );

		$rooms = wpd_plugin()->cpt_location->fetch_rooms_for_post( $building );

		$this->assertSame( array( 'Raum 316' ), wp_list_pluck( $rooms, 'name' ) );
	}

	// ---- location push ---------------------------------------------------

	public function test_room_push_never_carries_the_inherited_address_or_coordinates() {
		$room = $this->make_room();
		update_post_meta( $room, '_wpd_address', 'Am Studienhaus 1' );
		update_post_meta( $room, '_wpd_latitude', '50.93' );
		update_post_meta( $room, '_wpd_longitude', '6.95' );
		update_post_meta( $room, '_wpd_floor_condition', 'stone' );
		update_post_meta( $room, '_wpd_capacity', '40' );
		$this->mock_dansal( array( 'PATCH /api/v1/locations/135' => self::room_json() ) );

		$this->call( wpd_plugin()->cpt_location, 'sync_to_dansal', array( $room ) );

		$this->assertCount( 1, $this->requests );
		$body = $this->requests[0]['body'];
		foreach ( array( 'address', 'zipcode', 'town', 'country', 'country_code', 'region', 'latitude', 'longitude', 'osm_id', 'osm_type' ) as $inherited ) {
			$this->assertArrayNotHasKey( $inherited, $body, "$inherited is inherited from the building" );
		}
		$this->assertSame( 'stone', $body['floor_condition'] );
		$this->assertSame( 40, $body['capacity'] );
		$this->assertNull( $body['size_sqm'] );
	}

	public function test_a_room_without_a_dansal_id_is_never_created_from_wp() {
		$room = $this->make_room();
		delete_post_meta( $room, WPD_CPT_Location::META_DANSAL_ID );
		$this->mock_dansal( array() );

		$this->call( wpd_plugin()->cpt_location, 'sync_to_dansal', array( $room ) );

		$this->assertCount( 0, $this->requests );
	}

	public function test_saving_a_room_form_does_not_blank_the_inherited_address() {
		$room = $this->make_room();
		update_post_meta( $room, '_wpd_address', 'Am Studienhaus 1' );
		update_post_meta( $room, '_wpd_latitude', '50.93' );
		$this->mock_dansal( array( 'PATCH /api/v1/locations/135' => self::room_json() ) );

		$_POST = array(
			'wpd_location_nonce'  => wp_create_nonce( 'wpd_location_save' ),
			'wpd_floor_condition' => 'stone',
			'wpd_capacity'        => '25',
		);
		wpd_plugin()->cpt_location->save( $room );
		$_POST = array();

		$this->assertSame( 'Am Studienhaus 1', get_post_meta( $room, '_wpd_address', true ) );
		$this->assertSame( '50.93', get_post_meta( $room, '_wpd_latitude', true ) );
		$this->assertSame( '25', (string) get_post_meta( $room, '_wpd_capacity', true ) );
	}

	public function test_duplicate_check_never_offers_rooms() {
		$this->mock_dansal(
			array(
				'GET /api/v1/locations' => array(
					self::building_json(),
					self::room_json(),
				),
			)
		);
		$_GET = array(
			'_wpnonce' => wp_create_nonce( 'wpd_check_location_duplicate' ),
			'osm_id'   => '123',
			'osm_type' => 'way',
		);
		$_REQUEST = $_GET;
		add_filter(
            'wp_die_ajax_handler',
            function () {
				return function () {
					throw new WPDieException( 'done' );
				};
			}
        );
		add_filter( 'wp_doing_ajax', '__return_true' );

		ob_start();
		try {
			wpd_plugin()->cpt_location->ajax_check_duplicate();
		} catch ( WPDieException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// wp_send_json_* dies after printing.
		}
		$out = json_decode( ob_get_clean(), true );
		$_GET     = array();
		$_REQUEST = array();

		$this->assertSame( array( 36 ), wp_list_pluck( $out['data']['matches'], 'id' ) );
	}

	// ---- event form ------------------------------------------------------

	public function test_form_folds_building_plus_room_into_one_location() {
		$building = $this->make_building();
		$room     = $this->make_room();

		$out = WPD_Event_Fields::sanitize_field_group(
			array(
				'_wpd_location_post_id' => (string) $building,
				'_wpd_room_post_id'     => (string) $room,
			)
		);
		$this->assertSame( (string) $room, $out['_wpd_location_post_id'] );

		$out = WPD_Event_Fields::sanitize_field_group( array( '_wpd_location_post_id' => (string) $building ) );
		$this->assertSame( (string) $building, $out['_wpd_location_post_id'] );
		$this->assertArrayNotHasKey( '_wpd_room_id', $out );
	}

	public function test_a_room_of_another_building_does_not_ride_along() {
		$building       = $this->make_building();
		$other_building = $this->make_building( 99, 'Other venue' );
		$foreign_room   = $this->make_room( 99, 300, 'Elsewhere' );

		$out = WPD_Event_Fields::sanitize_field_group(
			array(
				'_wpd_location_post_id' => (string) $building,
				'_wpd_room_post_id'     => (string) $foreign_room,
			)
		);

		$this->assertSame( (string) $building, $out['_wpd_location_post_id'] );
		$this->assertNotSame( 0, $other_building );
	}

	public function test_event_venue_picker_lists_buildings_only() {
		$building = $this->make_building();
		$this->make_room();

		$posts = $this->call( wpd_plugin()->event_fields, 'get_location_posts' );

		$this->assertSame( array( $building ), wp_list_pluck( $posts, 'ID' ) );
	}

	// ---- migration of pre-#121 events -----------------------------------

	public function test_legacy_room_is_resolved_from_dansals_event_location_not_the_old_room_id() {
		$event = $this->make_event();
		update_post_meta( $event, '_wpd_room_id', 7 ); // the OLD rooms-table id — NOT a location id.
		update_post_meta( $event, '_wpd_room_name', 'Raum 316' );
		$this->mock_dansal(
			array(
				'GET /api/v1/events/500'    => array(
					'id' => 500,
					'location_id' => 135,
				),
				'GET /api/v1/locations/135' => self::room_json(),
				'GET /api/v1/locations/36'  => self::building_json( array( self::room_json() ) ),
			)
		);

		wpd_plugin()->cpt_event->maybe_migrate_legacy_rooms();

		$room = WPD_CPT_Location::find_post_id_by_dansal_id( 135 );
		$this->assertSame( $room, (int) get_post_meta( $event, '_wpd_location_post_id', true ) );
		$this->assertSame( '', get_post_meta( $event, '_wpd_room_id', true ) );
		$this->assertSame( '', get_post_meta( $event, '_wpd_room_name', true ) );
		// The old id 7 was never looked up as a location.
		foreach ( $this->requests as $r ) {
			$this->assertStringNotContainsString( '/locations/7', $r['url'] );
		}
	}

	public function test_legacy_room_of_a_local_only_event_is_matched_by_name() {
		$building = $this->make_building();
		$event    = self::factory()->post->create(
			array(
				'post_type'   => WPD_CPT_Event::POST_TYPE,
				'post_status' => 'draft',
				'post_title'  => 'Draft',
			)
		);
		update_post_meta( $event, '_wpd_location_post_id', $building );
		update_post_meta( $event, '_wpd_room_id', 7 );
		update_post_meta( $event, '_wpd_room_name', 'raum 316' );
		$this->mock_dansal( array( 'GET /api/v1/locations/36/children' => array( self::room_json() ) ) );

		wpd_plugin()->cpt_event->maybe_migrate_legacy_rooms();

		$room = WPD_CPT_Location::find_post_id_by_dansal_id( 135 );
		$this->assertSame( $room, (int) get_post_meta( $event, '_wpd_location_post_id', true ) );
		$this->assertSame( '', get_post_meta( $event, '_wpd_room_id', true ) );
	}

	public function test_migration_retries_later_when_dansal_is_down_and_finishes_when_nothing_is_left() {
		$event = $this->make_event();
		update_post_meta( $event, '_wpd_room_id', 7 );
		$this->mock_dansal( array( 'GET /api/v1/events/500' => 502 ) );

		wpd_plugin()->cpt_event->maybe_migrate_legacy_rooms();

		$this->assertSame( '7', (string) get_post_meta( $event, '_wpd_room_id', true ), 'kept for a retry' );
		$this->assertFalse( get_option( WPD_CPT_Event::OPTION_ROOMS_MIGRATED ) );

		delete_transient( 'wpd_rooms_migration_backoff' );
		delete_post_meta( $event, '_wpd_room_id' );
		wpd_plugin()->cpt_event->maybe_migrate_legacy_rooms();

		$this->assertSame( '1', (string) get_option( WPD_CPT_Event::OPTION_ROOMS_MIGRATED ) );
	}

	// ---- rooms admin API -------------------------------------------------

	public function test_delete_room_only_deletes_rooms_of_the_given_building() {
		$building = $this->make_building();
		$foreign  = $this->make_room( 99, 300, 'Elsewhere' );
		$this->mock_dansal( array( 'DELETE /api/v1/locations/300' => array() ) );
		$_POST    = array(
			'_wpnonce' => wp_create_nonce( 'wpd_rooms' ),
			'post_id'  => (string) $building,
			'room_id'  => '300',
		);
		$_REQUEST = $_POST;
		add_filter(
            'wp_die_ajax_handler',
            function () {
				return function () {
					throw new WPDieException( 'done' );
				};
			}
        );
		add_filter( 'wp_doing_ajax', '__return_true' );

		ob_start();
		try {
			wpd_plugin()->cpt_location->ajax_delete_room();
		} catch ( WPDieException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// wp_send_json_* dies after printing.
		}
		$out = json_decode( ob_get_clean(), true );
		$_POST    = array();
		$_REQUEST = array();

		$this->assertFalse( $out['success'] );
		$this->assertNotNull( get_post( $foreign ) );
		$this->assertCount( 0, $this->requests, 'a room of another building never reaches the API' );
	}
}
