<?php
/**
 * #126 part 1: the mini-calendar month-nav and [dansal_nearby] geolocation
 * refresh are wired through the Interactivity API (data-wp-on--click,
 * data-wp-init) instead of a manual addEventListener/DOMContentLoaded scan.
 * These pin the markup contract the two view script modules
 * (assets/js/frontend-mini-calendar.js, assets/js/frontend-nearby.js) rely
 * on, since nothing else in the suite exercises the module registration
 * path directly.
 */

class InteractivityTest extends WP_UnitTestCase {

	public function tear_down(): void {
		delete_option( 'wpd_settings' );
		parent::tear_down();
	}

	public function test_mini_calendar_carries_the_interactivity_directives() {
		$html = do_shortcode( '[dansal_events view="mini"]' );

		$this->assertStringContainsString( 'data-wp-interactive="wp-dansal"', $html );
		$this->assertStringContainsString( 'data-wp-on--click="actions.flipMonth"', $html );
		$this->assertStringContainsString( 'data-wpd-rest-url="' . esc_url( rest_url( 'wpd/v1/mini-calendar' ) ) . '"', $html );
		// Both nav arrows must be wired, not just one.
		$this->assertSame( 2, substr_count( $html, 'data-wp-on--click="actions.flipMonth"' ) );
	}

	public function test_nearby_wrapper_carries_the_interactivity_directives_even_when_unconfigured() {
		// No wpd_settings home coords: shortcode_nearby() takes its "not
		// configured" branch, which must still carry the same directives —
		// requestNearby() only ever needs the visitor's own geolocation, not
		// this wrapper's fallback center.
		$html = do_shortcode( '[dansal_nearby]' );

		$this->assertStringContainsString( 'data-wp-interactive="wp-dansal"', $html );
		$this->assertStringContainsString( 'data-wp-init="callbacks.requestNearby"', $html );
		$this->assertStringContainsString( 'data-wpd-rest-url="' . esc_url( rest_url( 'wpd/v1/nearby' ) ) . '"', $html );
	}

	public function test_nearby_wrapper_carries_the_interactivity_directives_when_configured() {
		update_option(
			'wpd_settings',
			array(
				'home_lat' => '50.9',
				'home_lon' => '6.9',
			)
		);

		$html = do_shortcode( '[dansal_nearby]' );

		$this->assertStringContainsString( 'data-wp-interactive="wp-dansal"', $html );
		$this->assertStringContainsString( 'data-wp-init="callbacks.requestNearby"', $html );
	}
}
