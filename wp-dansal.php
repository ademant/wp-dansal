<?php
/**
 * Plugin Name: WP Dansal
 * Plugin URI: https://github.com/ademant/wp-dansal
 * Description: Manage dance events and locations in WordPress, backed by a dansal server (https://github.com/ademant/dansal) as the storage/publishing backend.
 * Version: 0.8.2
 * Author: ademant
 * License: GPL-2.0-or-later
 * Text Domain: wp-dansal
 * Domain Path: /languages
 * Requires PHP: 7.4
 * Requires at least: 6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WPD_VERSION', '0.8.2' );
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
require_once WPD_PLUGIN_DIR . 'includes/class-wpd-cpt-person.php';
require_once WPD_PLUGIN_DIR . 'includes/class-wpd-cpt-musician.php';
require_once WPD_PLUGIN_DIR . 'includes/class-wpd-cpt-instructor.php';
require_once WPD_PLUGIN_DIR . 'includes/class-wpd-remote-events.php';
require_once WPD_PLUGIN_DIR . 'includes/class-wpd-frontend.php';
require_once WPD_PLUGIN_DIR . 'includes/class-wpd-widget-mini-calendar.php';
require_once WPD_PLUGIN_DIR . 'includes/class-wpd-widget-upcoming-events.php';

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
	public $cpt_musician;
	public $cpt_instructor;
	public $remote_events;
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
		$this->cpt_musician   = new WPD_CPT_Musician( $this->api, $this->settings );
		$this->cpt_instructor = new WPD_CPT_Instructor( $this->api, $this->settings );
		$this->remote_events  = new WPD_Remote_Events( $this->settings, $this->api );
		$this->frontend     = new WPD_Frontend( $this->settings, $this->remote_events );

		// WP 6.7+ core warns (_doing_it_wrong) if just-in-time translation
		// loading is triggered before 'init', so load here rather than on
		// 'plugins_loaded' even though the real fix for #88 was the missing
		// Domain Path header below.
		add_action( 'init', array( $this, 'load_textdomain' ), 1 );
		add_action( 'init', array( $this, 'ensure_cron_scheduled' ) );
		add_action( 'init', array( $this, 'maybe_flush_rewrite_rules' ), 20 );
		add_action( 'wpd_apikey_renew_check', array( $this, 'cron_renew_apikey' ) );
		add_action( 'admin_notices', array( $this, 'admin_notice_apikey_dead' ) );
	}

	/**
	 * Catches upgrades from an older version installed before the renewal
	 * cron existed — activation hook only runs on activate/reactivate.
	 */
	public function ensure_cron_scheduled() {
		if ( ! wp_next_scheduled( 'wpd_apikey_renew_check' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'wpd_apikey_renew_check' );
		}
	}

	/**
	 * Daily WP-Cron tick. Renews the publisher API key when its stored
	 * expiry is within the lead-time window (default 7 days) — never
	 * touches a key we already know has no expiry or is dead.
	 */
	public function cron_renew_apikey() {
		if ( ! $this->settings->is_configured() ) {
			return;
		}
		if ( ! $this->api->apikey_should_renew() ) {
			return;
		}
		$this->api->renew_apikey();
	}

	public function admin_notice_apikey_dead() {
		if ( ! current_user_can( 'manage_options' ) || ! $this->settings->is_api_key_dead() ) {
			return;
		}
		printf(
			'<div class="notice notice-error"><p>%s <a href="%s">%s</a></p></div>',
			esc_html__( 'WP Dansal: the publisher API key has expired.', 'wp-dansal' ),
			esc_url( admin_url( 'options-general.php?page=wpd-settings' ) ),
			esc_html__( 'Re-run the connect-link flow to restore the connection.', 'wp-dansal' )
		);
	}

	public function load_textdomain() {
		load_plugin_textdomain( 'wp-dansal', false, dirname( plugin_basename( WPD_PLUGIN_FILE ) ) . '/languages' );
	}

	/**
	 * Runs on 'init' at priority 20, after the CPT classes have registered
	 * their post types at the default priority. Activation can't flush
	 * rewrite rules directly — 'init' has already fired once by the time the
	 * activation hook runs, and re-firing it with do_action('init') breaks
	 * other plugins whose 'init' callbacks aren't safe to run twice in one
	 * request (#93). So activation just sets a flag, and the actual flush
	 * happens here on the next real 'init'.
	 */
	public function maybe_flush_rewrite_rules() {
		if ( get_option( 'wpd_flush_rewrite_rules' ) ) {
			flush_rewrite_rules();
			delete_option( 'wpd_flush_rewrite_rules' );
		}
	}
}

function wpd_plugin() {
	return WPD_Plugin::instance();
}
wpd_plugin();

/**
 * Activation: defer the rewrite-rules flush to the next real 'init' (see
 * WPD_Plugin::maybe_flush_rewrite_rules()) rather than forcing 'init' to
 * fire a second time in this request, which is unsafe for other plugins.
 */
function wpd_activate() {
	update_option( 'wpd_flush_rewrite_rules', 1 );
	if ( ! wp_next_scheduled( 'wpd_apikey_renew_check' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'wpd_apikey_renew_check' );
	}
}
register_activation_hook( __FILE__, 'wpd_activate' );

function wpd_deactivate() {
	flush_rewrite_rules();
	wp_clear_scheduled_hook( 'wpd_apikey_renew_check' );
}
register_deactivation_hook( __FILE__, 'wpd_deactivate' );
