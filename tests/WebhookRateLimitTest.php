<?php
/**
 * #152: the webhook receiver rate-limits per caller address before it verifies
 * any signature, so a flood of unsigned requests stops at a cheap check. The
 * forwarded address is trusted only from a loopback peer, so a remote caller
 * can't spoof its way into a fresh bucket.
 */

class WebhookRateLimitTest extends WP_UnitTestCase {

	private $server_before;

	public function set_up(): void {
		parent::set_up();
		$this->server_before = isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : null;
	}

	public function tear_down(): void {
		if ( null === $this->server_before ) {
			unset( $_SERVER['REMOTE_ADDR'] );
		} else {
			$_SERVER['REMOTE_ADDR'] = $this->server_before;
		}
		unset( $_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['HTTP_X_REAL_IP'] );
		remove_all_filters( 'wpd_webhook_rate_limit' );
		parent::tear_down();
	}

	private function deliver() {
		$request = new WP_REST_Request( 'POST', '/wpd/v1/webhook' );
		return wpd_plugin()->webhook->receive( $request );
	}

	private function limit_to( $n ) {
		add_filter(
			'wpd_webhook_rate_limit',
			static function () use ( $n ) {
				return $n;
			}
		);
	}

	public function test_requests_over_the_limit_are_refused_before_signature_checks() {
		$this->limit_to( 2 );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';

		$this->assertSame( 'wpd_webhook_missing_headers', $this->deliver()->get_error_code() );
		$this->assertSame( 'wpd_webhook_missing_headers', $this->deliver()->get_error_code() );

		$third = $this->deliver();
		$this->assertSame( 'wpd_webhook_rate_limited', $third->get_error_code() );
		$this->assertSame( 429, $third->get_error_data()['status'] );
	}

	public function test_a_spoofed_forwarded_address_from_a_remote_peer_does_not_reset_the_bucket() {
		$this->limit_to( 1 );
		$_SERVER['REMOTE_ADDR']         = '203.0.113.9';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.7';
		$this->deliver();

		$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.8';
		$this->assertSame( 'wpd_webhook_rate_limited', $this->deliver()->get_error_code() );
	}

	public function test_a_loopback_proxy_hands_the_limit_to_the_real_client_address() {
		$this->limit_to( 1 );
		$_SERVER['REMOTE_ADDR']   = '127.0.0.1';
		$_SERVER['HTTP_X_REAL_IP'] = '198.51.100.7';
		$this->deliver();

		$_SERVER['HTTP_X_REAL_IP'] = '198.51.100.8';
		$this->assertSame( 'wpd_webhook_missing_headers', $this->deliver()->get_error_code(), 'a different client gets its own bucket' );
	}
}
