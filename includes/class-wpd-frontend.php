<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Public-facing display: event list/calendar and single-event page render
 * from the locally synced dansal_event/dansal_location CPTs. A single post
 * view also triggers a rate-limited single-item refresh from dansal (see
 * WPD_CPT_Event::maybe_refresh_single() / WPD_CPT_Location::maybe_refresh_single()),
 * since the admin-list-triggered pull-sync only runs when someone opens
 * wp-admin and would otherwise leave a public page stale indefinitely.
 *
 * Every WP_Query in this class explicitly sets post_status => 'publish' —
 * do not change to 'any' or omit it, or drafts leak to unauthenticated
 * visitors.
 */
class WPD_Frontend {

	/** @var WPD_Settings */
	private $settings;

	/** @var WPD_Remote_Events */
	private $remote_events;

	public function __construct( WPD_Settings $settings, WPD_Remote_Events $remote_events ) {
		$this->settings      = $settings;
		$this->remote_events = $remote_events;

		add_shortcode( 'dansal_events', array( $this, 'shortcode_events' ) );
		add_shortcode( 'dansal_locations', array( $this, 'shortcode_locations' ) );
		add_shortcode( 'dansal_nearby', array( $this, 'shortcode_nearby' ) );
		add_shortcode( 'dansal_festivals', array( $this, 'shortcode_festivals' ) );
		add_filter( 'single_template', array( $this, 'single_template' ) );
		add_filter( 'archive_template', array( $this, 'archive_template' ) );
		add_filter( 'theme_page_templates', array( $this, 'register_page_templates' ) );
		add_filter( 'page_template', array( $this, 'page_template' ) );
		add_action( 'wp_ajax_wpd_mini_calendar', array( $this, 'ajax_mini_calendar' ) );
		add_action( 'wp_ajax_nopriv_wpd_mini_calendar', array( $this, 'ajax_mini_calendar' ) );
		add_action( 'wp_ajax_wpd_nearby', array( $this, 'ajax_nearby' ) );
		add_action( 'wp_ajax_nopriv_wpd_nearby', array( $this, 'ajax_nearby' ) );
	}

	/**
	 * Register selectable page templates in Page Attributes → Template.
	 * Keys are template slugs (matched against get_page_template_slug in
	 * page_template() below); values are the human labels shown in the
	 * dropdown. Site owners can then place the locations map or events
	 * calendar at any URL/menu position by choosing the template on a
	 * normal Page.
	 */
	public function register_page_templates( $templates ) {
		$templates['wpd-locations.php'] = __( 'Dansal: Locations Map', 'wp-dansal' );
		$templates['wpd-calendar.php']  = __( 'Dansal: Events Calendar', 'wp-dansal' );
		return $templates;
	}

	public function page_template( $template ) {
		$slug = get_page_template_slug( get_queried_object_id() );
		if ( 'wpd-locations.php' === $slug ) {
			return $this->locate_plugin_template( 'page-dansal-locations.php', $template );
		}
		if ( 'wpd-calendar.php' === $slug ) {
			return $this->locate_plugin_template( 'page-dansal-calendar.php', $template );
		}
		return $template;
	}

	public function single_template( $template ) {
		global $post;
		if ( $post && WPD_CPT_Event::POST_TYPE === $post->post_type ) {
			wpd_plugin()->cpt_event->maybe_refresh_single( $post->ID );
			return $this->locate_plugin_template( 'single-dansal_event.php', $template );
		}
		if ( $post && WPD_CPT_Location::POST_TYPE === $post->post_type ) {
			wpd_plugin()->cpt_location->maybe_refresh_single( $post->ID );
			return $this->locate_plugin_template( 'single-dansal_location.php', $template );
		}
		return $template;
	}

	public function archive_template( $template ) {
		if ( is_post_type_archive( WPD_CPT_Location::POST_TYPE ) ) {
			return $this->locate_plugin_template( 'archive-dansal_location.php', $template );
		}
		if ( is_post_type_archive( WPD_CPT_Event::POST_TYPE ) ) {
			return $this->locate_plugin_template( 'archive-dansal_event.php', $template );
		}
		return $template;
	}

	/**
	 * WooCommerce-style template resolution: a theme (or child theme) can
	 * override any plugin template by placing a file at
	 * {theme}/dansal/{name} — locate_template() checks the child theme
	 * first, then the parent, then falls back to the plugin's copy.
	 */
	private function locate_plugin_template( $name, $default_template ) {
		$theme_override = locate_template( array( 'dansal/' . $name ) );
		if ( $theme_override ) {
			return $theme_override;
		}
		$plugin_copy = WPD_PLUGIN_DIR . 'templates/' . $name;
		return file_exists( $plugin_copy ) ? $plugin_copy : $default_template;
	}

	private function enqueue_frontend_style() {
		wp_enqueue_style( 'wpd-frontend', WPD_PLUGIN_URL . 'assets/css/frontend.css', array(), wpd_asset_ver( 'assets/css/frontend.css' ) );
	}

	private function enqueue_leaflet() {
		wp_enqueue_style( 'wpd-leaflet', WPD_PLUGIN_URL . 'assets/vendor/leaflet/leaflet.css', array(), '1.9.4' );
		wp_enqueue_script( 'wpd-leaflet', WPD_PLUGIN_URL . 'assets/vendor/leaflet/leaflet.js', array(), '1.9.4', true );
		wp_enqueue_script( 'wpd-map', WPD_PLUGIN_URL . 'assets/js/frontend-map.js', array( 'wpd-leaflet' ), wpd_asset_ver( 'assets/js/frontend-map.js' ), true );
	}

	private function enqueue_mini_calendar_script() {
		wp_enqueue_script( 'wpd-mini-calendar', WPD_PLUGIN_URL . 'assets/js/frontend-mini-calendar.js', array(), wpd_asset_ver( 'assets/js/frontend-mini-calendar.js' ), true );
		wp_localize_script(
			'wpd-mini-calendar',
			'wpdMiniCal',
			array(
				'ajaxurl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'wpd_mini_calendar' ),
			)
		);
	}

	private function enqueue_nearby_script() {
		wp_enqueue_script( 'wpd-nearby', WPD_PLUGIN_URL . 'assets/js/frontend-nearby.js', array(), wpd_asset_ver( 'assets/js/frontend-nearby.js' ), true );
		wp_localize_script(
			'wpd-nearby',
			'wpdNearby',
			array(
				'ajaxurl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'wpd_nearby' ),
			)
		);
	}

	/**
	 * AJAX endpoint feeding the mini calendar's month arrows (see #98).
	 * Returns the rendered HTML for the requested month; JS swaps it into
	 * the widget in-place, avoiding the full page reload the anchor href
	 * would otherwise trigger.
	 */
	public function ajax_mini_calendar() {
		check_ajax_referer( 'wpd_mini_calendar' );

		$month = isset( $_GET['month'] ) ? absint( $_GET['month'] ) : 0;
		$year  = isset( $_GET['year'] ) ? absint( $_GET['year'] ) : 0;
		if ( $month < 1 || $month > 12 || $year < 1970 || $year > 2100 ) {
			wp_send_json_error( array( 'message' => 'Bad month/year' ) );
		}

		$atts = array(
			'location'   => 0,
			'tag'        => '',
			'type'       => array(),
			'limit'      => 100,
			'view'       => 'mini',
			'show_past'  => 1,
			'month'      => $month,
			'year'       => $year,
			'show_types' => 0,
		);

		wp_send_json_success( array( 'html' => $this->render_mini_calendar( $atts ) ) );
	}

	/**
	 * AJAX endpoint feeding [dansal_nearby] once the browser has
	 * geolocated the visitor (see #99). Returns the rendered widget
	 * HTML re-computed around the visitor's coords.
	 */
	public function ajax_nearby() {
		check_ajax_referer( 'wpd_nearby' );

		$lat       = isset( $_POST['lat'] ) && is_numeric( $_POST['lat'] ) ? (float) $_POST['lat'] : null;
		$lon       = isset( $_POST['lon'] ) && is_numeric( $_POST['lon'] ) ? (float) $_POST['lon'] : null;
		$radius_km = isset( $_POST['radius_km'] ) && is_numeric( $_POST['radius_km'] ) ? (float) $_POST['radius_km'] : 50.0;
		$limit     = isset( $_POST['limit'] ) ? max( 1, min( 100, absint( $_POST['limit'] ) ) ) : 50;
		$view      = isset( $_POST['view'] ) ? sanitize_key( wp_unslash( $_POST['view'] ) ) : 'map+list';
		$tag       = isset( $_POST['tag'] ) ? sanitize_key( wp_unslash( $_POST['tag'] ) ) : '';
		$exclude   = ! empty( $_POST['exclude_own_org'] ) ? 1 : 0;

		if ( null === $lat || null === $lon ) {
			wp_send_json_error( array( 'message' => 'Missing coordinates' ) );
		}
		$radius_km = max( 1.0, min( 500.0, $radius_km ) );
		if ( ! in_array( $view, array( 'list', 'map', 'map+list' ), true ) ) {
			$view = 'map+list';
		}

		$atts = array(
			'lat'             => $lat,
			'lon'             => $lon,
			'radius_km'       => $radius_km,
			'limit'           => $limit,
			'view'            => $view,
			'tag'             => $tag,
			'exclude_own_org' => $exclude,
		);

		wp_send_json_success( array( 'html' => $this->render_nearby_from_coords( $atts ) ) );
	}

