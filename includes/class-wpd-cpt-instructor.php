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
				// #125 slice A — see WPD_CPT_Event::register_post_type() for
				// the trade-off rationale.
				'show_in_rest' => true,
				'rest_base'    => 'instructors',
			)
		);
		// The instructor CPT only carries a `_wpd_description` overlay
		// locally (dansal's `bio`); register it for REST so a Query Loop
		// instructor card can render the same text shown on the frontend
		// page. Writable by anyone with edit_post on the target.
		register_post_meta(
			self::POST_TYPE,
			'_wpd_description',
			array(
				'auth_callback' => static function ( $allowed, $meta_key, $object_id ) {
					return current_user_can( 'edit_post', $object_id );
				},
				'show_in_rest'  => true,
				'single'        => true,
				'type'          => 'string',
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
