<?php
/**
 * Single dansal_event template.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_enqueue_style( 'wpd-frontend', WPD_PLUGIN_URL . 'assets/css/frontend.css', array(), WPD_VERSION );

get_header();

while ( have_posts() ) :
	the_post();

	$wpd_post_id  = get_the_ID();
	$start        = get_post_meta( $wpd_post_id, '_wpd_start_time', true );
	$end          = get_post_meta( $wpd_post_id, '_wpd_end_time', true );
	$loc_post_id  = get_post_meta( $wpd_post_id, '_wpd_location_post_id', true );
	$booking_url  = get_post_meta( $wpd_post_id, '_wpd_booking_url', true );
	$tags         = array_filter( explode( ',', get_post_meta( $wpd_post_id, '_wpd_tags', true ) ) );
	$musicians    = array_filter( explode( '|', get_post_meta( $wpd_post_id, '_wpd_musician_names', true ) ) );
	$instructors  = array_filter( explode( '|', get_post_meta( $wpd_post_id, '_wpd_instructor_names', true ) ) );
	$pricing_type = get_post_meta( $wpd_post_id, '_wpd_pricing_type', true );
	$food         = get_post_meta( $wpd_post_id, '_wpd_food', true );
	$drink        = get_post_meta( $wpd_post_id, '_wpd_drink', true );
	$floor        = get_post_meta( $wpd_post_id, '_wpd_floor_condition', true );
	$cancelled    = '1' === get_post_meta( $wpd_post_id, '_wpd_is_cancelled', true );
	$difficulty   = get_post_meta( $wpd_post_id, '_wpd_workshop_difficulty', true );

	$format_dt = function ( $value ) {
		if ( ! $value ) {
			return '';
		}
		$dt = date_create( $value );
		return $dt ? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $dt->getTimestamp() ) : $value;
	};
	?>
	<main id="primary" class="site-main wpd-single-event">
		<article <?php post_class(); ?>>
			<header class="entry-header">
				<h1 class="entry-title"><?php the_title(); ?></h1>
				<?php if ( $cancelled ) : ?>
					<p class="wpd-cancelled-badge"><?php esc_html_e( 'This event has been cancelled.', 'wp-dansal' ); ?></p>
				<?php endif; ?>
			</header>

			<div class="wpd-meta-row"><strong><?php esc_html_e( 'When:', 'wp-dansal' ); ?></strong> <?php echo esc_html( $format_dt( $start ) ); ?> &ndash; <?php echo esc_html( $format_dt( $end ) ); ?></div>

			<?php if ( $loc_post_id ) : ?>
				<div class="wpd-meta-row"><strong><?php esc_html_e( 'Where:', 'wp-dansal' ); ?></strong> <a href="<?php echo esc_url( get_permalink( $loc_post_id ) ); ?>"><?php echo esc_html( get_the_title( $loc_post_id ) ); ?></a></div>
			<?php endif; ?>

			<?php if ( $difficulty ) : ?>
				<div class="wpd-meta-row"><strong><?php esc_html_e( 'Difficulty:', 'wp-dansal' ); ?></strong> <?php echo esc_html( $difficulty ); ?></div>
			<?php endif; ?>

			<?php if ( $tags ) : ?>
				<div class="wpd-meta-row"><strong><?php esc_html_e( 'Tags:', 'wp-dansal' ); ?></strong> <?php echo esc_html( implode( ', ', $tags ) ); ?></div>
			<?php endif; ?>

			<?php if ( $musicians ) : ?>
				<div class="wpd-meta-row"><strong><?php esc_html_e( 'Musicians:', 'wp-dansal' ); ?></strong> <?php echo esc_html( implode( ', ', $musicians ) ); ?></div>
			<?php endif; ?>

			<?php if ( $instructors ) : ?>
				<div class="wpd-meta-row"><strong><?php esc_html_e( 'Instructors:', 'wp-dansal' ); ?></strong> <?php echo esc_html( implode( ', ', $instructors ) ); ?></div>
			<?php endif; ?>

			<?php if ( $pricing_type ) : ?>
				<div class="wpd-meta-row">
					<strong><?php esc_html_e( 'Price:', 'wp-dansal' ); ?></strong>
					<?php
					if ( 'free' === $pricing_type ) {
						esc_html_e( 'Free', 'wp-dansal' );
					} elseif ( 'donation' === $pricing_type ) {
						esc_html_e( 'Donation', 'wp-dansal' );
					} else {
						echo esc_html( get_post_meta( $wpd_post_id, '_wpd_pricing_amount', true ) . ' ' . get_post_meta( $wpd_post_id, '_wpd_pricing_currency', true ) );
					}
					?>
				</div>
			<?php endif; ?>

			<?php if ( $food || $drink ) : ?>
				<div class="wpd-meta-row"><strong><?php esc_html_e( 'Food & drink:', 'wp-dansal' ); ?></strong> <?php echo esc_html( trim( $food . ' / ' . $drink, ' /' ) ); ?></div>
			<?php endif; ?>

			<?php if ( $floor ) : ?>
				<div class="wpd-meta-row"><strong><?php esc_html_e( 'Floor:', 'wp-dansal' ); ?></strong> <?php echo esc_html( $floor ); ?></div>
			<?php endif; ?>

			<div class="entry-content">
				<?php the_content(); ?>
			</div>

			<?php if ( $booking_url ) : ?>
				<div class="wp-block-button wpd-booking-cta">
					<a class="wp-block-button__link wp-element-button" href="<?php echo esc_url( $booking_url ); ?>" rel="nofollow"><?php esc_html_e( 'Book / Tickets', 'wp-dansal' ); ?></a>
				</div>
			<?php endif; ?>
		</article>
	</main>
	<?php
endwhile;

get_footer();