	/**
	 * Tile provider config for the frontend map, filterable so site owners
	 * can point at a self-hosted or paid tile proxy instead of OSM's
	 * public tile server (which sees every visitor's IP + Referer). The
	 * default keeps OSM with referrerPolicy "origin" — OSM's tile usage
	 * policy requires a valid referer to identify the requesting site and
	 * blocks requests that send none, so "no-referrer" made every tile
	 * request fail; "origin" still keeps the page's full path/query from
	 * leaking, only the scheme+host is sent. Emitted as a data- attribute
	 * on the map container (see enqueue_leaflet callers) — no inline
	 * <script>, so a strict site CSP still works.
	 */
	public function tile_config() {
		return array(
			'urlTemplate'    => (string) apply_filters( 'wpd_tile_url_template', 'https://tile.openstreetmap.org/{z}/{x}/{y}.png' ),
			'attribution'    => (string) apply_filters( 'wpd_tile_attribution', '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors' ),
			'maxZoom'        => (int) apply_filters( 'wpd_tile_max_zoom', 19 ),
			'referrerPolicy' => (string) apply_filters( 'wpd_tile_referrer_policy', 'origin' ),
		);
	}

	/**
	 * [dansal_events location="123" tag="bal-folk" type="ball,workshop,festival,other" limit="20" view="list|calendar|mini|map|map+list|simple|map+simple" show_past="0"]
	 *
	 * `tag` filters the free-tags meta (_wpd_tags), `type` filters the
	 * boolean event-type flags (_wpd_has_ball / _wpd_has_workshop /
	 * _wpd_has_festival), OR-combined. `other` matches events with none
	 * of the three flags set. Remote queries currently only honor `tag`;
	 * `type` is local-only (no confirmed dansal query param spelling).
	 *
	 * Remote-query attributes surface events from OTHER organizations/cities
	 * on the same dansal instance, fetched live via GET /api/v1/events
	 * instead of the locally synced dansal_event CPTs (those only ever hold
	 * this site's own org). Setting any of them switches list/calendar
	 * rendering to the remote path; `location`/`tag` still apply (`tag` is
	 * also a dansal query param, `location` is WP-post-ID-scoped and is
	 * therefore ignored remotely):
	 *
	 * [dansal_events org="balfolkberlin,other-city" country="de,fr"
	 *  bbox="minLng,minLat,maxLng,maxLat" lat="52.5" lon="13.4" radius_km="50"
	 *  exclude_own_org="1"]
	 */
	public function shortcode_events( $atts ) {
		$atts = shortcode_atts(
            array(
				'location'        => '',
				'tag'             => '',
				'type'            => '',
				'limit'           => 20,
				'view'            => 'list',
				'show_past'       => 0,
				'month'           => '',
				'year'            => '',
				'org'             => '',
				'country'         => '',
				'bbox'            => '',
				'lat'             => '',
				'lon'             => '',
				'radius_km'       => '',
				'exclude_own_org' => 0,
				'show_types'      => 0,
            ),
            $atts,
            'dansal_events'
        );

		// Bound every attribute before it reaches WP_Query or the dansal API.
		// Author input from shortcodes can come from lower-trust roles or
		// copy-pasted snippets, so we cap limits, whitelist enums, and coerce
		// IDs/dates.
		$atts['location']  = absint( $atts['location'] );
		$atts['tag']       = sanitize_key( $atts['tag'] );
		$raw_types         = array_map( 'sanitize_key', explode( ',', (string) $atts['type'] ) );
		$atts['type']      = array_values( array_unique( array_intersect( $raw_types, array( 'ball', 'workshop', 'festival', 'other' ) ) ) );
		$atts['limit']     = max( 1, min( 100, absint( $atts['limit'] ) ) );
		$atts['view']      = in_array( $atts['view'], array( 'list', 'calendar', 'mini', 'map', 'map+list', 'simple', 'map+simple' ), true ) ? $atts['view'] : 'list';
		$atts['show_past'] = ! empty( $atts['show_past'] ) && '0' !== (string) $atts['show_past'] ? 1 : 0;
		$month             = absint( $atts['month'] );
		$atts['month']     = ( $month >= 1 && $month <= 12 ) ? $month : '';
		$year              = absint( $atts['year'] );
		$atts['year']      = ( $year >= 1970 && $year <= 2100 ) ? $year : '';

		$atts['org']             = array_values( array_filter( array_map( 'sanitize_title', explode( ',', (string) $atts['org'] ) ) ) );
		$atts['country']         = $this->sanitize_country_list( $atts['country'] );
		$atts['bbox']            = $this->sanitize_bbox( $atts['bbox'] );
		$atts['lat']             = is_numeric( $atts['lat'] ) ? (float) $atts['lat'] : '';
		$atts['lon']             = is_numeric( $atts['lon'] ) ? (float) $atts['lon'] : '';
		$atts['radius_km']       = is_numeric( $atts['radius_km'] ) && $atts['radius_km'] > 0 ? (float) $atts['radius_km'] : '';
		$atts['exclude_own_org'] = ! empty( $atts['exclude_own_org'] ) && '0' !== (string) $atts['exclude_own_org'] ? 1 : 0;
		$atts['show_types']      = ! empty( $atts['show_types'] ) && '0' !== (string) $atts['show_types'] ? 1 : 0;
		// Proximity search needs all three parts; a partial lat/lon/radius_km
		// combination is silently dropped rather than sent to dansal, which
		// would otherwise ignore it anyway (see API.md, GET /api/v1/events).
		if ( '' === $atts['lat'] || '' === $atts['lon'] || '' === $atts['radius_km'] ) {
			$atts['lat']       = '';
			$atts['lon']       = '';
			$atts['radius_km'] = '';
		}

		$this->enqueue_frontend_style();

		$remote = $this->is_remote_query( $atts );

		if ( 'calendar' === $atts['view'] ) {
			return $remote ? $this->render_calendar_remote( $atts ) : $this->render_calendar( $atts );
		}
		if ( 'mini' === $atts['view'] ) {
			return $this->render_mini_calendar( $atts );
		}
		if ( 'map' === $atts['view'] && ! $remote ) {
			return $this->render_map( $atts );
		}
		if ( 'map+list' === $atts['view'] && ! $remote ) {
			return $this->render_map_and_list( $atts );
		}
		if ( 'simple' === $atts['view'] && ! $remote ) {
			return $this->render_simple( $atts );
		}
		if ( 'map+simple' === $atts['view'] && ! $remote ) {
			return $this->render_map_and_simple( $atts );
		}
		return $remote ? $this->render_list_remote( $atts ) : $this->render_list( $atts );
	}

	/**
	 * True once any attribute asks for events dansal knows about but this
	 * site hasn't synced locally (another org, a place/country filter, a
	 * proximity search). `mini` view never goes remote — it's a compact
	 * sidebar widget tied to this site's own synced events.
	 */
	private function is_remote_query( $atts ) {
		return ! empty( $atts['org'] )
			|| ! empty( $atts['country'] )
			|| ! empty( $atts['bbox'] )
			|| '' !== $atts['lat']
			|| ! empty( $atts['exclude_own_org'] );
	}

	/**
	 * @return string Comma-separated, uppercased, deduped 2-letter codes —
	 *                dansal's `country=` filter passes this straight through.
	 */
	private function sanitize_country_list( $raw ) {
		$codes = array();
		foreach ( explode( ',', (string) $raw ) as $code ) {
			$code = strtoupper( trim( $code ) );
			if ( preg_match( '/^[A-Z]{2}$/', $code ) ) {
				$codes[] = $code;
			}
		}
		return implode( ',', array_values( array_unique( $codes ) ) );
	}

	/**
	 * @return string 'minLng,minLat,maxLng,maxLat' when all four values are
	 *                numeric, else '' (dansal's `bbox=` filter is all-or-nothing).
	 */
	private function sanitize_bbox( $raw ) {
		$parts = explode( ',', (string) $raw );
		if ( 4 !== count( $parts ) ) {
			return '';
		}
		$parts = array_map( 'trim', $parts );
		foreach ( $parts as $part ) {
			if ( ! is_numeric( $part ) ) {
				return '';
			}
		}
		return implode( ',', array_map( 'floatval', $parts ) );
	}

