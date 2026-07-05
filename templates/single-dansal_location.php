<?php
/**
 * Single dansal_location template.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_enqueue_style( 'wpd-frontend', WPD_PLUGIN_URL . 'assets/css/frontend.css', array(), WPD_VERSION );
wp_enqueue_style( 'wpd-leaflet', WPD_PLUGIN_URL . 'assets/vendor/leaflet/leaflet.css', array(), '1.9.4' );
wp_enqueue_script( 'wpd-leaflet', WPD_PLUGIN_URL . 'assets/vendor/leaflet/leaflet.js', array(), '1.9.4', true );
wp_enqueue_script( 'wpd-map', WPD_PLUGIN_URL . 'assets/js/frontend-map.js', array( 'wpd-leaflet' ), WPD_VERSION, true );

get_header();

while ( have_posts() ) :
	the_post();

	$wpd_post_id = get_the_ID();
	$address     = get_post_meta( $wpd_post_id, '_wpd_address', true );
	$zipcode     = get_post_meta( $wpd_post_id, '_wpd_zipcode', true );
	$town        = get_post_meta( $wpd_post_id, '_wpd_town', true );
	$country     = get_post_meta( $wpd_post_id, '_wpd_country', true );
	$lat         = get_post_meta( $wpd_post_id, '_wpd_latitude', true );
	$lng         = get_post_meta( $wpd_post_id, '_wpd_longitude', true );
	$website     = get_post_meta( $wpd_post_id, '_wpd_internetsite', true );
	$parking     = get_post_meta( $wpd_post_id, '_wpd_parking', true );
	$floor       = get_post_meta( $wpd_post_id, '_wpd_floor_condition', true );
	$no_shoes    = '1' === get_post_meta( $wpd_post_id, '_wpd_no_street_shoes', true );
	$notes       = get_post_meta( $wpd_post_id, '_wpd_notes_md', true );

	$amenities = array();
	foreach ( array(
		'wheelchair' => __( 'Wheelchair accessible', 'wp-dansal' ),
		'bar' => __( 'Bar', 'wp-dansal' ),
		'kitchen' => __( 'Kitchen', 'wp-dansal' ),
	) as $key => $label ) {
		if ( '1' === get_post_meta( $wpd_post_id, '_wpd_attr_' . $key, true ) ) {
			$amenities[] = $label;
		}
	}
	?>
	<div id="primary" class="content-area">
	<main id="main" class="site-main wpd-single-location">
		<article <?php post_class(); ?>>
			<header class="entry-header">
				<h1 class="entry-title"><?php the_title(); ?></h1>
			</header>

			<div class="wpd-meta-row"><?php echo esc_html( trim( "$address, $zipcode $town, $country", ' ,' ) ); ?></div>

			<?php if ( $website ) : ?>
				<?php // esc_url — not esc_attr — is required here: it strips javascript:/data: schemes that could otherwise reach a click. ?>
				<div class="wpd-meta-row"><a href="<?php echo esc_url( $website ); ?>" rel="nofollow noopener"><?php echo esc_html( $website ); ?></a></div>
			<?php endif; ?>

			<?php if ( '' !== $lat && '' !== $lng ) : ?>
				<?php
				// CSP-friendly: point data rides on a data- attribute instead
				// of an inline <script>, so no script-src exception is needed.
				$wpd_points_json = wp_json_encode(
					array(
						array(
							'lat' => (float) $lat,
							'lng' => (float) $lng,
							'title' => get_the_title(),
							'url' => get_permalink(),
						),
					)
				);
				$wpd_tiles_json  = wp_json_encode( wpd_plugin()->frontend->tile_config() );
				?>
				<div id="wpd-locations-map" class="wpd-single-map" data-wpd-points="<?php echo esc_attr( $wpd_points_json ); ?>" data-wpd-tiles="<?php echo esc_attr( $wpd_tiles_json ); ?>"></div>
			<?php endif; ?>

			<?php if ( $parking ) : ?>
				<div class="wpd-meta-row"><strong><?php esc_html_e( 'Parking:', 'wp-dansal' ); ?></strong> <?php echo esc_html( $parking ); ?></div>
			<?php endif; ?>

			<?php if ( $floor ) : ?>
				<div class="wpd-meta-row"><strong><?php esc_html_e( 'Floor:', 'wp-dansal' ); ?></strong> <?php echo esc_html( $floor ); ?></div>
			<?php endif; ?>

			<?php if ( $no_shoes ) : ?>
				<div class="wpd-meta-row"><?php esc_html_e( 'No street shoes on the dance floor.', 'wp-dansal' ); ?></div>
			<?php endif; ?>

			<?php if ( $amenities ) : ?>
				<div class="wpd-meta-row"><strong><?php esc_html_e( 'Amenities:', 'wp-dansal' ); ?></strong> <?php echo esc_html( implode( ', ', $amenities ) ); ?></div>
			<?php endif; ?>

			<?php if ( $notes ) : ?>
				<div class="entry-content wpd-notes"><?php echo nl2br( esc_html( $notes ) ); ?></div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Upcoming events here', 'wp-dansal' ); ?></h2>
			<?php echo do_shortcode( '[dansal_events location="' . absint( $wpd_post_id ) . '"]' ); ?>
		</article>
	</main>
	</div><!-- #primary -->
	<?php
endwhile;

get_sidebar();
get_footer();
