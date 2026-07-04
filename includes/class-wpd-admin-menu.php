<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers a single top-level "Dance" admin menu that acts as the parent
 * for the three plugin CPTs (Events, Locations, Series). Each CPT sets
 * show_in_menu = self::SLUG in its register_post_type args, which turns
 * them into submenus.
 *
 * WordPress adds a self-referencing first submenu whose slug equals the
 * top-level slug; we remove it so the sidebar shows only the three CPT
 * entries. Clicking the top-level "Dance" label loads a stub page whose
 * callback redirects to the Events list — that mirrors the WP default of
 * a top-level menu opening its first submenu.
 */
class WPD_Admin_Menu {

	const SLUG = 'wpd_dance';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register' ), 9 );
	}

	public function register() {
		add_menu_page(
			__( 'Dance', 'wp-dansal' ),
			__( 'Dance', 'wp-dansal' ),
			'edit_posts',
			self::SLUG,
			array( $this, 'redirect_to_events' ),
			'dashicons-calendar-alt',
			25
		);
		remove_submenu_page( self::SLUG, self::SLUG );
	}

	public function redirect_to_events() {
		wp_safe_redirect( admin_url( 'edit.php?post_type=' . WPD_CPT_Event::POST_TYPE ) );
		exit;
	}
}
