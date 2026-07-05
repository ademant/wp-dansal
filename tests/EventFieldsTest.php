<?php
/**
 * Seed suite for WPD_Event_Fields::sanitize_field_group().
 *
 * The sanitizer is the choke point every save flows through, so the
 * cases here nail down the two output shapes that are easy to break:
 *  - _wpd_tags is comma-boundary-padded so meta_query LIKE lookups
 *    on the frontend match whole slugs;
 *  - _wpd_dance_ids is a plain comma-joined list of absint'd IDs.
 */

class EventFieldsTest extends WP_UnitTestCase {

	public function test_missing_text_keys_default_to_empty_string() {
		$out = WPD_Event_Fields::sanitize_field_group( array() );
		$this->assertSame( '', $out['_wpd_booking_url'] );
		$this->assertSame( '', $out['_wpd_pricing_amount'] );
	}

	public function test_flag_keys_normalize_to_1_or_empty_string() {
		$out = WPD_Event_Fields::sanitize_field_group(
			array(
				'_wpd_has_ball'     => '1',
				'_wpd_has_workshop' => '0',
				'_wpd_has_festival' => '',
			)
		);
		$this->assertSame( '1', $out['_wpd_has_ball'] );
		// '0' is falsy in PHP, so the sanitizer treats it as unset — an
		// unchecked checkbox posts nothing, so this shape matches reality.
		$this->assertSame( '', $out['_wpd_has_workshop'] );
		$this->assertSame( '', $out['_wpd_has_festival'] );
	}

	public function test_tags_are_comma_boundary_padded() {
		$out = WPD_Event_Fields::sanitize_field_group(
			array( '_wpd_tags' => array( 'bal-folk', 'contra' ) )
		);
		$this->assertSame( ',bal-folk,contra,', $out['_wpd_tags'] );
	}

	public function test_empty_tags_produce_empty_string_not_bare_commas() {
		$out = WPD_Event_Fields::sanitize_field_group(
			array( '_wpd_tags' => array( '', null ) )
		);
		$this->assertSame( '', $out['_wpd_tags'] );
	}

	public function test_dance_ids_are_absint_joined() {
		$out = WPD_Event_Fields::sanitize_field_group(
			array( '_wpd_dance_ids' => array( '3', '-1', 'x', 7 ) )
		);
		// absint('x') is 0, filtered out; absint('-1') is 1.
		$this->assertSame( '3,1,7', $out['_wpd_dance_ids'] );
	}

	public function test_array_valued_text_key_falls_back_to_empty_string() {
		$out = WPD_Event_Fields::sanitize_field_group(
			array( '_wpd_booking_url' => array( 'not-a-string' ) )
		);
		$this->assertSame( '', $out['_wpd_booking_url'] );
	}
}
