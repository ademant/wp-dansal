<?php
/**
 * #144: payload builders must send the raw post_title to dansal, not
 * get_the_title()'s the_title-filtered output — wptexturize() (hooked on
 * the_title) HTML-entity-encodes straight quotes/dashes for HTML display,
 * which corrupts a plain-text API field. Confirmed live: an event title
 * from balfolk-koeln.de showed up on balfolk.jetzt as the literal garbled
 * text "Tanzlernabend &#8211; Kost ar c&#8217;hoat".
 *
 * Each test hooks `the_title` with an unmissable marker so a regression
 * (some call site reverting to get_the_title()) fails loudly instead of
 * only on titles that happen to contain a quote or dash.
 */

class TitleRawTest extends WP_UnitTestCase {

	private $marker_filter;

	public function tear_down(): void {
		if ( $this->marker_filter ) {
			remove_filter( 'the_title', $this->marker_filter );
			$this->marker_filter = null;
		}
		parent::tear_down();
	}

	/** Makes get_the_title() detectably diverge from the raw post_title. */
	private function mark_the_title_filter() {
		$this->marker_filter = function ( $title ) {
			return $title . ' [FILTERED]';
		};
		add_filter( 'the_title', $this->marker_filter );
	}

	private function call( $object, $method, array $args = array() ) {
		$ref = new ReflectionMethod( $object, $method );
		$ref->setAccessible( true );
		return $ref->invokeArgs( $object, $args );
	}

	public function test_event_payload_title_is_raw() {
		$this->mark_the_title_filter();
		$id = self::factory()->post->create(
			array(
				'post_type'  => WPD_CPT_Event::POST_TYPE,
				'post_title' => "Tanzlernabend \xe2\x80\x93 Kost ar c'hoat",
			)
		);

		$payload = $this->call( wpd_plugin()->cpt_event, 'build_payload', array( $id ) );

		$this->assertSame( "Tanzlernabend \xe2\x80\x93 Kost ar c'hoat", $payload['title'] );
	}

	public function test_series_payload_title_is_raw() {
		$this->mark_the_title_filter();
		$id = self::factory()->post->create(
			array(
				'post_type'  => WPD_CPT_Series::POST_TYPE,
				'post_title' => "Thursday Night Bal'Folk",
			)
		);

		$payload = $this->call( wpd_plugin()->cpt_series, 'build_payload', array( $id ) );

		$this->assertSame( "Thursday Night Bal'Folk", $payload['title'] );
	}

	public function test_musician_payload_primary_field_is_raw() {
		$this->mark_the_title_filter();
		$id = self::factory()->post->create(
			array(
				'post_type'  => WPD_CPT_Musician::POST_TYPE,
				'post_title' => "Kost ar c'hoat",
			)
		);

		$payload = $this->call( wpd_plugin()->cpt_musician, 'build_payload', array( $id ) );

		$this->assertSame( "Kost ar c'hoat", $payload['bandname'] );
	}

	public function test_location_sync_payload_title_is_raw() {
		$this->mark_the_title_filter();
		$id = self::factory()->post->create(
			array(
				'post_type'  => WPD_CPT_Location::POST_TYPE,
				'post_title' => "L'Atelier",
			)
		);

		// sync_to_dansal() reads post_title itself before handing it to
		// build_payload(); this is what actually exercises that read.
		$title = get_post_field( 'post_title', $id, 'raw' );
		$this->assertSame( "L'Atelier", $title );

		$payload = $this->call( wpd_plugin()->cpt_location, 'build_payload', array( $id, $title ) );
		$this->assertSame( "L'Atelier", $payload['location'] );
	}

	/**
	 * #144's change-detection fix: write_location_post() must compare the
	 * incoming dansal title against the raw local title, not the
	 * the_title-filtered one — otherwise every title with a quote/dash
	 * would look "changed" on every pull and trigger a pointless
	 * wp_update_post() (and the save_post round-trip that follows it).
	 */
	public function test_location_write_skips_update_when_raw_title_unchanged() {
		$this->mark_the_title_filter();
		$id = self::factory()->post->create(
			array(
				'post_type'  => WPD_CPT_Location::POST_TYPE,
				'post_title' => "L'Atelier",
			)
		);
		$before = get_post( $id )->post_modified_gmt;

		$this->call(
			wpd_plugin()->cpt_location,
			'write_location_post',
			array(
				$id,
				array(
					'location' => "L'Atelier",
					'id'       => 999,
				),
			)
		);

		$this->assertSame( $before, get_post( $id )->post_modified_gmt, 'no wp_update_post() call means post_modified does not move' );
	}

	public function test_musician_write_skips_update_when_raw_title_unchanged() {
		$this->mark_the_title_filter();
		$id = self::factory()->post->create(
			array(
				'post_type'  => WPD_CPT_Musician::POST_TYPE,
				'post_title' => "Kost ar c'hoat",
			)
		);
		$before = get_post( $id )->post_modified_gmt;

		$this->call(
			wpd_plugin()->cpt_musician,
			'write_post',
			array( $id, array( 'bandname' => "Kost ar c'hoat" ) )
		);

		$this->assertSame( $before, get_post( $id )->post_modified_gmt, 'no wp_update_post() call means post_modified does not move' );
	}
}
