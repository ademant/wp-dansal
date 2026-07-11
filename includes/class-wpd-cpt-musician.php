<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPD_CPT_Musician extends WPD_CPT_Person {

	const POST_TYPE = 'dansal_musician';

	public function register_post_type() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'       => array(
					'name'          => __( 'Musicians', 'wp-dansal' ),
					'singular_name' => __( 'Musician', 'wp-dansal' ),
					'add_new_item'  => __( 'Add New Musician', 'wp-dansal' ),
					'edit_item'     => __( 'Edit Musician', 'wp-dansal' ),
				),
				'public'       => true,
				'has_archive'  => false,
				'show_in_menu' => WPD_Admin_Menu::SLUG,
				'supports'     => array( 'title' ),
				'rewrite'      => array( 'slug' => 'musicians' ),
				// Classic editor by design (see WPD_CPT_Event note).
				'show_in_rest' => false,
			)
		);
	}

	protected function primary_field() {
		return 'bandname';
	}

	protected function resource_path() {
		return '/api/v1/musicians';
	}

	protected function field_map() {
		return array(
			'_wpd_country'     => 'country',
			'_wpd_mbid'        => 'mbid',
			'_wpd_description' => 'description',
		);
	}

	protected function field_labels() {
		return array(
			'_wpd_country'     => __( 'Country (ISO 3166-1 alpha-2)', 'wp-dansal' ),
			'_wpd_mbid'        => __( 'MusicBrainz ID', 'wp-dansal' ),
			'_wpd_description' => __( 'Description', 'wp-dansal' ),
		);
	}
}
