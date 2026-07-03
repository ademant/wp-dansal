<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Public-facing display: event list/calendar and single-event page render
 * from the locally synced dansal_event/dansal_location CPTs (they already
 * hold everything needed after a save_post sync, so there is no need to
 * re-fetch dansal live on every page view).
 */
class WPD_Frontend {

	/** @var WPD_Settings */
	private $settings;

	public function __construct( WPD_Settings $settings ) {
		$this->settings = $settings;

		add_shortcode( 'dansal_events', array( $this, 'shortcode_events' ) );
		add_shortcode( 'dansal_locations', array( $this, 'shortcode_locations' ) );
		add_filter( 'single_template', array( $this, 'single_template' ) );
	}

	public function single_template( $template ) {
		global $post;
		if ( $post && WPD_CPT_Event::POST_TYPE === $post->post_type ) {
			$custom = WPD_PLUGIN_DIR . 'templates/single-dansal_event.php';
			if ( file_exists( $custom ) ) {
				return $custom;
			}
		}
		if ( $post && WPD_CPT_Location::POST_TYPE === $post->post_type ) {
			$custom = WPD_PLUGIN_DIR . 'templates/single-dansal_location.php';
			if ( file_exists( $custom ) ) {
				return $custom;
			}
		}
		return $template;
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

		$this->enqueue_frontend_style();

		if ( 'calendar' === $atts['view'] ) {
			return $this->render_calendar( $atts );
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
										<li><a href="<?php echo esc_url( get_permalink( $eid ) ); ?>"><?php echo esc_html( get_the_title( $eid ) ); ?></a></li>
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
	 * [dansal_locations]
	 */
	public function shortcode_locations() {
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
			<div id="wpd-locations-map" class="wpd-locations-map"></div>
			<ul class="wpd-locations-list">
				<?php
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
				?>
			</ul>
		</div>
		<script type="application/json" id="wpd-locations-data"><?php echo wp_json_encode( $points ); ?></script>
		<?php
		return ob_get_clean();
	}
}