	private function base_meta_query( $atts ) {
		// Whether an event is "published" is just its WordPress post_status
		// now (see WPD_CPT_Event::build_payload()), so filtering to
		// post_status => 'publish' in the WP_Query args below is the only
		// visibility check needed here; no separate meta flag to match.
		$meta_query = array( 'relation' => 'AND' );
		if ( ! empty( $atts['location'] ) ) {
			$meta_query[] = array(
				'key' => '_wpd_location_post_id',
				'value' => absint( $atts['location'] ),
			);
		}
		if ( ! empty( $atts['tag'] ) ) {
			$meta_query[] = array(
				'key' => '_wpd_tags',
				'value' => ',' . sanitize_key( $atts['tag'] ) . ',',
				'compare' => 'LIKE',
			);
		}
		if ( ! empty( $atts['type'] ) && is_array( $atts['type'] ) ) {
			$type_sub = array( 'relation' => 'OR' );
			foreach ( $atts['type'] as $type ) {
				if ( 'other' === $type ) {
					// event_type_keys() treats a post as "other" when none of
					// the three flag metas equal '1'. That includes missing
					// keys, so each flag needs (NOT EXISTS OR value != '1').
					$type_sub[] = array(
						'relation' => 'AND',
						array(
							'relation' => 'OR',
							array( 'key' => '_wpd_has_ball', 'compare' => 'NOT EXISTS' ),
							array( 'key' => '_wpd_has_ball', 'value' => '1', 'compare' => '!=' ),
						),
						array(
							'relation' => 'OR',
							array( 'key' => '_wpd_has_workshop', 'compare' => 'NOT EXISTS' ),
							array( 'key' => '_wpd_has_workshop', 'value' => '1', 'compare' => '!=' ),
						),
						array(
							'relation' => 'OR',
							array( 'key' => '_wpd_has_festival', 'compare' => 'NOT EXISTS' ),
							array( 'key' => '_wpd_has_festival', 'value' => '1', 'compare' => '!=' ),
						),
					);
				} else {
					$type_sub[] = array(
						'key'   => '_wpd_has_' . $type,
						'value' => '1',
					);
				}
			}
			if ( count( $type_sub ) > 1 ) {
				$meta_query[] = $type_sub;
			}
		}
		return $meta_query;
	}

	/**
	 * dansal query params shared by the remote list and calendar renderers —
	 * everything from shortcode_events()'s remote-query attributes except
	 * time bounds and organization_id/limit, which each caller sets itself
	 * (calendar needs a month window; a multi-org fetch needs one
	 * organization_id per request — see WPD_Remote_Events::fetch_events_for_orgs()).
	 */
	private function build_remote_query( $atts ) {
		$query = array();
		if ( ! empty( $atts['tag'] ) ) {
			$query['tag'] = $atts['tag'];
		}
		if ( ! empty( $atts['country'] ) ) {
			$query['country'] = $atts['country'];
		}
		if ( ! empty( $atts['bbox'] ) ) {
			$query['bbox'] = $atts['bbox'];
		}
		if ( '' !== $atts['lat'] ) {
			$query['lat']       = $atts['lat'];
			$query['lon']       = $atts['lon'];
			$query['radius_km'] = $atts['radius_km'];
		}
		return $query;
	}

	/**
	 * Resolve org= slugs and run either the single-request or per-org-merge
	 * fetch, then drop the local org's own events when exclude_own_org is
	 * set. Shared by render_list_remote() and render_calendar_remote().
	 *
	 * @param array $query Base query from build_remote_query(), plus
	 *                      whatever time-range params the caller already added.
	 * @param int   $limit
	 */
	private function fetch_remote_events( $atts, $query, $limit ) {
		$org_ids = $this->remote_events->resolve_org_ids( $atts['org'] );

		if ( ! empty( $atts['org'] ) && empty( $org_ids ) ) {
			// Every requested org= slug failed to resolve — show nothing
			// rather than silently falling back to "all organizations".
			$events = array();
		} elseif ( ! empty( $org_ids ) ) {
			$events = $this->remote_events->fetch_events_for_orgs( $org_ids, $query, $limit );
		} else {
			$query['limit'] = $limit;
			$events         = $this->remote_events->fetch_events( $query );
			if ( is_wp_error( $events ) ) {
				$events = array();
			}
		}

		if ( ! empty( $atts['exclude_own_org'] ) ) {
			$local_org_id = $this->settings->get_org_id();
			$events       = array_values(
                array_filter(
                    $events,
                    function ( $event ) use ( $local_org_id ) {
						return ! ( $local_org_id && isset( $event['organization_id'] ) && (int) $event['organization_id'] === $local_org_id );
                    }
                )
            );
		}

		return $events;
	}

	/**
	 * @return string Link to the dansal-web single event page. Remote events
	 *                have no local WP permalink, so this is always the
	 *                dansal-web URL, not a site-relative one.
	 */
	private function remote_event_url( $event ) {
		$id = isset( $event['id'] ) ? absint( $event['id'] ) : 0;
		return $id ? $this->settings->get_web_url() . '/events/' . $id : '';
	}

	private function remote_org_url( $slug ) {
		return $this->settings->get_web_url() . '/org/' . rawurlencode( $slug );
	}

	private function remote_location_url( $id ) {
		return $this->settings->get_web_url() . '/location/' . absint( $id );
	}

	/**
	 * @return string[] Same shape as event_type_keys(), read from a dansal
	 *                   API event array instead of dansal_event post meta.
	 */
	private function event_type_keys_remote( $event ) {
		$flags  = array(
			'ball'     => ! empty( $event['has_ball'] ),
			'workshop' => ! empty( $event['has_workshop'] ),
			'festival' => ! empty( $event['has_festival'] ),
		);
		$active = array_keys( array_filter( $flags ) );
		return $active ? $active : array( 'other' );
	}

	/**
	 * Event card for a raw dansal API event array (GET /api/v1/events),
	 * counterpart to render_event_card() for locally synced posts. Links to
	 * dansal-web throughout since these events/locations/orgs have no local
	 * WP page. Shows the organization name/link only when it differs from
	 * this site's own org, so a mixed listing makes clear whose event it is.
	 */
	private function render_event_card_remote( $event ) {
		$cancelled = ! empty( $event['is_cancelled'] );
		$title     = isset( $event['title'] ) ? (string) $event['title'] : '';
		$start     = isset( $event['start_time'] ) ? (string) $event['start_time'] : '';

		$org_id       = isset( $event['organization_id'] ) ? (int) $event['organization_id'] : 0;
		$local_org_id = $this->settings->get_org_id();
		$org          = ( $org_id && $org_id !== $local_org_id ) ? $this->remote_events->org_info( $org_id ) : null;

		$location = isset( $event['location']['location'] ) ? (string) $event['location']['location'] : '';
		$loc_id   = isset( $event['location']['id'] ) ? absint( $event['location']['id'] ) : 0;
		?>
		<article class="wpd-event-card wpd-event-card-remote<?php echo $cancelled ? ' wpd-cancelled' : ''; ?>">
			<div class="wpd-event-date"><?php echo esc_html( $this->format_datetime( $start ) ); ?></div>
			<h3 class="wpd-event-title"><a href="<?php echo esc_url( $this->remote_event_url( $event ) ); ?>"><?php echo esc_html( $title ); ?></a></h3>
			<?php if ( $cancelled ) : ?>
				<p class="wpd-cancelled-badge"><?php esc_html_e( 'Cancelled', 'wp-dansal' ); ?></p>
			<?php endif; ?>
			<?php if ( '' !== $location ) : ?>
				<p class="wpd-event-location">
					<?php if ( $loc_id ) : ?>
						<a href="<?php echo esc_url( $this->remote_location_url( $loc_id ) ); ?>"><?php echo esc_html( $location ); ?></a>
					<?php else : ?>
						<?php echo esc_html( $location ); ?>
					<?php endif; ?>
				</p>
			<?php endif; ?>
			<?php if ( $org ) : ?>
				<p class="wpd-event-org"><a href="<?php echo esc_url( $this->remote_org_url( $org['slug'] ) ); ?>"><?php echo esc_html( $org['name'] ); ?></a></p>
			<?php endif; ?>
		</article>
		<?php
	}

	private function render_list_remote( $atts ) {
		$query = $this->build_remote_query( $atts );
		if ( ! empty( $atts['show_past'] ) ) {
			$query['include_past'] = 'true';
		}

		$events = $this->fetch_remote_events( $atts, $query, $atts['limit'] );
		$events = array_slice( $events, 0, $atts['limit'] );

		ob_start();
		echo '<div class="wpd-events-list">';
		if ( empty( $events ) ) {
			echo '<p class="wpd-no-events">' . esc_html__( 'No upcoming events.', 'wp-dansal' ) . '</p>';
		}
		foreach ( $events as $event ) {
			$this->render_event_card_remote( $event );
		}
		echo '</div>';
		return ob_get_clean();
	}

