<?php
/**
 * #158: every wpd/v1 REST route must declare a permission_callback, and the
 * only ones open to anonymous callers are the four that are public by design
 * (the mini-calendar, [dansal_nearby] refresh, map tiles and the signed
 * dansal webhook). Pinning that list means a new public route has to be added
 * here deliberately.
 */

class RoutePermissionsTest extends WP_UnitTestCase {

	const PUBLIC_ROUTES = array(
		'/wpd/v1/mini-calendar',
		'/wpd/v1/nearby',
		'/wpd/v1/tiles/(?P<z>\d+)/(?P<x>\d+)/(?P<y>\d+)',
		'/wpd/v1/webhook',
	);

	public function test_every_route_declares_a_permission_callback() {
		foreach ( rest_get_server()->get_routes( 'wpd/v1' ) as $route => $endpoints ) {
			foreach ( $endpoints as $endpoint ) {
				$this->assertArrayHasKey( 'permission_callback', $endpoint, "$route has a permission_callback" );
				$this->assertNotNull( $endpoint['permission_callback'], "$route permission_callback is not null" );
			}
		}
	}

	public function test_only_the_intentionally_public_routes_are_open_to_anonymous_callers() {
		$open = array();
		foreach ( rest_get_server()->get_routes( 'wpd/v1' ) as $route => $endpoints ) {
			foreach ( $endpoints as $endpoint ) {
				if ( '__return_true' === $endpoint['permission_callback'] ) {
					$open[ $route ] = true;
				}
			}
		}

		$expected = self::PUBLIC_ROUTES;
		sort( $expected );
		$actual = array_keys( $open );
		sort( $actual );
		$this->assertSame( $expected, $actual );
	}
}
