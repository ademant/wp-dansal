<?php
/**
 * Single dansal_event template.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_enqueue_style( 'wpd-frontend', WPD_PLUGIN_URL . 'assets/css/frontend.css', array(), wpd_asset_ver( 'assets/css/frontend.css' ) );

// Load Leaflet only when we can plausibly draw the map (linked location with
// coordinates); the actual map div is only emitted under the same guard below.
$wpd_map_loc_id = (int) get_post_meta( get_queried_object_id(), '_wpd_location_post_id', true );
$wpd_map_lat    = $wpd_map_loc_id ? get_post_meta( $wpd_map_loc_id, '_wpd_latitude', true ) : '';
$wpd_map_lng    = $wpd_map_loc_id ? get_post_meta( $wpd_map_loc_id, '_wpd_longitude', true ) : '';
if ( '' !== $wpd_map_lat && '' !== $wpd_map_lng ) {
	wp_enqueue_style( 'wpd-leaflet', WPD_PLUGIN_URL . 'assets/vendor/leaflet/leaflet.css', array(), '1.9.4' );
	wp_enqueue_script( 'wpd-leaflet', WPD_PLUGIN_URL . 'assets/vendor/leaflet/leaflet.js', array(), '1.9.4', true );
	wp_enqueue_script( 'wpd-map', WPD_PLUGIN_URL . 'assets/js/frontend-map.js', array( 'wpd-leaflet' ), wpd_asset_ver( 'assets/js/frontend-map.js' ), true );
}

get_header();

while ( have_posts() ) :
	the_post();

	$wpd_post_id  = get_the_ID();
	$start        = get_post_meta( $wpd_post_id, '_wpd_start_time', true );
	$end          = get_post_meta( $wpd_post_id, '_wpd_end_time', true );
	$loc_post_id  = get_post_meta( $wpd_post_id, '_wpd_location_post_id', true );
	$booking_url  = get_post_meta( $wpd_post_id, '_wpd_booking_url', true );
	$tags         = array_filter( explode( ',', get_post_meta( $wpd_post_id, '_wpd_tags', true ) ) );
	$wpd_web_base = wpd_plugin()->settings->get_web_url();
	if ( '' === $wpd_web_base ) {
		$wpd_web_base = untrailingslashit( wpd_plugin()->settings->get_base_url() );
	}
	$wpd_link_people = function ( $names_meta, $ids_meta, $post_type, $web_path ) use ( $wpd_post_id, $wpd_web_base ) {
		$names = array_values( array_filter( explode( '|', (string) get_post_meta( $wpd_post_id, $names_meta, true ) ) ) );
		$ids   = array_values( array_filter( array_map( 'absint', explode( ',', (string) get_post_meta( $wpd_post_id, $ids_meta, true ) ) ) ) );
		$out   = array();
		foreach ( $names as $i => $name ) {
			$did = isset( $ids[ $i ] ) ? (int) $ids[ $i ] : 0;
			$url = '';
			if ( $did ) {
				$local = get_posts( array(
					'post_type'      => $post_type,
					'post_status'    => 'publish',
					'posts_per_page' => 1,
					'meta_key'       => '_wpd_dansal_id',
					'meta_value'     => $did,
					'fields'         => 'ids',
				) );
				$url = $local ? get_permalink( (int) $local[0] ) : ( $wpd_web_base . $web_path . $did );
			}
			$out[] = $url
				? '<a href="' . esc_url( $url ) . '">' . esc_html( $name ) . '</a>'
				: esc_html( $name );
		}
		return $out;
	};
	$musicians   = $wpd_link_people( '_wpd_musician_names', '_wpd_musician_ids', 'dansal_musician', '/musicians/' );
	$instructors = $wpd_link_people( '_wpd_instructor_names', '_wpd_instructor_ids', 'dansal_instructor', '/instructors/' );
	$pricing_type = get_post_meta( $wpd_post_id, '_wpd_pricing_type', true );
	$food         = get_post_meta( $wpd_post_id, '_wpd_food', true );
	$drink        = get_post_meta( $wpd_post_id, '_wpd_drink', true );
	$floor        = get_post_meta( $wpd_post_id, '_wpd_floor_condition', true );
	$cancelled    = '1' === get_post_meta( $wpd_post_id, '_wpd_is_cancelled', true );

	// Parking/floor/amenities usually live on the location, not the event —
	// the event-level fields are only an override for this specific
	// occurrence. Fall back to the linked location's values so this page
	// shows the same effective info as dansal's own event page does.
	$loc_parking     = $loc_post_id ? get_post_meta( $loc_post_id, '_wpd_parking', true ) : '';
	$loc_floor       = $loc_post_id ? get_post_meta( $loc_post_id, '_wpd_floor_condition', true ) : '';
	$effective_floor = $floor ? $floor : $loc_floor;

	$amenity_labels = array(
		'wheelchair' => __( 'Wheelchair accessible', 'wp-dansal' ),
		'bar'        => __( 'Bar', 'wp-dansal' ),
		'kitchen'    => __( 'Kitchen', 'wp-dansal' ),
	);
	$amenities      = array();
	foreach ( $amenity_labels as $attr_key => $attr_label ) {
		$has_it = '1' === get_post_meta( $wpd_post_id, '_wpd_attr_' . $attr_key, true )
			|| ( $loc_post_id && '1' === get_post_meta( $loc_post_id, '_wpd_attr_' . $attr_key, true ) );
		if ( $has_it ) {
			$amenities[] = $attr_label;
		}
	}

	$format_dt = function ( $value ) {
		if ( ! $value ) {
			return '';
		}
		$dt = date_create( $value );
		return $dt ? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $dt->getTimestamp() ) : $value;
	};
	?>
	<div id="primary" class="content-area">
	<main id="main" class="site-main wpd-single-event">
		<article <?php post_class(); ?>>
			<header class="entry-header">
				<h1 class="entry-title"><?php the_title(); ?></h1>
				<?php if ( $cancelled ) : ?>
					<p class="wpd-cancelled-badge"><?php esc_html_e( 'This event has been cancelled.', 'wp-dansal' ); ?></p>
				<?php endif; ?>
				<?php edit_post_link( __( 'Edit event', 'wp-dansal' ), '<p class="wpd-edit-link">', '</p>' ); ?>
			</header>

			<?php
			// Prefer the local Featured Image (the plugin's push source, see
			// push_image()); fall back to dansal's own image_url for events
			// pulled in without ever getting a local attachment.
			$wpd_image_url = get_post_meta( $wpd_post_id, '_wpd_image_url', true );
			if ( has_post_thumbnail( $wpd_post_id ) ) :
				?>
				<div class="wpd-event-image-wrap"><?php echo get_the_post_thumbnail( $wpd_post_id, 'large', array( 'class' => 'wpd-event-image' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
				<?php
			elseif ( $wpd_image_url ) :
				?>
				<div class="wpd-event-image-wrap"><img class="wpd-event-image" src="<?php echo esc_url( $wpd_web_base . $wpd_image_url ); ?>" alt="" /></div>
				<?php
			endif;
			?>

			<?php
			$wpd_room_name = $loc_post_id ? get_post_meta( $wpd_post_id, '_wpd_room_name', true ) : '';
			$wpd_ev_lat    = $loc_post_id ? get_post_meta( $loc_post_id, '_wpd_latitude', true ) : '';
			$wpd_ev_lng    = $loc_post_id ? get_post_meta( $loc_post_id, '_wpd_longitude', true ) : '';
			$wpd_has_map   = '' !== $wpd_ev_lat && '' !== $wpd_ev_lng;
			?>
			<div class="wpd-event-info">
				<table class="wpd-event-table">
					<tbody>
						<tr>
							<th><?php esc_html_e( 'When:', 'wp-dansal' ); ?></th>
							<td><?php echo esc_html( $format_dt( $start ) ); ?> &ndash; <?php echo esc_html( $format_dt( $end ) ); ?></td>
						</tr>
						<?php if ( $loc_post_id ) : ?>
							<tr>
								<th><?php esc_html_e( 'Where:', 'wp-dansal' ); ?></th>
								<td>
									<a href="<?php echo esc_url( get_permalink( $loc_post_id ) ); ?>"><?php echo esc_html( get_the_title( $loc_post_id ) ); ?></a>
									<?php if ( $wpd_room_name ) : ?>
										<span class="wpd-room-name"> — <?php echo esc_html( $wpd_room_name ); ?></span>
									<?php endif; ?>
								</td>
							</tr>
						<?php endif; ?>
						<?php if ( $tags ) : ?>
							<tr>
								<th><?php esc_html_e( 'Tags:', 'wp-dansal' ); ?></th>
								<td><?php echo esc_html( implode( ', ', $tags ) ); ?></td>
							</tr>
						<?php endif; ?>
						<?php if ( $musicians ) : ?>
							<tr>
								<th><?php esc_html_e( 'Musicians:', 'wp-dansal' ); ?></th>
								<?php // Each item in $musicians is already an <a>…</a> with esc_html() on the display text and esc_url() on the href — see $wpd_link_people above; the separator is a static ", ". ?>
								<td><?php echo implode( ', ', $musicians ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
							</tr>
						<?php endif; ?>
						<?php if ( $instructors ) : ?>
							<tr>
								<th><?php esc_html_e( 'Instructors:', 'wp-dansal' ); ?></th>
								<td><?php echo implode( ', ', $instructors ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
							</tr>
						<?php endif; ?>
						<?php if ( $pricing_type ) : ?>
							<tr>
								<th><?php esc_html_e( 'Price:', 'wp-dansal' ); ?></th>
								<td>
									<?php
									if ( 'free' === $pricing_type ) {
										esc_html_e( 'Free', 'wp-dansal' );
									} elseif ( 'donation' === $pricing_type ) {
										esc_html_e( 'Donation', 'wp-dansal' );
									} else {
										echo esc_html( get_post_meta( $wpd_post_id, '_wpd_pricing_amount', true ) . ' ' . get_post_meta( $wpd_post_id, '_wpd_pricing_currency', true ) );
									}
									?>
								</td>
							</tr>
						<?php endif; ?>
						<?php if ( $food || $drink ) : ?>
							<tr>
								<th><?php esc_html_e( 'Food & drink:', 'wp-dansal' ); ?></th>
								<td><?php echo esc_html( trim( WPD_Vocab::label( 'food', $food ) . ' / ' . WPD_Vocab::label( 'drink', $drink ), ' /' ) ); ?></td>
							</tr>
						<?php endif; ?>
						<?php if ( $loc_parking ) : ?>
							<tr>
								<th><?php esc_html_e( 'Parking:', 'wp-dansal' ); ?></th>
								<td><?php echo esc_html( WPD_Vocab::label( 'parking', $loc_parking ) ); ?></td>
							</tr>
						<?php endif; ?>
						<?php if ( $effective_floor ) : ?>
							<tr>
								<th><?php esc_html_e( 'Floor:', 'wp-dansal' ); ?></th>
								<td><?php echo esc_html( WPD_Vocab::label( 'floor_condition', $effective_floor ) ); ?></td>
							</tr>
						<?php endif; ?>
						<?php if ( $amenities ) : ?>
							<tr>
								<th><?php esc_html_e( 'Amenities:', 'wp-dansal' ); ?></th>
								<td><?php echo esc_html( implode( ', ', $amenities ) ); ?></td>
							</tr>
						<?php endif; ?>
					</tbody>
				</table>

				<?php if ( $wpd_has_map ) : ?>
					<?php
					$wpd_ev_points_json = wp_json_encode(
						array(
							array(
								'lat'   => (float) $wpd_ev_lat,
								'lng'   => (float) $wpd_ev_lng,
								'title' => get_the_title( $loc_post_id ),
								'url'   => get_permalink( $loc_post_id ),
							),
						)
					);
					$wpd_ev_tiles_json = wp_json_encode( wpd_plugin()->frontend->tile_config() );
					?>
					<div id="wpd-locations-map" class="wpd-event-map" data-wpd-points="<?php echo esc_attr( $wpd_ev_points_json ); ?>" data-wpd-tiles="<?php echo esc_attr( $wpd_ev_tiles_json ); ?>"></div>
				<?php endif; ?>
			</div>

			<div class="entry-content">
				<?php the_content(); ?>
			</div>

			<?php if ( $booking_url ) : ?>
				<div class="wp-block-button wpd-booking-cta">
					<?php // esc_url — not esc_attr — is required here: it strips javascript:/data: schemes that could otherwise reach a click. ?>
					<a class="wp-block-button__link wp-element-button" href="<?php echo esc_url( $booking_url ); ?>" rel="nofollow noopener"><?php esc_html_e( 'Book / Tickets', 'wp-dansal' ); ?></a>
				</div>
			<?php endif; ?>

			<?php
			$wpd_event_dansal_id = (int) get_post_meta( $wpd_post_id, '_wpd_dansal_id', true );
			if ( $wpd_event_dansal_id ) :
				$wpd_ics_url = $wpd_web_base . '/events/' . $wpd_event_dansal_id . '.ics';
				?>
				<p class="wpd-ical-link"><a href="<?php echo esc_url( $wpd_ics_url ); ?>" rel="nofollow"><?php esc_html_e( 'Add to calendar (.ics)', 'wp-dansal' ); ?></a></p>
			<?php endif; ?>
		</article>
	</main>
	</div><!-- #primary -->
	<?php
endwhile;

get_sidebar();
get_footer();
