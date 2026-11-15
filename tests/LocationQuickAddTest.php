<?php
/**
 * #159: "Add event here" quick actions on locations — prefilled new-event
 * URL, location validation, row action gating, and the upcoming-events
 * lookup that covers a building's rooms.
 */

class LocationQuickAddTest extends WP_UnitTestCase {

	public function tear_down(): void {
		unset( $_GET['wpd_location'] );
		parent::tear_down();
	}

	private function location( $dansal_id = 0, $parent_dansal_id = 0 ) {
		$id = self::factory()->post->create(
			array(
				'post_type'   => WPD_CPT_Location::POST_TYPE,
				'post_status' => 'publish',
			)
		);
		if ( $dansal_id ) {
			update_post_meta( $id, WPD_CPT_Location::META_DANSAL_ID, $dansal_id );
		}
		if ( $parent_dansal_id ) {
			update_post_meta( $id, WPD_CPT_Location::META_PARENT_DANSAL_ID, $parent_dansal_id );
		}
		return $id;
	}

	private function event_at( $location_post_id, $start ) {
		$id = self::factory()->post->create(
			array(
				'post_type'   => WPD_CPT_Event::POST_TYPE,
				'post_status' => 'publish',
			)
		);
		update_post_meta( $id, '_wpd_location_post_id', $location_post_id );
		update_post_meta( $id, '_wpd_start_time', $start );
		return $id;
	}

	public function test_new_event_url_carries_the_location() {
		$url = WPD_CPT_Event::new_event_url( 42 );
		$this->assertStringContainsString( 'post-new.php', $url );
		$this->assertStringContainsString( 'post_type=' . WPD_CPT_Event::POST_TYPE, $url );
		$this->assertStringContainsString( 'wpd_location=42', $url );
	}

	public function test_requested_location_accepts_only_location_posts() {
		$location = $this->location( 7 );
		$_GET['wpd_location'] = (string) $location;
		$this->assertSame( $location, WPD_CPT_Event::requested_location() );

		$_GET['wpd_location'] = (string) self::factory()->post->create();
		$this->assertSame( 0, WPD_CPT_Event::requested_location() );

		$_GET['wpd_location'] = 'abc';
		$this->assertSame( 0, WPD_CPT_Event::requested_location() );
	}

	public function test_row_action_needs_a_synced_location_and_create_capability() {
		$synced   = get_post( $this->location( 7 ) );
		$unsynced = get_post( $this->location() );
		$cpt      = wpd_plugin()->cpt_location;

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertArrayHasKey( 'wpd_add_event', $cpt->row_actions( array(), $synced ) );
		$this->assertArrayNotHasKey( 'wpd_add_event', $cpt->row_actions( array(), $unsynced ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertArrayNotHasKey( 'wpd_add_event', $cpt->row_actions( array(), $synced ) );
	}

	public function test_upcoming_at_location_includes_rooms_and_skips_past_and_elsewhere() {
		$building = $this->location( 10 );
		$room     = $this->location( 11, 10 );
		$other    = $this->location( 12 );

		$future = gmdate( 'Y-m-d\TH:i', time() + 7 * DAY_IN_SECONDS );
		$later  = gmdate( 'Y-m-d\TH:i', time() + 14 * DAY_IN_SECONDS );
		$past   = gmdate( 'Y-m-d\TH:i', time() - 7 * DAY_IN_SECONDS );

		$in_room     = $this->event_at( $room, $later );
		$in_building = $this->event_at( $building, $future );
		$this->event_at( $building, $past );
		$this->event_at( $other, $future );

		$ids = wp_list_pluck( WPD_CPT_Event::upcoming_at_location( $building ), 'ID' );
		$this->assertSame( array( $in_building, $in_room ), array_map( 'intval', $ids ) );

		$room_ids = wp_list_pluck( WPD_CPT_Event::upcoming_at_location( $room ), 'ID' );
		$this->assertSame( array( $in_room ), array_map( 'intval', $room_ids ) );
	}
}
