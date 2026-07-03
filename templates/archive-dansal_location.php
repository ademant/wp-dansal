<?php
/**
 * Archive template for dansal_location: all synced locations on a map.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_enqueue_style( 'wpd-frontend', WPD_PLUGIN_URL . 'assets/css/frontend.css', array(), WPD_VERSION );
wp_enqueue_style( 'wpd-leaflet', WPD_PLUGIN_URL . 'assets/vendor/leaflet/leaflet.css', array(), '1.9.4' );
wp_enqueue_script( 'wpd-leaflet', WPD_PLUGIN_URL . 'assets/vendor/leaflet/leaflet.js', array(), '1.9.4', true );
wp_enqueue_script( 'wpd-map', WPD_PLUGIN_URL . 'assets/js/frontend-map.js', array( 'wpd-leaflet' ), WPD_VERSION, true );

get_header();
?>
<div id="primary" class="content-area">
<main id="main" class="site-main wpd-locations-archive">
	<header class="entry-header">
		<h1 class="entry-title"><?php post_type_archive_title(); ?></h1>
	</header>

	<?php echo do_shortcode( '[dansal_locations]' ); ?>
</main>
</div><!-- #primary -->
<?php
get_sidebar();
get_footer();
