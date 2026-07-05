<?php
/**
 * Page template: "Dansal: Locations Map".
 *
 * Selectable from Page Attributes → Template so a site owner can place
 * the locations directory + map at a URL and menu position of their
 * own choosing without pasting the shortcode into the page body. The
 * page's own title/content still render — this template only appends
 * the map + list underneath.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_enqueue_style( 'wpd-frontend', WPD_PLUGIN_URL . 'assets/css/frontend.css', array(), wpd_asset_ver( 'assets/css/frontend.css' ) );
wp_enqueue_style( 'wpd-leaflet', WPD_PLUGIN_URL . 'assets/vendor/leaflet/leaflet.css', array(), '1.9.4' );
wp_enqueue_script( 'wpd-leaflet', WPD_PLUGIN_URL . 'assets/vendor/leaflet/leaflet.js', array(), '1.9.4', true );
wp_enqueue_script( 'wpd-map', WPD_PLUGIN_URL . 'assets/js/frontend-map.js', array( 'wpd-leaflet' ), wpd_asset_ver( 'assets/js/frontend-map.js' ), true );

get_header();
?>
<div id="primary" class="content-area">
<main id="main" class="site-main wpd-locations-page">
	<?php
	while ( have_posts() ) :
		the_post();
		?>
		<article <?php post_class(); ?>>
			<header class="entry-header">
				<h1 class="entry-title"><?php the_title(); ?></h1>
			</header>
			<?php if ( get_the_content() ) : ?>
				<div class="entry-content"><?php the_content(); ?></div>
			<?php endif; ?>
			<?php echo do_shortcode( '[dansal_locations]' ); ?>
		</article>
		<?php
	endwhile;
	?>
</main>
</div><!-- #primary -->
<?php
get_sidebar();
get_footer();
