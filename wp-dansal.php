<?php
/**
 * Plugin Name: WP Dansal
 * Plugin URI: https://github.com/ademant/wp-dansal
 * Description: Manage dance events and locations in WordPress, backed by a dansal server (https://github.com/ademant/dansal) as the storage/publishing backend.
 * Version: 0.1.3
 * Author: ademant
 * License: GPL-2.0-or-later
 * Text Domain: wp-dansal
 * Requires PHP: 7.4
 * Requires at least: 6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WPD_VERSION', '0.1.3' );
define( 'WPD_PLUGIN_FILE', __FILE__ );
define( 'WPD_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPD_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Cache-buster for enqueued assets. In WP_DEBUG we return the file mtime
 * so edits to CSS/JS show up without bumping WPD_VERSION; in production
 * we return WPD_VERSION so one release = one cache generation.
 *
 * @param string $rel Path relative to the plugin directory, e.g. 'assets/js/foo.js'.
 */
function wpd_asset_ver( $rel ) {
	if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		$abs = WPD_PLUGIN_DIR . ltrim( $rel, '/' );
		if ( file_exists( $abs ) ) {
			return (string) filemtime( $abs );
		}
	}
	return WPD_VERSION;
}

require_once WPD_PLUGIN_DIR . 'includes/class-wpd-vocab.php';
require_once WPD_PLUGIN_DIR . 'includes/class-wpd-settings.php';
require_once WPD_PLUGIN_DIR . 'includes/class-wpd-api-client.php';
require_once WPD_PLUGIN_DIR . 'includes/class-wpd-nominatim.php';
require_once WPD_PLUGIN_DIR . 'includes/class-wpd-admin-menu.php';
require_once WPD_PLUGIN_DIR . 'includes/class-wpd-cpt-location.php';
require_once WPD_PLUGIN_DIR . 'includes/class-wpd-event-fields.php';
require_once WPD_PLUGIN_DIR . 'includes/class-wpd-event-prefill.php';
require_once WPD_PLUGIN_DIR . 'includes/class-wpd-admin-action.php';
require_once WPD_PLUGIN_DIR . 'includes/class-wpd-event-draft.php';
require_once WPD_PLUGIN_DIR . 'includes/class-wpd-preset-menu.php';
require_once WPD_PLUGIN_DIR . 'includes/class-wpd-preset-buttons.php';
require_once WPD_PLUGIN_DIR . 'includes/class-wpd-datetime-hint.php';
require_once WPD_PLUGIN_DIR . 'includes/class-wpd-cpt-event.php';
require_once WPD_PLUGIN_DIR . 'includes/class-wpd-cpt-series.php';
require_once WPD_PLUGIN_DIR . 'includes/class-wpd-frontend.php';
require_once WPD_PLUGIN_DIR . 'includes/class-wpd-widget-mini-calendar.php';

/**
 * Central bootstrap. Everything else is wired up through the classes below,
 * each hooking its own actions/filters in its constructor.
 */
final class WPD_Plugin {

	private static $instance = null;

	public $settings;
	public $api;
	public $nominatim;
	public $admin_menu;
	public $cpt_location;
	public $event_fields;
	public $cpt_event;
	public $cpt_series;
	public $frontend;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->settings     = new WPD_Settings();
		$this->api          = new WPD_Api_Client( $this->settings );
		$this->nominatim    = new WPD_Nominatim();
		$this->admin_menu   = new WPD_Admin_Menu();
		$this->cpt_location = new WPD_CPT_Location( $this->api, $this->nominatim, $this->settings );
		$this->event_fields = new WPD_Event_Fields( $this->api );
		$this->cpt_event    = new WPD_CPT_Event( $this->api, $this->settings, $this->event_fields );
		$this->cpt_series   = new WPD_CPT_Series( $this->api, $this->settings, $this->event_fields );
		$this->frontend     = new WPD_Frontend( $this->settings );

		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
	}

	public function load_textdomain() {
		load_plugin_textdomain( 'wp-dansal', false, dirname( plugin_basename( WPD_PLUGIN_FILE ) ) . '/languages' );
	}
}

function wpd_plugin() {
	return WPD_Plugin::instance();
}
wpd_plugin();

/**
 * Activation: register CPTs (via the classes' own hooks having already run
 * on this same request) then flush rewrite rules.
 */
function wpd_activate() {
	// Post types are registered on 'init' by the CPT classes; make sure that
	// has happened before we flush rewrite rules.
	do_action( 'init' );
	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'wpd_activate' );

function wpd_deactivate() {
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'wpd_deactivate' );