	private function render_list( $atts ) {
		$meta_query = $this->base_meta_query( $atts );
		if ( empty( $atts['show_past'] ) ) {
			$meta_query[] = array(
				'key' => '_wpd_start_time',
				'value' => current_time( 'Y-m-d\TH:i' ),
				'compare' => '>=',
			);
		}

		$query = new WP_Query(
            array(
				'post_type'      => WPD_CPT_Event::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => absint( $atts['limit'] ),
				'meta_key'       => '_wpd_start_time',
				'orderby'        => 'meta_value',
				'order'          => 'ASC',
				'meta_query'     => $meta_query,
            )
        );

		ob_start();
		echo '<div class="wpd-events-list">';
		if ( ! $query->have_posts() ) {
			echo '<p class="wpd-no-events">' . esc_html__( 'No upcoming events.', 'wp-dansal' ) . '</p>';
		}
		while ( $query->have_posts() ) {
			$query->the_post();
			$this->render_event_card( get_the_ID() );
		}
		echo '</div>';
		wp_reset_postdata();
		return ob_get_clean();
	}

	private function build_event_query( $atts ) {
		$meta_query = $this->base_meta_query( $atts );
		if ( empty( $atts['show_past'] ) ) {
			$meta_query[] = array(
				'key'     => '_wpd_start_time',
				'value'   => current_time( 'Y-m-d\TH:i' ),
				'compare' => '>=',
			);
		}
		return new WP_Query(
			array(
				'post_type'      => WPD_CPT_Event::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => absint( $atts['limit'] ),
				'meta_key'       => '_wpd_start_time',
				'orderby'        => 'meta_value',
				'order'          => 'ASC',
				'meta_query'     => $meta_query,
			)
		);
	}

	private function group_events_by_location( WP_Query $query ) {
		$by_location = array();
		while ( $query->have_posts() ) {
			$query->the_post();
			$eid    = get_the_ID();
			$loc_id = (int) get_post_meta( $eid, '_wpd_location_post_id', true );
			if ( ! $loc_id ) {
				continue;
			}
			$lat = get_post_meta( $loc_id, '_wpd_latitude', true );
			$lng = get_post_meta( $loc_id, '_wpd_longitude', true );
			if ( '' === $lat || '' === $lng ) {
				continue;
			}
			if ( ! isset( $by_location[ $loc_id ] ) ) {
				$by_location[ $loc_id ] = array(
					'lat'    => (float) $lat,
					'lng'    => (float) $lng,
					'title'  => get_the_title( $loc_id ),
					'url'    => get_permalink( $loc_id ),
					'events' => array(),
				);
			}
			$start = get_post_meta( $eid, '_wpd_start_time', true );
			$by_location[ $loc_id ]['events'][] = array(
				'title' => get_the_title( $eid ),
				'url'   => get_permalink( $eid ),
				'when'  => $this->format_datetime( $start ),
			);
		}
		return array_values( $by_location );
	}

