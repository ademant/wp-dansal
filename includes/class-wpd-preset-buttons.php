<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Injects one "Add {name}" button per preset next to the WordPress
 * .page-title-action ("Add New Event") on the edit.php list screen.
 *
 * Standard admin markup doesn't expose a filter for those buttons, so we
 * emit a small inline script that appends them client-side after DOMReady.
 */
class WPD_Preset_Buttons {

	/**
	 * @param string   $post_type      CPT slug (matches $typenow to gate).
	 * @param callable $presets        Returns array of
	 *                                 ['slug' => …, 'label' => …, 'name' => …].
	 * @param string   $action_slug    Admin action slug.
	 * @param string   $preset_key     Query arg name (e.g. "template", "series").
	 * @param string   $label_template sprintf template, e.g. __('Add %s'). Applied to 'name'.
	 */
	public static function register( $post_type, callable $presets, $action_slug, $preset_key, $label_template ) {
		add_action(
			'admin_head-edit.php',
			function () use ( $post_type, $presets, $action_slug, $preset_key, $label_template ) {
				global $typenow;
				if ( $post_type !== $typenow ) {
					return;
				}

				$items = array();
				foreach ( call_user_func( $presets ) as $preset ) {
					if ( empty( $preset['slug'] ) || empty( $preset['name'] ) ) {
						continue;
					}
					$items[] = array(
						'url'   => WPD_Admin_Action::url( $action_slug, array( $preset_key => $preset['slug'] ) ),
						'label' => sprintf( $label_template, $preset['name'] ),
					);
				}
				if ( ! $items ) {
					return;
				}
				?>
				<script>
				document.addEventListener('DOMContentLoaded', function () {
					var anchor = document.querySelector('.wrap .page-title-action');
					if (!anchor) { return; }
					var items = <?php echo wp_json_encode( $items ); ?>;
					var last = anchor;
					items.forEach(function (item) {
						var a = document.createElement('a');
						a.href = item.url;
						a.className = 'page-title-action';
						a.textContent = item.label;
						last.parentNode.insertBefore(a, last.nextSibling);
						last = a;
					});
				});
				</script>
				<?php
			}
		);
	}
}
