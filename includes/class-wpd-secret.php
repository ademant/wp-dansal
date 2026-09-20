<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Encryption of the stored dansal API key (#123).
 *
 * Current format `s1:` + base64( nonce . ciphertext ) is libsodium's
 * authenticated `secretbox` (XSalsa20-Poly1305) — a tampered or truncated
 * value fails to decrypt instead of yielding garbage. The key is derived from
 * the site's `auth` salts via `wp_salt()`, domain-separated from anything else
 * that hashes them. WordPress ships sodium_compat, so this works whether or not
 * the PHP sodium extension is installed.
 *
 * The previous format (unauthenticated AES-256-CBC, key = sha256(AUTH_KEY .
 * AUTH_SALT), stored as bare base64( iv . ciphertext )) is still read, so an
 * upgrade never locks anyone out; WPD_Settings::maybe_upgrade_key_encryption()
 * rewrites it in the new format once. Kept free of hooks and of any
 * dependency on the other plugin classes so uninstall.php can use it too.
 */
final class WPD_Secret {

	const CURRENT_PREFIX = 's1:';

	private static function key() {
		return sodium_crypto_generichash( 'wp-dansal/api-key/v1|' . wp_salt( 'auth' ), '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}

	/**
	 * @param string $plaintext Secret to protect.
	 * @return string|false `s1:`-prefixed blob, or false if it can't be encrypted here.
	 */
	public static function encrypt( $plaintext ) {
		$plaintext = (string) $plaintext;
		if ( '' === $plaintext || ! function_exists( 'sodium_crypto_secretbox' ) ) {
			return false;
		}
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$box   = sodium_crypto_secretbox( $plaintext, $nonce, self::key() );
		return self::CURRENT_PREFIX . base64_encode( $nonce . $box ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- binary ciphertext for storage, not obfuscation.
	}

	/** True for a value already in the current (libsodium) format. */
	public static function is_current( $blob ) {
		return 0 === strpos( (string) $blob, self::CURRENT_PREFIX );
	}

	/**
	 * @param string $blob Stored value, current or legacy format.
	 * @return string|false Plaintext, or false if it can't be decrypted (wrong
	 *                      salts, tampered, unsupported).
	 */
	public static function decrypt( $blob ) {
		$blob = (string) $blob;
		if ( '' === $blob ) {
			return false;
		}
		return self::is_current( $blob )
			? self::decrypt_current( substr( $blob, strlen( self::CURRENT_PREFIX ) ) )
			: self::decrypt_legacy( $blob );
	}

	private static function decrypt_current( $encoded ) {
		if ( ! function_exists( 'sodium_crypto_secretbox_open' ) ) {
			return false;
		}
		$raw = base64_decode( $encoded, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- binary ciphertext from storage, not obfuscation.
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return false;
		}
		$nonce = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$box   = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$plain = sodium_crypto_secretbox_open( $box, $nonce, self::key() );
		return false === $plain || '' === $plain ? false : $plain;
	}

	/**
	 * Pre-0.16 format. Decrypt only — nothing writes it anymore.
	 */
	private static function decrypt_legacy( $blob ) {
		if ( ! function_exists( 'openssl_decrypt' ) ) {
			return false;
		}
		$data = base64_decode( $blob, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- binary ciphertext from storage, not obfuscation.
		if ( false === $data || strlen( $data ) <= 16 ) {
			return false;
		}
		$key_material = defined( 'AUTH_KEY' ) && defined( 'AUTH_SALT' ) ? AUTH_KEY . AUTH_SALT : '';
		if ( '' === $key_material ) {
			return false;
		}
		$key   = substr( hash( 'sha256', $key_material, true ), 0, 32 );
		$plain = openssl_decrypt( substr( $data, 16 ), 'AES-256-CBC', $key, OPENSSL_RAW_DATA, substr( $data, 0, 16 ) );
		return false === $plain || '' === $plain ? false : $plain;
	}
}