	private function render_map_markup( array $points ) {
		ob_start();
		?>
		<div class="wpd-events-map-wrap">
			<?php if ( empty( $points ) ) : ?>
				<p class="wpd-no-events"><?php esc_html_e( 'No upcoming events.', 'wp-dansal' ); ?></p>
			<?php else : ?>
				<div id="wpd-locations-map" class="wpd-locations-map" data-wpd-points="<?php echo esc_attr( wp_json_encode( $points ) ); ?>" data-wpd-tiles="<?php echo esc_attr( wp_json_encode( $this->tile_config() ) ); ?>"></div>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	private function render_list_markup( WP_Query $query ) {
		ob_start();
		echo '<div class="wpd-events-list">';
		if ( ! $query->have_posts() ) {
			echo '<p class="wpd-no-events">' . esc_html__( 'No upcoming events.', 'wp-dansal' ) . '</p>';
		}
		$query->rewind_posts();
		while ( $query->have_posts() ) {
			$query->the_post();
			$this->render_event_card( get_the_ID() );
		}
		echo '</div>';
		return ob_get_clean();
	}

	private function render_map( $atts ) {
		$this->enqueue_frontend_style();
		$this->enqueue_leaflet();
		$query  = $this->build_event_query( $atts );
		$points = $this->group_events_by_location( $query );
		wp_reset_postdata();
		return $this->render_map_markup( $points );
	}

	private function render_map_and_list( $atts ) {
		$this->enqueue_frontend_style();
		$this->enqueue_leaflet();
		$query  = $this->build_event_query( $atts );
		$points = $this->group_events_by_location( $query );
		$out    = $this->render_map_markup( $points ) . $this->render_list_markup( $query );
		wp_reset_postdata();
		return $out;
	}

	private function render_simple_markup( WP_Query $query, $show_types = false ) {
		ob_start();
		echo '<ul class="wpd-events-simple' . ( $show_types ? ' wpd-events-simple-with-types' : '' ) . '">';
		if ( ! $query->have_posts() ) {
			echo '<li class="wpd-no-events">' . esc_html__( 'No upcoming events.', 'wp-dansal' ) . '</li>';
		}
		$query->rewind_posts();
		while ( $query->have_posts() ) {
			$query->the_post();
			$eid       = get_the_ID();
			$start     = get_post_meta( $eid, '_wpd_start_time', true );
			$loc_id    = (int) get_post_meta( $eid, '_wpd_location_post_id', true );
			$cancelled = '1' === get_post_meta( $eid, '_wpd_is_cancelled', true );
			$types     = $show_types ? $this->event_type_keys( $eid ) : array();
			?>
			<li class="wpd-event-simple<?php echo $cancelled ? ' wpd-cancelled' : ''; ?>">
				<?php if ( $show_types ) : ?>
					<span class="wpd-mini-markers wpd-event-simple-types">
						<?php foreach ( $types as $t ) : ?>
							<i class="wpd-mini-dot wpd-mini-dot-<?php echo esc_attr( $t ); ?>" title="<?php echo esc_attr( $this->event_type_label( $t ) ); ?>"></i>
						<?php endforeach; ?>
					</span>
				<?php endif; ?>
				<span class="wpd-event-simple-date"><?php echo esc_html( $this->format_datetime( $start, 'd.m.Y H:i' ) ); ?></span>
				<span class="wpd-event-simple-sep"> — </span>
				<a class="wpd-event-simple-title" href="<?php echo esc_url( get_permalink( $eid ) ); ?>"><?php echo esc_html( get_the_title( $eid ) ); ?></a>
				<?php if ( $loc_id ) : ?>
					<span class="wpd-event-simple-at"> @ </span>
					<a class="wpd-event-simple-venue" href="<?php echo esc_url( get_permalink( $loc_id ) ); ?>"><?php echo esc_html( get_the_title( $loc_id ) ); ?></a>
				<?php endif; ?>
				<?php if ( $cancelled ) : ?>
					<span class="wpd-event-simple-cancelled"> (<?php esc_html_e( 'Cancelled', 'wp-dansal' ); ?>)</span>
				<?php endif; ?>
			</li>
			<?php
		}
		echo '</ul>';
		return ob_get_clean();
	}

	private function render_simple( $atts ) {
		$this->enqueue_frontend_style();
		$query = $this->build_event_query( $atts );
		$out   = $this->render_simple_markup( $query, ! empty( $atts['show_types'] ) );
		wp_reset_postdata();
		return $out;
	}

	private function render_map_and_simple( $atts ) {
		$this->enqueue_frontend_style();
		$this->enqueue_leaflet();
		$query  = $this->build_event_query( $atts );
		$points = $this->group_events_by_location( $query );
		$out    = $this->render_map_markup( $points ) . $this->render_simple_markup( $query, ! empty( $atts['show_types'] ) );
		wp_reset_postdata();
		return $out;
	}

	/**
	 * Group a list of remote dansal events into location-anchored map points,
	 * counterpart to group_events_by_location() for local WP_Query results.
	 * dansal's event payload embeds `location` with `id/name/latitude/longitude`,
	 * so no per-event round-trip is needed.
	 */
	private function group_remote_events_by_location( array $events ) {
		$by_loc = array();
		foreach ( $events as $event ) {
			$loc = isset( $event['location'] ) && is_array( $event['location'] ) ? $event['location'] : array();
			$lat = isset( $loc['latitude'] ) && is_numeric( $loc['latitude'] ) ? (float) $loc['latitude'] : null;
			$lng = isset( $loc['longitude'] ) && is_numeric( $loc['longitude'] ) ? (float) $loc['longitude'] : null;
			if ( null === $lat || null === $lng ) {
				continue;
			}
			$loc_id = isset( $loc['id'] ) ? absint( $loc['id'] ) : 0;
			$name   = isset( $loc['name'] ) ? (string) $loc['name'] : ( isset( $loc['location'] ) ? (string) $loc['location'] : '' );
			$key    = $loc_id ? 'r' . $loc_id : sprintf( 'c%.5f,%.5f', $lat, $lng );
			if ( ! isset( $by_loc[ $key ] ) ) {
				$by_loc[ $key ] = array(
					'lat'    => $lat,
					'lng'    => $lng,
					'title'  => $name,
					'url'    => $loc_id ? $this->remote_location_url( $loc_id ) : '',
					'events' => array(),
				);
			}
			$by_loc[ $key ]['events'][] = array(
				'title' => isset( $event['title'] ) ? (string) $event['title'] : '',
				'url'   => $this->remote_event_url( $event ),
				'when'  => isset( $event['start_time'] ) ? $this->format_datetime( (string) $event['start_time'] ) : '',
			);
		}
		return array_values( $by_loc );
	}

	private function render_remote_events_list_markup( array $events ) {
		ob_start();
		echo '<div class="wpd-events-list">';
		if ( empty( $events ) ) {
			echo '<p class="wpd-no-events">' . esc_html__( 'No upcoming events.', 'wp-dansal' ) . '</p>';
		}
		foreach ( $events as $event ) {
			$this->render_event_card_remote( $event );
		}
		echo '</div>';
		return ob_get_clean();
	}

	/**
	 * [dansal_nearby] — proximity-scoped event list/map fed by dansal's
	 * remote GET /api/v1/events. Coordinates come from (in order):
	 *   1. explicit lat/lon attributes,
	 *   2. the visitor's browser (see assets/js/frontend-nearby.js — takes
	 *      over after the initial server render via wp_ajax_wpd_nearby),
	 *   3. the org-home coords stored in wpd_settings (#99).
	 * If none is available the widget renders a short "not configured" note
	 * instead of firing a bogus zero-coord query.
	 */
	public function shortcode_nearby( $atts ) {
		$atts = shortcode_atts(
			array(
				'radius_km'       => 50,
				'lat'             => '',
				'lon'             => '',
				'view'            => 'map+list',
				'limit'           => 50,
				'tag'             => '',
				'type'            => '',
				'exclude_own_org' => 1,
			),
			$atts,
			'dansal_nearby'
		);

		$atts['radius_km']       = max( 1.0, min( 500.0, is_numeric( $atts['radius_km'] ) ? (float) $atts['radius_km'] : 50.0 ) );
		$atts['lat']             = is_numeric( $atts['lat'] ) ? (float) $atts['lat'] : '';
		$atts['lon']             = is_numeric( $atts['lon'] ) ? (float) $atts['lon'] : '';
		$atts['view']            = in_array( $atts['view'], array( 'list', 'map', 'map+list' ), true ) ? $atts['view'] : 'map+list';
		$atts['limit']           = max( 1, min( 100, absint( $atts['limit'] ) ) );
		$atts['tag']             = sanitize_key( $atts['tag'] );
		$atts['exclude_own_org'] = ! empty( $atts['exclude_own_org'] ) && '0' !== (string) $atts['exclude_own_org'] ? 1 : 0;

		$this->enqueue_frontend_style();
		$this->enqueue_nearby_script();

		// Resolve fallback coords: shortcode override → org home → nothing.
		if ( '' === $atts['lat'] || '' === $atts['lon'] ) {
			$home = $this->settings->get_home_coords();
			if ( '' !== $home['lat'] && '' !== $home['lon'] ) {
				$atts['lat'] = (float) $home['lat'];
				$atts['lon'] = (float) $home['lon'];
			}
		}

		$data_attrs = sprintf(
			' data-wpd-radius="%s" data-wpd-view="%s" data-wpd-limit="%d" data-wpd-tag="%s" data-wpd-exclude-own="%d"',
			esc_attr( $atts['radius_km'] ),
			esc_attr( $atts['view'] ),
			(int) $atts['limit'],
			esc_attr( $atts['tag'] ),
			(int) $atts['exclude_own_org']
		);

		if ( '' === $atts['lat'] || '' === $atts['lon'] ) {
			return '<div class="wpd-nearby" ' . trim( $data_attrs ) . '><p class="wpd-no-events">'
				. esc_html__( 'Home location not configured yet — set coordinates in Settings → Dansal.', 'wp-dansal' )
				. '</p></div>';
		}

		$inner = $this->render_nearby_from_coords( $atts );
		return '<div class="wpd-nearby" ' . trim( $data_attrs ) . '>' . $inner . '</div>';
	}

	/**
	 * [dansal_festivals] — cross-org festival browser (#100). Fetches remote
	 * events, keeps only has_festival=1, then groups by (location_id,
	 * style_bucket) so a yearly "Danserla 2026"/"Danserla 2027" collapses to
	 * one row/pin (showing the latest edition), while a balfolk festival and
	 * a tango festival at the same venue stay distinct.
	 */
	public function shortcode_festivals( $atts ) {
		$atts = shortcode_atts(
			array(
				'view'      => 'map+list',
				'limit'     => 50,
				'show_past' => 1,
				'tag'       => '',
				'org'       => '',
				'country'   => '',
				'bbox'      => '',
				'lat'       => '',
				'lon'       => '',
				'radius_km' => '',
			),
			$atts,
			'dansal_festivals'
		);

		$atts['view']      = in_array( $atts['view'], array( 'list', 'map', 'map+list' ), true ) ? $atts['view'] : 'map+list';
		$atts['limit']     = max( 1, min( 200, absint( $atts['limit'] ) ) );
		$atts['show_past'] = ! empty( $atts['show_past'] ) && '0' !== (string) $atts['show_past'] ? 1 : 0;
		$atts['tag']       = sanitize_key( $atts['tag'] );
		$atts['org']       = array_values( array_filter( array_map( 'sanitize_title', explode( ',', (string) $atts['org'] ) ) ) );
		$atts['country']   = $this->sanitize_country_list( $atts['country'] );
		$atts['bbox']      = $this->sanitize_bbox( $atts['bbox'] );
		$atts['lat']       = is_numeric( $atts['lat'] ) ? (float) $atts['lat'] : '';
		$atts['lon']       = is_numeric( $atts['lon'] ) ? (float) $atts['lon'] : '';
		$atts['radius_km'] = is_numeric( $atts['radius_km'] ) && $atts['radius_km'] > 0 ? (float) $atts['radius_km'] : '';
		if ( '' === $atts['lat'] || '' === $atts['lon'] || '' === $atts['radius_km'] ) {
			$atts['lat']       = '';
			$atts['lon']       = '';
			$atts['radius_km'] = '';
		}
		// exclude_own_org isn't exposed as a shortcode att, but fetch_remote_events()
		// needs the key present.
		$atts['exclude_own_org'] = 0;

		$this->enqueue_frontend_style();

		$query = $this->build_remote_query( $atts );
		if ( ! empty( $atts['show_past'] ) ) {
			$query['include_past'] = 'true';
		}
		// dansal's /events endpoint has no confirmed `type=festival` filter
		// (see [dansal_events] `type` note), so we overfetch and drop
		// non-festivals locally. Cap the fetch generously — grouping shrinks
		// it, and yearly editions cluster around the same festival.
		$events = $this->fetch_remote_events( $atts, $query, min( 500, max( 200, $atts['limit'] * 5 ) ) );
		$events = array_values(
			array_filter(
				$events,
				function ( $event ) {
					return ! empty( $event['has_festival'] );
				}
			)
		);

		$groups = $this->group_festivals( $events );
		$groups = array_slice( $groups, 0, $atts['limit'] );

		$out = '';
		if ( 'list' !== $atts['view'] ) {
			$this->enqueue_leaflet();
			$out .= $this->render_map_markup( $this->festival_map_points( $groups ) );
		}
		if ( 'map' !== $atts['view'] ) {
			$out .= $this->render_festivals_list_markup( $groups );
		}
		return '<div class="wpd-festivals">' . $out . '</div>';
	}

	/**
	 * Map an event's tags to a coarse dance-style bucket. Untagged / no-match
	 * events return a per-event bucket so they never merge with unrelated
	 * festivals at the same venue (#100).
	 *
	 * @param array $event Raw dansal event array.
	 * @return string
	 */
	private function festival_style_bucket( array $event ) {
		$tags = isset( $event['tags'] ) && is_array( $event['tags'] ) ? array_map( 'sanitize_key', $event['tags'] ) : array();

		$synonyms = array(
			'balfolk' => array( 'balfolk', 'bal-folk', 'fest-noz' ),
			'tango'   => array( 'tango', 'tango-argentino', 'tango-nuevo' ),
			'swing'   => array( 'swing', 'lindy-hop', 'balboa' ),
			'salsa'   => array( 'salsa', 'bachata', 'kizomba' ),
		);

		$bucket = '';
		foreach ( $synonyms as $key => $members ) {
			if ( array_intersect( $tags, $members ) ) {
				$bucket = $key;
				break;
			}
		}
		if ( '' === $bucket ) {
			$id     = isset( $event['id'] ) ? (int) $event['id'] : 0;
			$bucket = 'event_' . $id;
		}

		return (string) apply_filters( 'wpd_festival_style_bucket', $bucket, $tags, $event );
	}

	/**
	 * Group festival events by (location_id, style_bucket), keep the latest
	 * edition of each group as representative, and return sorted by that
	 * latest edition's start_time descending.
	 */
	private function group_festivals( array $events ) {
		$groups = array();
		foreach ( $events as $event ) {
			$loc_id = isset( $event['location']['id'] ) ? (int) $event['location']['id'] : 0;
			if ( ! $loc_id ) {
				continue;
			}
			$bucket = $this->festival_style_bucket( $event );
			$key    = $loc_id . '|' . $bucket;
			$start  = isset( $event['start_time'] ) ? (string) $event['start_time'] : '';

			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array(
					'latest'   => $event,
					'bucket'   => $bucket,
					'editions' => array( $event ),
				);
				continue;
			}
			$groups[ $key ]['editions'][] = $event;
			$cur_start = isset( $groups[ $key ]['latest']['start_time'] ) ? (string) $groups[ $key ]['latest']['start_time'] : '';
			if ( strcmp( $start, $cur_start ) > 0 ) {
				$groups[ $key ]['latest'] = $event;
			}
		}

		usort(
			$groups,
			function ( $a, $b ) {
				$sa = isset( $a['latest']['start_time'] ) ? (string) $a['latest']['start_time'] : '';
				$sb = isset( $b['latest']['start_time'] ) ? (string) $b['latest']['start_time'] : '';
				return strcmp( $sb, $sa );
			}
		);

		return $groups;
	}

