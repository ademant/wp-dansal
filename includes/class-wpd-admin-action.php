<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thin wrapper around admin_post_$slug: verifies the nonce and capability,
 * then delegates to the handler. Pair with WPD_Admin_Action::url() for the
 * matching nonced URL.
 *
 * Used by #9 (clone event), #11 (new-from-template) and #12 (assign/add-date)
 * so their entry points — row actions, publish-box buttons, sidebar menu
 * items — all share one code path for CSRF + cap gating.
 */
class WPD_Admin_Action {

	/**
	 * Register a handler for admin_post_$slug. The wrapper verifies the
	 * nonce and capability; the handler is responsible for redirecting
	 * (e.g. via wp_safe_redirect) or wp_die()ing.
	 *
	 * @param string   $slug       Action slug, e.g. "wpd_clone_event".
	 * @param string   $capability Capability the caller must have.
	 * @param callable $handler    Called with no args after gate checks pass.
	 */
	public static function register( $slug, $capability, callable $handler ) {
		add_action(
			'admin_post_' . $slug,
			function () use ( $slug, $capability, $handler ) {
				check_admin_referer( $slug );
				if ( ! current_user_can( $capability ) ) {
					wp_die( esc_html__( 'Insufficient permissions.', 'wp-dansal' ), 403 );
				}
				call_user_func( $handler );
			}
		);
	}

	/**
	 * Compose the nonced admin URL for a registered action.
	 *
	 * @param string $slug Action slug matching a register() call.
	 * @param array  $args Extra query args (e.g. ['post' => 42]).
	 * @return string Nonced URL.
	 */
	public static function url( $slug, array $args = array() ) {
		$args['action'] = $slug;
		return wp_nonce_url( add_query_arg( $args, admin_url( 'admin-post.php' ) ), $slug );
	}
}
