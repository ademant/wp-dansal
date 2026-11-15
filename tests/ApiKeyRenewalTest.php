<?php
/**
 * #148: a 400 from /apikeys/renew must not permanently disable renewal, so
 * an expiring key still gets probed by the daily cron and a lapsed one is
 * detected (api_key_dead) instead of failing silently.
 */

class ApiKeyRenewalTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		update_option(
			'wpd_settings',
			array(
				'base_url' => 'https://dansal.example',
				'api_key'  => 'ak_test',
				'org_id'   => 2,
			)
		);
	}

	public function tear_down(): void {
		delete_option( 'wpd_settings' );
		delete_transient( WPD_Api_Client::RENEW_LOCK );
		remove_all_filters( 'pre_http_request' );
		parent::tear_down();
	}

	private function mock_renew( $code, $body = '' ) {
		add_filter(
			'pre_http_request',
			static function () use ( $code, $body ) {
				return array(
					'response' => array( 'code' => $code ),
					'body'     => $body,
				);
			}
		);
	}

	private function settings() {
		return wpd_plugin()->settings;
	}

	private function api() {
		return wpd_plugin()->api;
	}

	public function test_a_400_does_not_disable_future_renewal_probing() {
		$this->mock_renew( 400, '{"error":"This key has no expiry; renewal is not applicable"}' );

		$result = $this->api()->renew_apikey();

		$this->assertWPError( $result );
		$this->assertSame( 'wpd_apikey_no_expiry', $result->get_error_code() );
		$this->assertFalse( $this->settings()->is_api_key_dead() );
		$this->assertTrue( $this->api()->apikey_should_renew(), 'a key with an unknown expiry must still be probed on the next cron run' );
	}

	public function test_a_401_marks_the_key_dead_so_the_reconnect_notice_fires() {
		$this->mock_renew( 401, '{"error":"This key has already expired"}' );

		$result = $this->api()->renew_apikey();

		$this->assertSame( 'wpd_apikey_dead', $result->get_error_code() );
		$this->assertTrue( $this->settings()->is_api_key_dead() );
	}

	public function test_a_dead_key_is_not_probed_again() {
		$this->settings()->mark_apikey_dead();

		$this->assertFalse( $this->api()->apikey_should_renew() );
	}

	public function test_a_200_stores_the_new_key_and_its_expiry() {
		$expires = gmdate( 'c', time() + 30 * DAY_IN_SECONDS );
		$this->mock_renew(
			200,
			wp_json_encode(
				array(
					'api_key'    => 'ak_new',
					'expires_at' => $expires,
				)
			)
		);

		$this->assertTrue( $this->api()->renew_apikey() );
		$this->assertSame( strtotime( $expires ), $this->settings()->get_api_key_expires_at() );
		$this->assertFalse( $this->api()->apikey_should_renew(), 'a freshly renewed key is outside the 7-day lead time' );
	}
}