	/**
	 * @param array $groups Output of group_festivals().
	 * @return array Points shape consumed by render_map_markup(): one pin per
	 *               (location, style bucket); popup shows the latest edition
	 *               with up to a few prior editions listed underneath.
	 */
	private function festival_map_points( array $groups ) {
		$points = array();
		foreach ( $groups as $group ) {
			$event = $group['latest'];
			$loc   = isset( $event['location'] ) && is_array( $event['location'] ) ? $event['location'] : array();
			$lat   = isset( $loc['latitude'] ) && is_numeric( $loc['latitude'] ) ? (float) $loc['latitude'] : null;
			$lng   = isset( $loc['longitude'] ) && is_numeric( $loc['longitude'] ) ? (float) $loc['longitude'] : null;
			if ( null === $lat || null === $lng ) {
				continue;
			}
			// Show up to the 3 most recent editions in the popup (latest first).
			$editions = $group['editions'];
			usort(
				$editions,
				function ( $a, $b ) {
					return strcmp( (string) ( $b['start_time'] ?? '' ), (string) ( $a['start_time'] ?? '' ) );
				}
			);
			$pin_events = array();
			foreach ( array_slice( $editions, 0, 3 ) as $ed ) {
				$pin_events[] = array(
					'title' => isset( $ed['title'] ) ? (string) $ed['title'] : '',
					'url'   => $this->remote_event_url( $ed ),
					'when'  => isset( $ed['start_time'] ) ? $this->format_datetime( (string) $ed['start_time'], get_option( 'date_format' ) ) : '',
				);
			}
			$points[] = array(
				'lat'    => $lat,
				'lng'    => $lng,
				'title'  => isset( $event['title'] ) ? (string) $event['title'] : '',
				'url'    => $this->remote_event_url( $event ),
				'events' => $pin_events,
			);
		}
		return $points;
	}

	private function render_festivals_list_markup( array $groups ) {
		ob_start();
		echo '<ul class="wpd-festivals-list">';
		if ( empty( $groups ) ) {
			echo '<li class="wpd-no-events">' . esc_html__( 'No festivals found.', 'wp-dansal' ) . '</li>';
		}
		$now = current_time( 'Y-m-d\TH:i' );
		foreach ( $groups as $group ) {
			$event    = $group['latest'];
			$title    = isset( $event['title'] ) ? (string) $event['title'] : '';
			$start    = isset( $event['start_time'] ) ? (string) $event['start_time'] : '';
			$is_past  = $start && strcmp( $start, $now ) < 0;
			$loc      = isset( $event['location'] ) && is_array( $event['location'] ) ? $event['location'] : array();
			$loc_name = isset( $loc['name'] ) ? (string) $loc['name'] : ( isset( $loc['location'] ) ? (string) $loc['location'] : '' );
			$loc_id   = isset( $loc['id'] ) ? absint( $loc['id'] ) : 0;
			?>
			<li class="wpd-festival-item<?php echo $is_past ? ' wpd-festival-past' : ''; ?>">
				<a class="wpd-festival-title" href="<?php echo esc_url( $this->remote_event_url( $event ) ); ?>"><?php echo esc_html( $title ); ?></a>
				<?php if ( '' !== $loc_name ) : ?>
					<span class="wpd-festival-venue">
						@
						<?php if ( $loc_id ) : ?>
							<a href="<?php echo esc_url( $this->remote_location_url( $loc_id ) ); ?>"><?php echo esc_html( $loc_name ); ?></a>
						<?php else : ?>
							<?php echo esc_html( $loc_name ); ?>
						<?php endif; ?>
					</span>
				<?php endif; ?>
				<span class="wpd-festival-date"><?php echo esc_html( $this->format_datetime( $start, get_option( 'date_format' ) ) ); ?></span>
				<?php if ( $is_past ) : ?>
					<span class="wpd-festival-last-edition">
						<?php
						/* translators: %s: latest edition date */
						printf( esc_html__( 'Last edition: %s', 'wp-dansal' ), esc_html( $this->format_datetime( $start, get_option( 'date_format' ) ) ) );
						?>
					</span>
				<?php endif; ?>
			</li>
			<?php
		}
		echo '</ul>';
		return ob_get_clean();
	}

	/**
	 * Run the dansal remote-proximity query at the given lat/lon/radius and
	 * return the inner markup (map, list, or both) — shared by the initial
	 * server render (shortcode_nearby) and the AJAX swap (ajax_nearby).
	 */
	private function render_nearby_from_coords( $atts ) {
		$query = array(
			'lat'          => (float) $atts['lat'],
			'lon'          => (float) $atts['lon'],
			'radius_km'    => (float) $atts['radius_km'],
			'include_past' => 'false',
		);
		if ( ! empty( $atts['tag'] ) ) {
			$query['tag'] = $atts['tag'];
		}

		$fetch_atts = array(
			'org'             => array(),
			'exclude_own_org' => ! empty( $atts['exclude_own_org'] ) ? 1 : 0,
		);
		$events = $this->fetch_remote_events( $fetch_atts, $query, (int) $atts['limit'] );
		$events = array_slice( $events, 0, (int) $atts['limit'] );

		$view = isset( $atts['view'] ) ? $atts['view'] : 'map+list';

		if ( 'list' === $view ) {
			return $this->render_remote_events_list_markup( $events );
		}

		$this->enqueue_leaflet();
		$points = $this->group_remote_events_by_location( $events );
		$map    = $this->render_map_markup( $points );

		if ( 'map' === $view ) {
			return $map;
		}
		return $map . $this->render_remote_events_list_markup( $events );
	}

	private function event_type_label( $type ) {
		$labels = array(
			'ball'     => __( 'Ball', 'wp-dansal' ),
			'workshop' => __( 'Workshop', 'wp-dansal' ),
			'festival' => __( 'Festival', 'wp-dansal' ),
			'other'    => __( 'Other', 'wp-dansal' ),
		);
		return isset( $labels[ $type ] ) ? $labels[ $type ] : (string) $type;
	}

	private function render_event_card( $post_id ) {
		$start     = get_post_meta( $post_id, '_wpd_start_time', true );
		$end       = get_post_meta( $post_id, '_wpd_end_time', true );
		$loc_id    = get_post_meta( $post_id, '_wpd_location_post_id', true );
		$cancelled = '1' === get_post_meta( $post_id, '_wpd_is_cancelled', true );
		?>
		<article class="wpd-event-card<?php echo $cancelled ? ' wpd-cancelled' : ''; ?>">
			<div class="wpd-event-date"><?php echo esc_html( $this->format_datetime( $start ) ); ?></div>
			<h3 class="wpd-event-title"><a href="<?php echo esc_url( get_permalink( $post_id ) ); ?>"><?php echo esc_html( get_the_title( $post_id ) ); ?></a></h3>
			<?php if ( $cancelled ) : ?>
				<p class="wpd-cancelled-badge"><?php esc_html_e( 'Cancelled', 'wp-dansal' ); ?></p>
			<?php endif; ?>
			<?php if ( $loc_id ) : ?>
				<p class="wpd-event-location"><a href="<?php echo esc_url( get_permalink( $loc_id ) ); ?>"><?php echo esc_html( get_the_title( $loc_id ) ); ?></a></p>
			<?php endif; ?>
		</article>
		<?php
	}

	/**
	 * @return string[] Active type keys ('ball'/'workshop'/'festival'), or
	 *                   array( 'other' ) if the event has none of those flags.
	 *                   An event can be more than one (e.g. a ball with a
	 *                   workshop beforehand), matching how dansal itself
	 *                   allows all three flags simultaneously.
	 */
	private function event_type_keys( $post_id ) {
		$flags  = array(
			'ball'     => '1' === get_post_meta( $post_id, '_wpd_has_ball', true ),
			'workshop' => '1' === get_post_meta( $post_id, '_wpd_has_workshop', true ),
			'festival' => '1' === get_post_meta( $post_id, '_wpd_has_festival', true ),
		);
		$active = array_keys( array_filter( $flags ) );
		return $active ? $active : array( 'other' );
	}

