<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers one admin submenu item per "preset" — templates in #11, series
 * in #12 — under the same parent slug. Each submenu item deep-links into a
 * nonced admin action URL so clicking it creates a new draft pre-filled
 * from that preset.
 *
 * WordPress recognises a submenu whose menu_slug contains "?" as an external
 * URL, which is what makes this work without a per-preset callback.
 */
class WPD_Preset_Menu {

	/**
	 * @param string   $parent_slug    Menu parent, e.g.
	 *                                 "edit.php?post_type=dansal_event".
	 * @param callable $presets        Returns array of ['slug' => …, 'name' => …].
	 * @param string   $action_slug    Admin action slug, e.g.
	 *                                 "wpd_new_from_template".
	 * @param string   $preset_key     Query arg name (e.g. "template", "series").
	 * @param string   $label_template sprintf template applied to 'name',
	 *                                 e.g. __('Add %s'). Same signature as
	 *                                 WPD_Preset_Buttons so callers share
	 *                                 one preset-list callback for both.
	 * @param string   $capability     Capability required to see the submenu.
	 */
	public static function register( $parent_slug, callable $presets, $action_slug, $preset_key, $label_template, $capability = 'edit_posts' ) {
		add_action(
			'admin_menu',
			function () use ( $parent_slug, $presets, $action_slug, $preset_key, $label_template, $capability ) {
				foreach ( call_user_func( $presets ) as $preset ) {
					if ( empty( $preset['slug'] ) || empty( $preset['name'] ) ) {
						continue;
					}
					$label = sprintf( $label_template, $preset['name'] );
					$url   = WPD_Admin_Action::url( $action_slug, array( $preset_key => $preset['slug'] ) );
					add_submenu_page(
						$parent_slug,
						$label,
						$label,
						$capability,
						$url
					);
				}
			}
		);
	}
}
