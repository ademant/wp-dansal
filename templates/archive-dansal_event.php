<?php
/**
 * Archive template for dansal_event: calendar (default) or list view,
 * with a view toggle and, in calendar view, month navigation.
 *
 * View/month/year navigation is intentionally GET-only (idempotent, safely
 * cacheable, no side effects) and uses add_query_arg + absint on the
 * inputs — no nonce required and no state-changing action. Keep it that
 * way: do not switch these links to POST or add write side effects to the
 * archive request path.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_enqueue_style( 'wpd-frontend', WPD_PLUGIN_URL . 'assets/css/frontend.css', array(), wpd_asset_ver( 'assets/css/frontend.css' ) );

$wpd_view  = isset( $_GET['wpd_view'] ) && 'list' === $_GET['wpd_view'] ? 'list' : 'calendar'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$wpd_month = isset( $_GET['wpd_month'] ) ? absint( $_GET['wpd_month'] ) : (int) current_time( 'n' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$wpd_year  = isset( $_GET['wpd_year'] ) ? absint( $_GET['wpd_year'] ) : (int) current_time( 'Y' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
if ( $wpd_month < 1 || $wpd_month > 12 ) {
	$wpd_month = (int) current_time( 'n' );
}

$wpd_archive_url = get_post_type_archive_link( WPD_CPT_Event::POST_TYPE );

$wpd_prev_month = $wpd_month - 1;
$wpd_prev_year  = $wpd_year;
if ( $wpd_prev_month < 1 ) {
	$wpd_prev_month = 12;
	--$wpd_prev_year;
}
$wpd_next_month = $wpd_month + 1;
$wpd_next_year  = $wpd_year;
if ( $wpd_next_month > 12 ) {
	$wpd_next_month = 1;
	++$wpd_next_year;
}

get_header();
?>
<div id="primary" class="content-area">
<main id="main" class="site-main wpd-events-archive">
	<header class="entry-header">
		<h1 class="entry-title"><?php post_type_archive_title(); ?></h1>
	</header>

	<nav class="wpd-view-toggle">
		<a href="
        <?php
        echo esc_url(
            add_query_arg(
                array(
					'wpd_view' => 'calendar',
					'wpd_month' => $wpd_month,
					'wpd_year' => $wpd_year,
                ),
                $wpd_archive_url
            )
        );
		?>
        " class="<?php echo 'calendar' === $wpd_view ? 'wpd-active' : ''; ?>"><?php esc_html_e( 'Calendar', 'wp-dansal' ); ?></a>
		&nbsp;|&nbsp;
		<a href="<?php echo esc_url( add_query_arg( array( 'wpd_view' => 'list' ), $wpd_archive_url ) ); ?>" class="<?php echo 'list' === $wpd_view ? 'wpd-active' : ''; ?>"><?php esc_html_e( 'List', 'wp-dansal' ); ?></a>
	</nav>

	<?php if ( 'calendar' === $wpd_view ) : ?>
		<nav class="wpd-month-nav">
			<a href="
            <?php
            echo esc_url(
                add_query_arg(
                    array(
						'wpd_view' => 'calendar',
						'wpd_month' => $wpd_prev_month,
						'wpd_year' => $wpd_prev_year,
                    ),
                    $wpd_archive_url
                )
            );
			?>
                        ">&laquo; <?php esc_html_e( 'Previous month', 'wp-dansal' ); ?></a>
			&nbsp;|&nbsp;
			<a href="
            <?php
            echo esc_url(
                add_query_arg(
                    array(
						'wpd_view' => 'calendar',
						'wpd_month' => $wpd_next_month,
						'wpd_year' => $wpd_next_year,
                    ),
                    $wpd_archive_url
                )
            );
			?>
                        "><?php esc_html_e( 'Next month', 'wp-dansal' ); ?> &raquo;</a>
		</nav>
	<?php endif; ?>

	<?php echo do_shortcode( '[dansal_events view="' . esc_attr( $wpd_view ) . '" month="' . absint( $wpd_month ) . '" year="' . absint( $wpd_year ) . '"]' ); ?>
</main>
</div><!-- #primary -->
<?php
get_sidebar();
get_footer();
