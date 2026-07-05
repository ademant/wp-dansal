<?php
/**
 * PHPUnit bootstrap. Wires up wp-phpunit so tests run against a real
 * WordPress test install (from wp-phpunit) with wp-dansal loaded as a
 * mu-plugin equivalent.
 *
 * Env vars:
 *   WP_TESTS_DIR — path to the wp-phpunit test suite. Defaults to
 *   vendor/wp-phpunit/wp-phpunit (installed via composer require-dev).
 */

$wpd_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $wpd_tests_dir ) {
	$wpd_tests_dir = __DIR__ . '/../vendor/wp-phpunit/wp-phpunit';
}

if ( ! file_exists( $wpd_tests_dir . '/includes/functions.php' ) ) {
	fwrite( STDERR, "wp-phpunit not found at {$wpd_tests_dir}. Run `composer install` and/or set WP_TESTS_DIR.\n" );
	exit( 1 );
}

require_once $wpd_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	function () {
		require dirname( __DIR__ ) . '/wp-dansal.php';
	}
);

require $wpd_tests_dir . '/includes/bootstrap.php';
