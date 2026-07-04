<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders a small "From {source}: HH:MM–HH:MM" hint under a datetime-local
 * input, and enqueues a JS module that pre-fills the time portion of that
 * input as soon as the user picks a date.
 *
 * Used by templates (#11) and event series (#12) to nudge the user through
 * the "pick a date; time is already set for you" flow.
 */
class WPD_Datetime_Hint {

	/**
	 * @param string $input_id ID of the datetime-local input to bind to.
	 * @param string $time     Time-of-day in HH:MM to auto-fill.
	 * @param string $label    Human-readable hint text (already-formatted,
	 *                         translatable).
	 */
	public static function render( $input_id, $time, $label ) {
		printf(
			'<p class="description wpd-datetime-hint" data-target="%s" data-time="%s">%s</p>',
			esc_attr( $input_id ),
			esc_attr( $time ),
			esc_html( $label )
		);
	}

	public static function enqueue() {
		wp_enqueue_script(
			'wpd-datetime-hint',
			WPD_PLUGIN_URL . 'assets/js/admin-datetime-hint.js',
			array(),
			WPD_VERSION,
			true
		);
	}
}
