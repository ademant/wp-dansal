<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPD_CPT_Instructor extends WPD_CPT_Person {

	const POST_TYPE = 'dansal_instructor';

	public function register_post_type() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'       => array(
					'name'          => __( 'Instructors', 'wp-dansal' ),
					'singular_name' => __( 'Instructor', 'wp-dansal' ),
					'add_new_item'  => __( 'Add New Instructor', 'wp-dansal' ),
					'edit_item'     => __( 'Edit Instructor', 'wp-dansal' ),
				),
				'public'       => true,
				'has_archive'  => false,
				'show_in_menu' => WPD_Admin_Menu::SLUG,
				'supports'     => array( 'title' ),
				'rewrite'      => array( 'slug' => 'instructors' ),
				'show_in_rest' => false,
			)
		);
	}

	protected function primary_field() {
		return 'name';
	}

	protected function resource_path() {
		return '/api/v1/instructors';
	}

	protected function field_map() {
		// WP "description" maps to dansal's "bio" — the only fields the plugin
		// surfaces. dansal's website / email are left alone (merge-patch).
		return array(
			'_wpd_description' => 'bio',
		);
	}

	protected function field_labels() {
		return array(
			'_wpd_description' => __( 'Description', 'wp-dansal' ),
		);
	}
}
