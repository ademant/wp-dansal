<?php
/**
 * Nominatim rate limiting and result caching (#133).
 *
 * OpenStreetMap's Nominatim usage policy caps outbound requests at 1/second
 * *per site* — every request shares this server's one IP regardless of which
 * WP user triggered it, so the throttle in WPD_Nominatim::throttle() has to
 * be site-wide, not per-user. These tests run with the interval filtered
 * down to something small but still measurable, so they stay fast without
 * turning the throttle into a no-op.
 */

class NominatimThrottleTest extends WP_UnitTestCase {

	/** @var array Captured request URLs. */
	private $requests = array();

	public function set_up(): void {
		parent::set_up();
		update_option( 'wpd_settings', array( 'nominatim_email' => 'test@example.org' ) );
	}

	public function tear_down(): void {
		delete_option( 'wpd_settings' );
		delete_transient( WPD_Nominatim::THROTTLE_TRANSIENT );
		remove_all_filters( 'pre_http_request' );
		remove_all_filters( 'wpd_nominatim_min_interval' );
		$this->requests = array();
		parent::tear_down();
	}

	private function mock_http( $body ) {
		$this->requests = array();
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( $body ) {
				$this->requests[] = $url;
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( $body ),
				);
			},
			10,
			3
		);
	}

	public function test_second_call_within_the_window_is_delayed() {
		add_filter( 'wpd_nominatim_min_interval', fn() => 0.15 );
		$this->mock_http(
            array(
				array(
					'display_name' => 'A',
					'lat' => '1',
					'lon' => '2',
				),
            )
        );

		wpd_plugin()->nominatim->search( 'first query' );
		$start = microtime( true );
		wpd_plugin()->nominatim->search( 'second query' ); // Different query — cache can't hide this one.
		$elapsed = microtime( true ) - $start;

		$this->assertGreaterThanOrEqual( 0.1, $elapsed, 'the second call waited out most of the window' );
		$this->assertCount( 2, $this->requests );
	}

	public function test_a_call_after_the_window_has_passed_is_not_delayed() {
		add_filter( 'wpd_nominatim_min_interval', fn() => 0.05 );
		$this->mock_http(
            array(
				array(
					'display_name' => 'A',
					'lat' => '1',
					'lon' => '2',
				),
            )
        );

		wpd_plugin()->nominatim->search( 'first query' );
		usleep( 80000 ); // Longer than the 0.05s window.
		$start = microtime( true );
		wpd_plugin()->nominatim->search( 'second query' );
		$elapsed = microtime( true ) - $start;

		$this->assertLessThan( 0.05, $elapsed );
	}

	public function test_interval_filtered_to_zero_disables_throttling() {
		add_filter( 'wpd_nominatim_min_interval', fn() => 0 );
		$this->mock_http(
            array(
				array(
					'display_name' => 'A',
					'lat' => '1',
					'lon' => '2',
				),
            )
        );

		$start = microtime( true );
		wpd_plugin()->nominatim->search( 'first query' );
		wpd_plugin()->nominatim->search( 'second query' );
		$elapsed = microtime( true ) - $start;

		$this->assertLessThan( 0.05, $elapsed );
	}

	public function test_identical_search_is_served_from_cache_without_a_second_request() {
		add_filter( 'wpd_nominatim_min_interval', fn() => 0 );
		$this->mock_http(
            array(
				array(
					'display_name' => 'Stollwerck, Köln',
					'lat' => '50.93',
					'lon' => '6.95',
				),
            )
        );

		$first  = wpd_plugin()->nominatim->search( 'Stollwerck' );
		$second = wpd_plugin()->nominatim->search( 'Stollwerck' );

		$this->assertCount( 1, $this->requests, 'the second identical search never hit the network' );
		$this->assertSame( $first, $second );
	}

	public function test_identical_reverse_is_served_from_cache_without_a_second_request() {
		add_filter( 'wpd_nominatim_min_interval', fn() => 0 );
		$this->mock_http(
            array(
				'display_name' => 'Stollwerck, Köln',
				'lat' => '50.93',
				'lon' => '6.95',
            )
        );

		wpd_plugin()->nominatim->reverse( 50.93, 6.95 );
		wpd_plugin()->nominatim->reverse( 50.93, 6.95 );

		$this->assertCount( 1, $this->requests );
	}

	public function test_different_queries_are_not_conflated_by_the_cache() {
		add_filter( 'wpd_nominatim_min_interval', fn() => 0 );
		$this->mock_http(
            array(
				array(
					'display_name' => 'A',
					'lat' => '1',
					'lon' => '2',
				),
            )
        );

		wpd_plugin()->nominatim->search( 'Stollwerck' );
		wpd_plugin()->nominatim->search( 'Kulturhaus' );

		$this->assertCount( 2, $this->requests );
	}

	public function test_a_failed_lookup_is_not_cached() {
		add_filter( 'wpd_nominatim_min_interval', fn() => 0 );
		$this->requests = array();
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				$this->requests[] = $url;
				return new WP_Error( 'wpd_test_down', 'simulated transport failure' );
			},
			10,
			3
		);

		$first  = wpd_plugin()->nominatim->search( 'Stollwerck' );
		$second = wpd_plugin()->nominatim->search( 'Stollwerck' );

		$this->assertWPError( $first );
		$this->assertWPError( $second );
		$this->assertCount( 2, $this->requests, 'a failure is retried, not cached' );
	}
}
