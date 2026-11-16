<?php
/**
 * #151: the plugin's own REST responses carry the hardening headers, other
 * routes don't, and the set can be overridden with `wpd_security_headers`.
 */

class SecurityHeadersTest extends WP_UnitTestCase {

	public function tear_down(): void {
		remove_all_filters( 'wpd_security_headers' );
		parent::tear_down();
	}

	private function mini_calendar_response() {
		$request = new WP_REST_Request( 'GET', '/wpd/v1/mini-calendar' );
		$request->set_query_params(
			array(
				'month' => 3,
				'year'  => 2027,
			)
		);
		return rest_do_request( $request );
	}

	public function test_plugin_rest_responses_carry_the_hardening_headers() {
		$headers = $this->mini_calendar_response()->get_headers();

		$this->assertSame( 'nosniff', $headers['X-Content-Type-Options'] );
		$this->assertSame( 'SAMEORIGIN', $headers['X-Frame-Options'] );
		$this->assertSame( 'strict-origin-when-cross-origin', $headers['Referrer-Policy'] );
	}

	public function test_other_rest_routes_are_left_alone() {
		$headers = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/posts' ) )->get_headers();

		$this->assertArrayNotHasKey( 'X-Frame-Options', $headers );
	}

	public function test_the_header_set_can_be_overridden_by_the_site() {
		add_filter( 'wpd_security_headers', '__return_empty_array' );

		$this->assertArrayNotHasKey( 'X-Frame-Options', $this->mini_calendar_response()->get_headers() );
	}
}