	private function format_datetime( $value, $format = null ) {
		if ( ! $value ) {
			return '';
		}
		if ( null === $format ) {
			$format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		}
		$dt = date_create( $value );
		return $dt ? date_i18n( $format, $dt->getTimestamp() ) : $value;
	}

	private function render_calendar( $atts ) {
		$month = $atts['month'] ? absint( $atts['month'] ) : (int) current_time( 'n' );
		$year  = $atts['year'] ? absint( $atts['year'] ) : (int) current_time( 'Y' );

		$first_day     = sprintf( '%04d-%02d-01T00:00', $year, $month );
		$days_in_month = (int) gmdate( 't', mktime( 0, 0, 0, $month, 1, $year ) );
		$last_day      = sprintf( '%04d-%02d-%02dT23:59', $year, $month, $days_in_month );

		$meta_query   = $this->base_meta_query( $atts );
		$meta_query[] = array(
			'key' => '_wpd_start_time',
			'value' => array( $first_day, $last_day ),
			'compare' => 'BETWEEN',
		);

		$query = new WP_Query(
            array(
				'post_type'      => WPD_CPT_Event::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'meta_key'       => '_wpd_start_time',
				'orderby'        => 'meta_value',
				'order'          => 'ASC',
				'meta_query'     => $meta_query,
            )
        );

		$by_day = array();
		while ( $query->have_posts() ) {
			$query->the_post();
			$id               = get_the_ID();
			$day              = (int) substr( get_post_meta( $id, '_wpd_start_time', true ), 8, 2 );
			$by_day[ $day ][] = $id;
		}
		wp_reset_postdata();

		$first_weekday = (int) gmdate( 'N', mktime( 0, 0, 0, $month, 1, $year ) ); // 1 (Mon) .. 7 (Sun)

		ob_start();
		?>
		<div class="wpd-calendar">
			<h3 class="wpd-calendar-title"><?php echo esc_html( date_i18n( 'F Y', mktime( 0, 0, 0, $month, 1, $year ) ) ); ?></h3>
			<div class="wpd-calendar-legend">
				<?php
				foreach (
					array(
						'ball'     => __( 'Ball', 'wp-dansal' ),
						'workshop' => __( 'Workshop', 'wp-dansal' ),
						'festival' => __( 'Festival', 'wp-dansal' ),
						'other'    => __( 'Other', 'wp-dansal' ),
					) as $type => $label
				) :
					?>
					<span class="wpd-calendar-legend-item"><i class="wpd-mini-dot wpd-mini-dot-<?php echo esc_attr( $type ); ?>"></i> <?php echo esc_html( $label ); ?></span>
				<?php endforeach; ?>
			</div>
			<table class="wpd-calendar-table">
				<thead><tr>
					<?php
					global $wp_locale;
					for ( $i = 1; $i <= 7; $i++ ) :
						$wp_day = $i % 7;
						$abbrev = $wp_locale ? $wp_locale->get_weekday_abbrev( $wp_locale->get_weekday( $wp_day ) ) : gmdate( 'D', mktime( 0, 0, 0, 1, 5 + $i, 2025 ) );
						?>
						<th><?php echo esc_html( $abbrev ); ?></th>
					<?php endfor; ?>
				</tr></thead>
				<tbody><tr>
					<?php for ( $i = 1; $i < $first_weekday; $i++ ) : ?>
						<td class="wpd-empty"></td>
					<?php endfor; ?>
					<?php
					for ( $day = 1; $day <= $days_in_month; $day++ ) :
						if ( ( $day + $first_weekday - 2 ) % 7 === 0 && $day > 1 ) :
							echo '</tr><tr>';
						endif;
						?>
						<td class="<?php echo isset( $by_day[ $day ] ) ? 'wpd-has-events' : ''; ?>">
							<div class="wpd-day-number"><?php echo esc_html( $day ); ?></div>
							<?php if ( isset( $by_day[ $day ] ) ) : ?>
								<ul class="wpd-day-events">
									<?php foreach ( $by_day[ $day ] as $eid ) : ?>
										<?php $types = $this->event_type_keys( $eid ); ?>
										<li class="wpd-day-event wpd-day-event-<?php echo esc_attr( $types[0] ); ?>">
											<a href="<?php echo esc_url( get_permalink( $eid ) ); ?>"><?php echo esc_html( get_the_title( $eid ) ); ?></a>
										</li>
									<?php endforeach; ?>
								</ul>
							<?php endif; ?>
						</td>
					<?php endfor; ?>
				</tr></tbody>
			</table>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Remote counterpart to render_calendar() — same markup, but the month's
	 * events come from GET /api/v1/events (start_time_after/before bounding
	 * the month) instead of a local WP_Query. include_past is always forced
	 * on so browsing to a past month still shows its events, matching
	 * render_calendar()'s unconditional BETWEEN on _wpd_start_time.
	 */
	private function render_calendar_remote( $atts ) {
		$month         = $atts['month'] ? absint( $atts['month'] ) : (int) current_time( 'n' );
		$year          = $atts['year'] ? absint( $atts['year'] ) : (int) current_time( 'Y' );
		$days_in_month = (int) gmdate( 't', mktime( 0, 0, 0, $month, 1, $year ) );

		$query                      = $this->build_remote_query( $atts );
		$query['include_past']      = 'true';
		$query['start_time_after']  = mktime( 0, 0, 0, $month, 1, $year ) - 1;
		$query['start_time_before'] = mktime( 23, 59, 59, $month, $days_in_month, $year ) + 1;

		$events = $this->fetch_remote_events( $atts, $query, 1000 );

		$by_day = array();
		foreach ( $events as $event ) {
			$day = (int) substr( (string) ( isset( $event['start_time'] ) ? $event['start_time'] : '' ), 8, 2 );
			if ( $day >= 1 && $day <= 31 ) {
				$by_day[ $day ][] = $event;
			}
		}

		$first_weekday = (int) gmdate( 'N', mktime( 0, 0, 0, $month, 1, $year ) ); // 1 (Mon) .. 7 (Sun)

		ob_start();
		?>
		<div class="wpd-calendar">
			<h3 class="wpd-calendar-title"><?php echo esc_html( date_i18n( 'F Y', mktime( 0, 0, 0, $month, 1, $year ) ) ); ?></h3>
			<div class="wpd-calendar-legend">
				<?php
				foreach (
					array(
						'ball'     => __( 'Ball', 'wp-dansal' ),
						'workshop' => __( 'Workshop', 'wp-dansal' ),
						'festival' => __( 'Festival', 'wp-dansal' ),
						'other'    => __( 'Other', 'wp-dansal' ),
					) as $type => $label
				) :
					?>
					<span class="wpd-calendar-legend-item"><i class="wpd-mini-dot wpd-mini-dot-<?php echo esc_attr( $type ); ?>"></i> <?php echo esc_html( $label ); ?></span>
				<?php endforeach; ?>
			</div>
			<table class="wpd-calendar-table">
				<thead><tr>
					<?php
					global $wp_locale;
					for ( $i = 1; $i <= 7; $i++ ) :
						$wp_day = $i % 7;
						$abbrev = $wp_locale ? $wp_locale->get_weekday_abbrev( $wp_locale->get_weekday( $wp_day ) ) : gmdate( 'D', mktime( 0, 0, 0, 1, 5 + $i, 2025 ) );
						?>
						<th><?php echo esc_html( $abbrev ); ?></th>
					<?php endfor; ?>
				</tr></thead>
				<tbody><tr>
					<?php for ( $i = 1; $i < $first_weekday; $i++ ) : ?>
						<td class="wpd-empty"></td>
					<?php endfor; ?>
					<?php
					for ( $day = 1; $day <= $days_in_month; $day++ ) :
						if ( ( $day + $first_weekday - 2 ) % 7 === 0 && $day > 1 ) :
							echo '</tr><tr>';
						endif;
						?>
						<td class="<?php echo isset( $by_day[ $day ] ) ? 'wpd-has-events' : ''; ?>">
							<div class="wpd-day-number"><?php echo esc_html( $day ); ?></div>
							<?php if ( isset( $by_day[ $day ] ) ) : ?>
								<ul class="wpd-day-events">
									<?php foreach ( $by_day[ $day ] as $event ) : ?>
										<?php $types = $this->event_type_keys_remote( $event ); ?>
										<li class="wpd-day-event wpd-day-event-<?php echo esc_attr( $types[0] ); ?>">
											<a href="<?php echo esc_url( $this->remote_event_url( $event ) ); ?>"><?php echo esc_html( isset( $event['title'] ) ? $event['title'] : '' ); ?></a>
										</li>
									<?php endforeach; ?>
								</ul>
							<?php endif; ?>
						</td>
					<?php endfor; ?>
				</tr></tbody>
			</table>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Compact month grid for sidebar widget areas: day numbers plus small
	 * dot markers (one per event type present that day) instead of the
	 * full day-by-day title list rendered by render_calendar().
	 */
	private function render_mini_calendar( $atts ) {
		$this->enqueue_mini_calendar_script();
		$month = $atts['month'] ? absint( $atts['month'] ) : (int) current_time( 'n' );
		$year  = $atts['year'] ? absint( $atts['year'] ) : (int) current_time( 'Y' );

		$first_day     = sprintf( '%04d-%02d-01T00:00', $year, $month );
		$days_in_month = (int) gmdate( 't', mktime( 0, 0, 0, $month, 1, $year ) );
		$last_day      = sprintf( '%04d-%02d-%02dT23:59', $year, $month, $days_in_month );

		$meta_query   = $this->base_meta_query( $atts );
		$meta_query[] = array(
			'key' => '_wpd_start_time',
			'value' => array( $first_day, $last_day ),
			'compare' => 'BETWEEN',
		);

		$query = new WP_Query(
            array(
				'post_type'      => WPD_CPT_Event::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'meta_key'       => '_wpd_start_time',
				'orderby'        => 'meta_value',
				'order'          => 'ASC',
				'meta_query'     => $meta_query,
            )
        );

		$types_by_day = array();
		while ( $query->have_posts() ) {
			$query->the_post();
			$id  = get_the_ID();
			$day = (int) substr( get_post_meta( $id, '_wpd_start_time', true ), 8, 2 );

			foreach ( $this->event_type_keys( $id ) as $type ) {
				$types_by_day[ $day ][ $type ][] = get_the_title( $id );
			}
		}
		wp_reset_postdata();

		$first_weekday = (int) gmdate( 'N', mktime( 0, 0, 0, $month, 1, $year ) ); // 1 (Mon) .. 7 (Sun)

		$archive_url = get_post_type_archive_link( WPD_CPT_Event::POST_TYPE );
		$prev_month  = 1 === $month ? 12 : $month - 1;
		$prev_year   = 1 === $month ? $year - 1 : $year;
		$next_month  = 12 === $month ? 1 : $month + 1;
		$next_year   = 12 === $month ? $year + 1 : $year;

		ob_start();
		?>
		<div class="wpd-mini-calendar">
			<div class="wpd-mini-nav">
				<?php if ( $archive_url ) : ?>
					<a class="wpd-mini-prev" data-wpd-month="<?php echo esc_attr( $prev_month ); ?>" data-wpd-year="<?php echo esc_attr( $prev_year ); ?>" href="<?php echo esc_url( add_query_arg( array( 'wpd_view' => 'calendar', 'wpd_month' => $prev_month, 'wpd_year' => $prev_year ), $archive_url ) ); ?>">&laquo;</a>
				<?php endif; ?>
				<span class="wpd-mini-title"><?php echo esc_html( date_i18n( 'F Y', mktime( 0, 0, 0, $month, 1, $year ) ) ); ?></span>
				<?php if ( $archive_url ) : ?>
					<a class="wpd-mini-next" data-wpd-month="<?php echo esc_attr( $next_month ); ?>" data-wpd-year="<?php echo esc_attr( $next_year ); ?>" href="<?php echo esc_url( add_query_arg( array( 'wpd_view' => 'calendar', 'wpd_month' => $next_month, 'wpd_year' => $next_year ), $archive_url ) ); ?>">&raquo;</a>
				<?php endif; ?>
			</div>
			<div class="wpd-mini-grid">
				<?php
				global $wp_locale;
				for ( $i = 1; $i <= 7; $i++ ) :
					$wp_day = $i % 7;
					$initial = $wp_locale ? $wp_locale->get_weekday_initial( $wp_locale->get_weekday( $wp_day ) ) : gmdate( 'D', mktime( 0, 0, 0, 1, 5 + $i, 2025 ) )[0];
					?>
					<span class="wpd-mini-dow"><?php echo esc_html( $initial ); ?></span>
				<?php endfor; ?>
				<?php for ( $i = 1; $i < $first_weekday; $i++ ) : ?>
					<span class="wpd-mini-day wpd-mini-empty"></span>
				<?php endfor; ?>
				<?php for ( $day = 1; $day <= $days_in_month; $day++ ) : ?>
					<span class="wpd-mini-day">
						<span class="wpd-mini-daynum"><?php echo esc_html( $day ); ?></span>
						<?php if ( isset( $types_by_day[ $day ] ) ) : ?>
							<span class="wpd-mini-markers">
								<?php foreach ( $types_by_day[ $day ] as $type => $titles ) : ?>
									<i class="wpd-mini-dot wpd-mini-dot-<?php echo esc_attr( $type ); ?>" title="<?php echo esc_attr( implode( ', ', $titles ) ); ?>"></i>
								<?php endforeach; ?>
							</span>
						<?php endif; ?>
					</span>
				<?php endfor; ?>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * [dansal_locations]
	 */
	public function shortcode_locations( $atts = array() ) {
		$atts = shortcode_atts(
			array(
				'tag'      => '',
				'country'  => '',
				'location' => '',
			),
			(array) $atts,
			'dansal_locations'
		);
		$atts['tag']      = sanitize_key( $atts['tag'] );
		$atts['country']  = $this->sanitize_country_list( $atts['country'] );
		$atts['location'] = absint( $atts['location'] );

		$this->enqueue_frontend_style();
		$this->enqueue_leaflet();

		$query_args = array(
			'post_type'      => WPD_CPT_Location::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
		);

		if ( $atts['location'] ) {
			$query_args['post__in'] = array( $atts['location'] );
		}

		$meta_query = array( 'relation' => 'AND' );
		if ( '' !== $atts['country'] ) {
			$meta_query[] = array(
				'key'     => '_wpd_country',
				'value'   => explode( ',', $atts['country'] ),
				'compare' => 'IN',
			);
		}

		if ( '' !== $atts['tag'] ) {
			$event_ids = get_posts(
				array(
					'post_type'      => WPD_CPT_Event::POST_TYPE,
					'post_status'    => 'publish',
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'meta_query'     => array(
						array(
							'key'     => '_wpd_tags',
							'value'   => ',' . $atts['tag'] . ',',
							'compare' => 'LIKE',
						),
					),
				)
			);
			$location_ids = array();
			foreach ( $event_ids as $eid ) {
				$lid = (int) get_post_meta( $eid, '_wpd_location_post_id', true );
				if ( $lid ) {
					$location_ids[ $lid ] = true;
				}
			}
			if ( empty( $location_ids ) ) {
				return '<div class="wpd-locations wpd-locations-empty"></div>';
			}
			$ids = array_keys( $location_ids );
			$query_args['post__in'] = isset( $query_args['post__in'] )
				? array_values( array_intersect( $query_args['post__in'], $ids ) )
				: $ids;
			if ( empty( $query_args['post__in'] ) ) {
				return '<div class="wpd-locations wpd-locations-empty"></div>';
			}
		}

		if ( count( $meta_query ) > 1 ) {
			$query_args['meta_query'] = $meta_query;
		}

		$query = new WP_Query( $query_args );

		$points = array();
		ob_start();
		?>
		<div class="wpd-locations">
			<?php
			// Points are collected first so the map container can carry the
			// JSON payload in a data- attribute — no inline <script> means no
			// script-src/unsafe-inline dependency in the host site's CSP.
			$points_html = '';
			ob_start();
			while ( $query->have_posts() ) :
				$query->the_post();
				$id  = get_the_ID();
				$lat = get_post_meta( $id, '_wpd_latitude', true );
				$lng = get_post_meta( $id, '_wpd_longitude', true );
				if ( '' !== $lat && '' !== $lng ) {
					$points[] = array(
						'lat'   => (float) $lat,
						'lng'   => (float) $lng,
						'title' => get_the_title( $id ),
						'url'   => get_permalink( $id ),
					);
				}
				?>
				<li class="wpd-location-item">
					<a href="<?php echo esc_url( get_permalink( $id ) ); ?>"><?php the_title(); ?></a>
					<div class="wpd-location-meta"><?php echo esc_html( get_post_meta( $id, '_wpd_town', true ) ); ?></div>
				</li>
				<?php
			endwhile;
			wp_reset_postdata();
			$points_html = ob_get_clean();
			?>
			<div id="wpd-locations-map" class="wpd-locations-map" data-wpd-points="<?php echo esc_attr( wp_json_encode( $points ) ); ?>" data-wpd-tiles="<?php echo esc_attr( wp_json_encode( $this->tile_config() ) ); ?>"></div>
			<ul class="wpd-locations-list"><?php echo wp_kses_post( $points_html ); ?></ul>
		</div>
		<?php
		return ob_get_clean();
	}
}
