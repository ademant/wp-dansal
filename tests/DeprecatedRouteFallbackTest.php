<?php
/**
 * #146: wp-dansal moved three calls to dansal's canonical routes
 * (PUT .../locations/{id}/organizations/{org_id}, and the event image
 * upload/delete under /api/v1/events/{id}/image) with a fallback to the
 * old, now-deprecated route when dansal answers 404/405 — i.e. a dansal
 * instance that predates those canonical routes. These pin that fallback,
 * since nothing else in the suite exercises WPD_CPT_Location::assign_org()
 * or WPD_CPT_Event::push_image()'s HTTP calls directly.
 */

class DeprecatedRouteFallbackTest extends WP_UnitTestCase {

	/** @var array Captured [method, url] of every intercepted request. */
	private $requests = array();

	public function set_up(): void {
		parent::set_up();
		update_option(
			'wpd_settings',
			array(
				'base_url' => 'https://dansal.example',
				'api_key'  => 'ak_test',
				'org_id'   => 7,
			)
		);
		set_transient( WPD_Api_Client::TOKEN_TRANSIENT, 'session-token', HOUR_IN_SECONDS );
	}

	public function tear_down(): void {
		delete_option( 'wpd_settings' );
		delete_transient( WPD_Api_Client::TOKEN_TRANSIENT );
		remove_all_filters( 'pre_http_request' );
		$this->requests = array();
		parent::tear_down();
	}

	/**
	 * Intercept dansal. $routes maps "METHOD /path" to an array body (200) or
	 * an int (that status, empty error body).
	 */
	private function mock_dansal( array $routes ) {
		$this->requests = array();
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( $routes ) {
				$method           = isset( $args['method'] ) ? $args['method'] : 'GET';
				$path             = (string) wp_parse_url( $url, PHP_URL_PATH );
				$this->requests[] = $method . ' ' . $path;
				$key              = $method . ' ' . $path;
				if ( ! array_key_exists( $key, $routes ) ) {
					return array(
						'response' => array( 'code' => 404 ),
						'body'     => '{"error":"not found"}',
					);
				}
				$r = $routes[ $key ];
				if ( is_int( $r ) ) {
					return array(
						'response' => array( 'code' => $r ),
						'body'     => '{"error":"simulated"}',
					);
				}
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( $r ),
				);
			},
			10,
			3
		);
	}

	private function call( $object, $method, array $args = array() ) {
		$ref = new ReflectionMethod( $object, $method );
		$ref->setAccessible( true );
		return $ref->invokeArgs( $object, $args );
	}

	// ---- WPD_CPT_Location::assign_org() -----------------------------------

	public function test_assign_org_uses_the_canonical_put_route() {
		$this->mock_dansal( array( 'PUT /api/v1/locations/42/organizations/7' => array() ) );

		$result = $this->call( wpd_plugin()->cpt_location, 'assign_org', array( 42, 7 ) );

		$this->assertTrue( $result );
		$this->assertSame( array( 'PUT /api/v1/locations/42/organizations/7' ), $this->requests );
	}

	public function test_assign_org_falls_back_to_the_deprecated_post_route_on_404() {
		$this->mock_dansal(
			array(
				'PUT /api/v1/locations/42/organizations/7' => 404,
				'POST /api/v1/locations/42/assign-org'     => array(),
			)
		);

		$result = $this->call( wpd_plugin()->cpt_location, 'assign_org', array( 42, 7 ) );

		$this->assertTrue( $result );
		$this->assertSame(
			array( 'PUT /api/v1/locations/42/organizations/7', 'POST /api/v1/locations/42/assign-org' ),
			$this->requests
		);
	}

	public function test_assign_org_falls_back_on_405_too() {
		$this->mock_dansal(
			array(
				'PUT /api/v1/locations/42/organizations/7' => 405,
				'POST /api/v1/locations/42/assign-org'     => array(),
			)
		);

		$this->assertTrue( $this->call( wpd_plugin()->cpt_location, 'assign_org', array( 42, 7 ) ) );
	}

	public function test_assign_org_does_not_fall_back_on_an_unrelated_error() {
		$this->mock_dansal( array( 'PUT /api/v1/locations/42/organizations/7' => 500 ) );

		$result = $this->call( wpd_plugin()->cpt_location, 'assign_org', array( 42, 7 ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( array( 'PUT /api/v1/locations/42/organizations/7' ), $this->requests, 'a real failure must not retry the deprecated route' );
	}

	// ---- WPD_CPT_Event::push_image() --------------------------------------

	private function make_event_with_image( $dansal_id = 501 ) {
		$id = self::factory()->post->create( array( 'post_type' => WPD_CPT_Event::POST_TYPE ) );
		update_post_meta( $id, WPD_CPT_Event::META_DANSAL_ID, $dansal_id );
		$attachment_id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg', $id );
		set_post_thumbnail( $id, $attachment_id );
		update_post_meta( $id, WPD_CPT_Event::META_LAST_SYNCED_IMAGE, 0 );
		return $id;
	}

	public function test_push_image_upload_uses_the_canonical_route() {
		$event_id = $this->make_event_with_image( 501 );
		$this->mock_dansal( array( 'POST /api/v1/events/501/image' => array() ) );

		$this->call( wpd_plugin()->cpt_event, 'push_image', array( $event_id, 501 ) );

		$this->assertSame( array( 'POST /api/v1/events/501/image' ), $this->requests );
	}

	public function test_push_image_upload_falls_back_to_the_deprecated_route_on_404() {
		$event_id = $this->make_event_with_image( 502 );
		$this->mock_dansal(
			array(
				'POST /api/v1/events/502/image' => 404,
				'POST /api/v1/images/502'        => array(),
			)
		);

		$this->call( wpd_plugin()->cpt_event, 'push_image', array( $event_id, 502 ) );

		$this->assertSame( array( 'POST /api/v1/events/502/image', 'POST /api/v1/images/502' ), $this->requests );
		$this->assertSame( array(), get_transient( 'wpd_admin_notices_' . get_current_user_id() ), 'the fallback succeeding is not a failure' );
	}

	public function test_push_image_delete_falls_back_to_the_deprecated_route_on_404() {
		$event_id = self::factory()->post->create( array( 'post_type' => WPD_CPT_Event::POST_TYPE ) );
		update_post_meta( $event_id, WPD_CPT_Event::META_DANSAL_ID, 503 );
		update_post_meta( $event_id, WPD_CPT_Event::META_LAST_SYNCED_IMAGE, 99 ); // thumbnail was removed since
		$this->mock_dansal(
			array(
				'DELETE /api/v1/events/503/image' => 405,
				'DELETE /api/v1/images/503'       => array(),
			)
		);

		$this->call( wpd_plugin()->cpt_event, 'push_image', array( $event_id, 503 ) );

		$this->assertSame( array( 'DELETE /api/v1/events/503/image', 'DELETE /api/v1/images/503' ), $this->requests );
		$this->assertSame( '0', get_post_meta( $event_id, WPD_CPT_Event::META_LAST_SYNCED_IMAGE, true ) );
	}
}
