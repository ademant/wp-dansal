<?php
/**
 * #153: rejected webhook deliveries and a dead publisher key raise a
 * `wpd_security_event` with non-secret context only.
 */

class SecurityEventTest extends WP_UnitTestCase {

	/** @var array[] Captured [event, context] pairs. */
	private $events = array();

	public function set_up(): void {
		parent::set_up();
		$this->events = array();
		add_action(
			'wpd_security_event',
			function ( $event, $context ) {
				$this->events[] = array( $event, $context );
			},
			10,
			2
		);
		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
	}

	public function tear_down(): void {
		remove_all_actions( 'wpd_security_event' );
		remove_all_filters( 'wpd_webhook_rate_limit' );
		unset( $_SERVER['REMOTE_ADDR'] );
		parent::tear_down();
	}

	public function test_an_unsigned_delivery_is_reported_with_its_error_code_and_address() {
		wpd_plugin()->webhook->receive( new WP_REST_Request( 'POST', '/wpd/v1/webhook' ) );

		$this->assertCount( 1, $this->events );
		list( $event, $context ) = $this->events[0];
		$this->assertSame( 'webhook.rejected', $event );
		$this->assertSame( 'wpd_webhook_missing_headers', $context['code'] );
		$this->assertSame( '203.0.113.9', $context['ip'] );
		$this->assertArrayNotHasKey( 'signature', $context, 'the signature itself is never logged' );
	}

	public function test_a_rate_limited_delivery_is_reported() {
		add_filter(
			'wpd_webhook_rate_limit',
			static function () {
				return 0;
			}
		);

		wpd_plugin()->webhook->receive( new WP_REST_Request( 'POST', '/wpd/v1/webhook' ) );

		$this->assertSame( 'webhook.rate_limited', $this->events[0][0] );
	}
}
