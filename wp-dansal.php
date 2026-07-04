<?php
/**
 * Plugin Name: WP Dansal
 * Plugin URI: https://github.com/ademant/wp-dansal
 * Description: Manage dance events and locations in WordPress, backed by a dansal server (https://github.com/ademant/dansal) as the storage/publishing backend.
 * Version: 0.1.1
 * Author: ademant
 * License: GPL-2.0-or-later
 * Text Domain: wp-dansal
 * Requires PHP: 7.4
 * Requires at least: 6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WPD_VERSION', '0.1.1' );
define( 'WPD_PLUGIN_FILE', __FILE__ );
define( 'WPD_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPD_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once WPD_PLUGIN_DIR . 'includes/class-wpd-settings.php';
require_once WPD_PLUGIN_DIR . 'includes/class-wpd-api-client.php';
require_once WPD_PLUGIN_DIR . 'includes/class-wpd-nominatim.php';
require_once WPD_PLUGIN_DIR . 'includes/class-wpd-cpt-location.php';
require_once WPD_PLUGIN_DIR . 'includes/class-wpd-event-fields.php';
require_once WPD_PLUGIN_DIR . 'includes/class-wpd-event-prefill.php';
require_once WPD_PLUGIN_DIR . 'includes/class-wpd-admin-action.php';
require_once WPD_PLUGIN_DIR . 'includes/class-wpd-event-draft.php';
require_once WPD_PLUGIN_DIR . 'includes/class-wpd-cpt-event.php';
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
	public $cpt_location;
	public $event_fields;
	public $cpt_event;
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
		$this->cpt_location = new WPD_CPT_Location( $this->api, $this->nominatim, $this->settings );
		$this->event_fields = new WPD_Event_Fields( $this->api );
		$this->cpt_event    = new WPD_CPT_Event( $this->api, $this->settings, $this->event_fields );
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
