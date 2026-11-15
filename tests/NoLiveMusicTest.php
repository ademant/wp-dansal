<?php
/**
 * #149: dansal's no_live_music flag (#1612). wp-dansal syncs events with a
 * full-replace PUT, so the flag must always be in the payload, and it must
 * never be true alongside musicians, which dansal rejects with 400.
 */

class NoLiveMusicTest extends WP_UnitTestCase {

	private function call( $object, $method, array $args = array() ) {
		$ref = new ReflectionMethod( $object, $method );
		$ref->setAccessible( true );
		return $ref->invokeArgs( $object, $args );
	}

	private function event( array $meta ) {
		$id = self::factory()->post->create( array( 'post_type' => WPD_CPT_Event::POST_TYPE ) );
		foreach ( $meta as $key => $value ) {
			update_post_meta( $id, $key, $value );
		}
		return $id;
	}

	public function test_flag_is_always_sent_even_when_unset() {
		$payload = $this->call( wpd_plugin()->cpt_event, 'build_payload', array( $this->event( array() ) ) );
		$this->assertArrayHasKey( 'no_live_music', $payload );
		$this->assertFalse( $payload['no_live_music'] );
	}

	public function test_flag_is_sent_when_set_without_musicians() {
		$payload = $this->call( wpd_plugin()->cpt_event, 'build_payload', array( $this->event( array( '_wpd_no_live_music' => '1' ) ) ) );
		$this->assertTrue( $payload['no_live_music'] );
	}

	public function test_musicians_override_the_flag() {
		$id      = $this->event(
			array(
				'_wpd_no_live_music' => '1',
				'_wpd_musician_ids'  => '12,13',
			)
		);
		$payload = $this->call( wpd_plugin()->cpt_event, 'build_payload', array( $id ) );
		$this->assertFalse( $payload['no_live_music'] );
		$this->assertSame( array( 12, 13 ), $payload['musicians'] );
	}

	public function test_meta_is_registered_for_rest() {
		$keys = get_registered_meta_keys( 'post', WPD_CPT_Event::POST_TYPE );
		$this->assertArrayHasKey( '_wpd_no_live_music', $keys );
	}
}
