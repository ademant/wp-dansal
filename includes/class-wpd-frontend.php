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

	public function __construct( WPD_Settings $settings ) {
		$this->settings = $settings;

		add_shortcode( 'dansal_events', array( $this, 'shortcode_events' ) );
		add_shortcode( 'dansal_locations', array( $this, 'shortcode_locations' ) );
		add_filter( 'single_template', array( $this, 'single_template' ) );
		add_filter( 'archive_template', array( $this, 'archive_template' ) );
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
		wp_enqueue_style( 'wpd-frontend', WPD_PLUGIN_URL . 'assets/css/frontend.css', array(), WPD_VERSION );
	}

	private function enqueue_leaflet() {
		wp_enqueue_style( 'wpd-leaflet', WPD_PLUGIN_URL . 'assets/vendor/leaflet/leaflet.css', array(), '1.9.4' );
		wp_enqueue_script( 'wpd-leaflet', WPD_PLUGIN_URL . 'assets/vendor/leaflet/leaflet.js', array(), '1.9.4', true );
		wp_enqueue_script( 'wpd-map', WPD_PLUGIN_URL . 'assets/js/frontend-map.js', array( 'wpd-leaflet' ), WPD_VERSION, true );
	}

	/**
	 * Tile provider config for the frontend map, filterable so site owners
	 * can point at a self-hosted or paid tile proxy instead of OSM's
	 * public tile server (which sees every visitor's IP + Referer). The
	 * default keeps OSM but sets a strict referrer policy so the page URL
	 * doesn't leak with each tile request. Emitted as a data- attribute
	 * on the map container (see enqueue_leaflet callers) — no inline
	 * <script>, so a strict site CSP still works.
	 */
	public function tile_config() {
		return array(
			'urlTemplate'    => (string) apply_filters( 'wpd_tile_url_template', 'https://tile.openstreetmap.org/{z}/{x}/{y}.png' ),
			'attribution'    => (string) apply_filters( 'wpd_tile_attribution', '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors' ),
			'maxZoom'        => (int) apply_filters( 'wpd_tile_max_zoom', 19 ),
			'referrerPolicy' => (string) apply_filters( 'wpd_tile_referrer_policy', 'no-referrer' ),
		);
	}

	/**
	 * [dansal_events location="123" tag="bal-folk" limit="20" view="list|calendar" show_past="0"]
	 */
	public function shortcode_events( $atts ) {
		$atts = shortcode_atts(
            array(
				'location'  => '',
				'tag'       => '',
				'limit'     => 20,
				'view'      => 'list',
				'show_past' => 0,
				'month'     => '',
				'year'      => '',
            ),
            $atts,
            'dansal_events'
        );

		// Bound every attribute before it reaches WP_Query. Author input from
		// shortcodes can come from lower-trust roles or copy-pasted snippets,
		// so we cap limits, whitelist enums, and coerce IDs/dates.
		$atts['location']  = absint( $atts['location'] );
		$atts['tag']       = sanitize_key( $atts['tag'] );
		$atts['limit']     = max( 1, min( 100, absint( $atts['limit'] ) ) );
		$atts['view']      = in_array( $atts['view'], array( 'list', 'calendar', 'mini' ), true ) ? $atts['view'] : 'list';
		$atts['show_past'] = ! empty( $atts['show_past'] ) && '0' !== (string) $atts['show_past'] ? 1 : 0;
		$month             = absint( $atts['month'] );
		$atts['month']     = ( $month >= 1 && $month <= 12 ) ? $month : '';
		$year              = absint( $atts['year'] );
		$atts['year']      = ( $year >= 1970 && $year <= 2100 ) ? $year : '';

		$this->enqueue_frontend_style();

		if ( 'calendar' === $atts['view'] ) {
			return $this->render_calendar( $atts );
		}
		if ( 'mini' === $atts['view'] ) {
			return $this->render_mini_calendar( $atts );
		}
		return $this->render_list( $atts );
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
		return $meta_query;
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

	private function format_datetime( $value ) {
		if ( ! $value ) {
			return '';
		}
		$dt = date_create( $value );
		return $dt ? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $dt->getTimestamp() ) : $value;
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
					<?php foreach ( array( 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun' ) as $d ) : ?>
						<th><?php echo esc_html( $d ); ?></th>
					<?php endforeach; ?>
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
	 * Compact month grid for sidebar widget areas: day numbers plus small
	 * dot markers (one per event type present that day) instead of the
	 * full day-by-day title list rendered by render_calendar().
	 */
	private function render_mini_calendar( $atts ) {
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
					<a href="
                    <?php
                    echo esc_url(
                        add_query_arg(
                            array(
								'wpd_view' => 'calendar',
								'wpd_month' => $prev_month,
								'wpd_year' => $prev_year,
                            ),
                            $archive_url
                        )
                    );
					?>
                                ">&laquo;</a>
				<?php endif; ?>
				<span class="wpd-mini-title"><?php echo esc_html( date_i18n( 'F Y', mktime( 0, 0, 0, $month, 1, $year ) ) ); ?></span>
				<?php if ( $archive_url ) : ?>
					<a href="
                    <?php
                    echo esc_url(
                        add_query_arg(
                            array(
								'wpd_view' => 'calendar',
								'wpd_month' => $next_month,
								'wpd_year' => $next_year,
                            ),
                            $archive_url
                        )
                    );
					?>
                                ">&raquo;</a>
				<?php endif; ?>
			</div>
			<div class="wpd-mini-grid">
				<?php foreach ( array( 'M', 'T', 'W', 'T', 'F', 'S', 'S' ) as $d ) : ?>
					<span class="wpd-mini-dow"><?php echo esc_html( $d ); ?></span>
				<?php endforeach; ?>
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
		// [dansal_locations] has no attributes today, but call shortcode_atts
		// with an empty schema so unknown attrs are dropped rather than
		// silently passed on to future callers.
		shortcode_atts( array(), (array) $atts, 'dansal_locations' );

		$this->enqueue_frontend_style();
		$this->enqueue_leaflet();

		$query = new WP_Query(
            array(
				'post_type'      => WPD_CPT_Location::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
            )
        );

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
			<ul class="wpd-locations-list"><?php echo $points_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — pre-escaped list items ?></ul>
		</div>
		<?php
		return ob_get_clean();
	}
}
