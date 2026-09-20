<?php
/**
 * API-key encryption at rest (#123): authenticated libsodium secretbox, with
 * the old unauthenticated AES-CBC format still readable and upgraded once.
 */

class SecretTest extends WP_UnitTestCase {

	/**
	 * The pre-0.16 format keys off AUTH_KEY/AUTH_SALT, so the legacy-format
	 * tests need a harness config that defines them like a real wp-config.php
	 * does (CI's does; see .github/workflows/ci.yml). They can't be defined
	 * from inside a test: wp_salt() has already cached which salt constants
	 * exist by then.
	 */
	private function require_salts() {
		if ( ! defined( 'AUTH_KEY' ) || ! defined( 'AUTH_SALT' ) ) {
			$this->markTestSkipped( 'wp-tests-config.php does not define AUTH_KEY/AUTH_SALT.' );
		}
	}

	public function tear_down(): void {
		delete_option( 'wpd_settings' );
		parent::tear_down();
	}

	/** A blob exactly as pre-0.16 versions wrote it. */
	private function legacy_blob( $plaintext ) {
		$this->require_salts();
		$key    = substr( hash( 'sha256', AUTH_KEY . AUTH_SALT, true ), 0, 32 );
		$iv     = str_repeat( 'i', 16 );
		$cipher = openssl_encrypt( $plaintext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
		return base64_encode( $iv . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	public function test_round_trip_uses_the_current_format_with_a_fresh_nonce_each_time() {
		$a = WPD_Secret::encrypt( 'ak_secret' );
		$b = WPD_Secret::encrypt( 'ak_secret' );

		$this->assertTrue( WPD_Secret::is_current( $a ) );
		$this->assertStringStartsWith( 's1:', $a );
		$this->assertNotSame( $a, $b, 'a random nonce per encryption' );
		$this->assertSame( 'ak_secret', WPD_Secret::decrypt( $a ) );
		$this->assertStringNotContainsString( 'ak_secret', $a );
	}

	public function test_a_tampered_value_is_rejected_not_decrypted_to_garbage() {
		$blob = WPD_Secret::encrypt( 'ak_secret' );
		$raw  = base64_decode( substr( $blob, 3 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$raw[ strlen( $raw ) - 1 ] = $raw[ strlen( $raw ) - 1 ] ^ "\x01";
		$tampered                  = 's1:' . base64_encode( $raw ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode

		$this->assertFalse( WPD_Secret::decrypt( $tampered ) );
	}

	public function test_truncated_and_garbage_values_are_rejected() {
		$this->assertFalse( WPD_Secret::decrypt( 's1:' ) );
		$this->assertFalse( WPD_Secret::decrypt( 's1:AAAA' ) );
		$this->assertFalse( WPD_Secret::decrypt( 's1:not base64 !!' ) );
		$this->assertFalse( WPD_Secret::decrypt( '' ) );
		$this->assertFalse( WPD_Secret::decrypt( 'garbage' ) );
	}

	public function test_empty_plaintext_is_not_encrypted() {
		$this->assertFalse( WPD_Secret::encrypt( '' ) );
	}

	public function test_legacy_blobs_are_still_readable() {
		$legacy = $this->legacy_blob( 'ak_old_key' );

		$this->assertFalse( WPD_Secret::is_current( $legacy ) );
		$this->assertSame( 'ak_old_key', WPD_Secret::decrypt( $legacy ) );
	}

	public function test_new_keys_are_stored_in_the_current_format_and_read_back() {
		wpd_plugin()->settings->record_apikey_renewed( 'ak_new', null );

		$stored = get_option( 'wpd_settings' );
		$this->assertStringStartsWith( 's1:', $stored['api_key_encrypted'] );
		$this->assertSame( '***', $stored['api_key'], 'no plaintext copy is kept' );
		$this->assertSame( 'ak_new', wpd_plugin()->settings->get_api_key() );
	}

	public function test_a_legacy_key_is_upgraded_once_and_still_works() {
		update_option(
			'wpd_settings',
			array(
				'base_url'          => 'https://dansal.example',
				'api_key'           => '***',
				'api_key_encrypted' => $this->legacy_blob( 'ak_old_key' ),
			)
		);
		$this->assertSame( 'ak_old_key', wpd_plugin()->settings->get_api_key(), 'readable before the upgrade' );

		wpd_plugin()->settings->maybe_upgrade_key_encryption();

		$stored = get_option( 'wpd_settings' );
		$this->assertStringStartsWith( 's1:', $stored['api_key_encrypted'] );
		$this->assertSame( 'ak_old_key', wpd_plugin()->settings->get_api_key() );
		$this->assertSame( 'https://dansal.example', $stored['base_url'], 'other settings untouched' );

		// Idempotent: a second run must not rewrite (and re-nonce) the value.
		wpd_plugin()->settings->maybe_upgrade_key_encryption();
		$this->assertSame( $stored['api_key_encrypted'], get_option( 'wpd_settings' )['api_key_encrypted'] );
	}

	public function test_an_unreadable_key_is_left_untouched_by_the_upgrade() {
		update_option(
			'wpd_settings',
			array(
				'base_url'          => 'https://dansal.example',
				'api_key'           => '***',
				'api_key_encrypted' => base64_encode( str_repeat( 'x', 48 ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			)
		);
		$before = get_option( 'wpd_settings' );

		wpd_plugin()->settings->maybe_upgrade_key_encryption();

		$this->assertSame( $before, get_option( 'wpd_settings' ) );
	}

	public function test_the_upgrade_runs_on_init() {
		$this->assertNotFalse( has_action( 'init', array( wpd_plugin()->settings, 'maybe_upgrade_key_encryption' ) ) );
	}
}
