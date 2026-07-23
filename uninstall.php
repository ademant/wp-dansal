<?php
/**
 * Fires when the plugin is deleted through the WordPress admin (Plugins →
 * Delete), not on plain deactivation. Removes plugin-owned options and
 * transients. User content (dansal_event / dansal_location / dansal_series
 * posts and their meta) is left alone by default — deleting user data on
 * uninstall is surprising and irreversible. Power users can opt in via the
 * wpd_uninstall_delete_content filter.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Best-effort self-revoke of the publisher API key on the dansal side, so
// deleting the plugin doesn't leave a live key on the server. Runs before
// we drop wpd_settings because we need base_url + the decrypted key. Silent
// on any failure (network, older dansal without the route, missing openssl)
// so uninstall never blocks on a broken/offline dansal.
$wpd_opts = get_option( 'wpd_settings', array() );
if ( is_array( $wpd_opts ) && ! empty( $wpd_opts['base_url'] ) ) {
	$wpd_key = '';
	if ( ! empty( $wpd_opts['api_key_encrypted'] ) && function_exists( 'openssl_decrypt' ) && defined( 'AUTH_KEY' ) && defined( 'AUTH_SALT' ) ) {
		$wpd_blob = base64_decode( (string) $wpd_opts['api_key_encrypted'], true );
		if ( false !== $wpd_blob && strlen( $wpd_blob ) > 16 ) {
			$wpd_iv     = substr( $wpd_blob, 0, 16 );
			$wpd_cipher = substr( $wpd_blob, 16 );
			$wpd_km     = substr( hash( 'sha256', AUTH_KEY . AUTH_SALT, true ), 0, 32 );
			$wpd_plain  = openssl_decrypt( $wpd_cipher, 'AES-256-CBC', $wpd_km, OPENSSL_RAW_DATA, $wpd_iv );
			if ( false !== $wpd_plain && '' !== $wpd_plain ) {
				$wpd_key = $wpd_plain;
			}
		}
	}
	if ( '' === $wpd_key && ! empty( $wpd_opts['api_key'] ) && '***' !== $wpd_opts['api_key'] ) {
		$wpd_key = (string) $wpd_opts['api_key'];
	}
	if ( '' !== $wpd_key ) {
		$wpd_url = untrailingslashit( (string) $wpd_opts['base_url'] ) . '/api/v1/apikeys/current';
		wp_remote_request(
			$wpd_url,
			array(
				'method'  => 'DELETE',
				'timeout' => 5,
				'headers' => array(
					'Authorization' => 'Bearer ' . $wpd_key,
					'Accept'        => 'application/json',
				),
			)
		);
		// Response ignored — best-effort. A 404/405 from older dansal, a
		// transport failure, or a stale/dead key all leave the plugin's
		// local state deletion below untouched.
	}
}

// Options.
delete_option( 'wpd_settings' );

// Named transients we know statically.
$wpd_named_transients = array(
	'wpd_dansal_session_token', // WPD_Api_Client::TOKEN_TRANSIENT
	'wpd_apikey_renew_lock',    // WPD_Api_Client::RENEW_LOCK
	'wpd_event_pull_lock',
	'wpd_location_pull_lock',
	'wpd_series_pull_lock',
	'wpd_event_refresh_global',
	'wpd_location_refresh_global',
);
wp_clear_scheduled_hook( 'wpd_apikey_renew_check' );
foreach ( $wpd_named_transients as $wpd_t ) {
	delete_transient( $wpd_t );
}

// Prefixed transients (per-post refresh locks, per-user admin notices,
// version-salted vocabulary caches) — sweep by option_name from the
// options table since WP has no delete_transient_by_prefix().
global $wpdb;
$wpd_prefixes = array(
	'wpd_event_refresh_',
	'wpd_event_pending_pull_',
	'wpd_location_refresh_',
	'wpd_admin_notices_',
	'wpd_tags_vocab_',
	'wpd_dances_vocab_',
	'wpd_vocab_',
);
foreach ( $wpd_prefixes as $wpd_prefix ) {
	$wpd_like = $wpdb->esc_like( '_transient_' . $wpd_prefix ) . '%';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $wpd_like, $wpdb->esc_like( '_transient_timeout_' . $wpd_prefix ) . '%' ) );
}

// Opt-in content wipe. Off by default; a site owner who really wants
// their events/locations/series gone can add:
//   add_filter( 'wpd_uninstall_delete_content', '__return_true' );
if ( apply_filters( 'wpd_uninstall_delete_content', false ) ) {
	foreach ( array( 'dansal_event', 'dansal_location', 'dansal_series', 'dansal_musician', 'dansal_instructor' ) as $wpd_pt ) {
		$wpd_ids = get_posts(
			array(
				'post_type'      => $wpd_pt,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);
		foreach ( $wpd_ids as $wpd_id ) {
			wp_delete_post( $wpd_id, true );
		}
	}
}
