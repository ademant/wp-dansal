<?php
/**
 * #151: the plugin's own REST responses carry the hardening headers, other
 * routes don't, and the set can be overridden with `wpd_security_headers`.
 *
 * The header callback is exercised through the `rest_post_dispatch` filter
 * directly, rather than a full rest_do_request(), so the assertion depends
 * only on the plugin's callback and not on core dispatch details.
 */

class SecurityHeadersTest extends WP_UnitTestCase {

	public function tear_down(): void {
		remove_all_filters( 'wpd_security_headers' );
		parent::tear_down();
	}

	/**
	 * Runs a bare response for $route through the post-dispatch filter, the
	 * same hook WP_REST_Server applies to every REST response.
	 */
	private function filtered_headers_for( $route ) {
		$request = new WP_REST_Request( 'GET', $route );
		$request->set_query_params(
			array(
				'month' => 3,
				'year'  => 2027,
			)
		);
		$response = apply_filters( 'rest_post_dispatch', new WP_REST_Response( array() ), rest_get_server(), $request );
		return $response->get_headers();
	}

	public function test_plugin_rest_responses_carry_the_hardening_headers() {
		$headers = $this->filtered_headers_for( '/wpd/v1/mini-calendar' );

		$this->assertSame( 'nosniff', $headers['X-Content-Type-Options'] );
		$this->assertSame( 'SAMEORIGIN', $headers['X-Frame-Options'] );
		$this->assertSame( 'strict-origin-when-cross-origin', $headers['Referrer-Policy'] );
	}

	public function test_other_rest_routes_are_left_alone() {
		$this->assertArrayNotHasKey( 'X-Frame-Options', $this->filtered_headers_for( '/wp/v2/posts' ) );
	}

	public function test_the_header_set_can_be_overridden_by_the_site() {
		add_filter( 'wpd_security_headers', '__return_empty_array' );

		$this->assertArrayNotHasKey( 'X-Frame-Options', $this->filtered_headers_for( '/wpd/v1/mini-calendar' ) );
	}
}
