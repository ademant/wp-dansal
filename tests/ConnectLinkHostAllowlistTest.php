<?php
/**
 * #157: `wpd_connect_link_allowed_hosts` restricts which dansal hosts a connect
 * link may point at. Empty (the default) accepts any HTTPS host.
 */

class ConnectLinkHostAllowlistTest extends WP_UnitTestCase {

	const LINK = 'https://dansal.example.org/api/v1/invites/tok123/publisher';

	public function tear_down(): void {
		remove_all_filters( 'wpd_connect_link_allowed_hosts' );
		remove_all_filters( 'pre_http_request' );
		parent::tear_down();
	}

	private function redeem( $url ) {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$request = new WP_REST_Request( 'POST', '/wpd/v1/connection/link' );
		$request->set_param( 'connect_url', $url );
		return rest_do_request( $request )->as_error();
	}

	public function test_with_no_allowlist_any_https_host_passes_validation() {
		// Stop before any network call: validation passed if we reach HTTP.
		add_filter( 'pre_http_request', static fn() => new WP_Error( 'stopped_after_validation' ) );

		$error = $this->redeem( self::LINK );

		$this->assertNotSame( 'wpd_connect_host_not_allowed', $error ? $error->get_error_code() : '' );
	}

	public function test_a_host_off_the_allowlist_is_refused_before_any_request() {
		add_filter( 'wpd_connect_link_allowed_hosts', static fn() => array( 'dansal.balfolk.example' ) );
		add_filter( 'pre_http_request', static fn() => $this->fail( 'no HTTP request should be made' ) );

		$error = $this->redeem( self::LINK );

		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame( 'wpd_connect_host_not_allowed', $error->get_error_code() );
	}

	public function test_an_allowlisted_host_is_compared_case_insensitively() {
		add_filter( 'wpd_connect_link_allowed_hosts', static fn() => array( 'DANSAL.example.ORG' ) );
		add_filter( 'pre_http_request', static fn() => new WP_Error( 'stopped_after_validation' ) );

		$error = $this->redeem( self::LINK );

		$this->assertNotSame( 'wpd_connect_host_not_allowed', $error ? $error->get_error_code() : '' );
	}
}
