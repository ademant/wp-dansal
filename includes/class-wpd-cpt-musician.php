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
				// #125 slice A — see WPD_CPT_Event::register_post_type() for
				// the trade-off rationale.
				'show_in_rest' => true,
				'rest_base'    => 'musicians',
			)
		);
		$readonly = array(
			'auth_callback' => '__return_false',
			'show_in_rest'  => true,
			'single'        => true,
			'type'          => 'string',
		);
		// #125 slice B: read-safe musician meta. Country / MusicBrainz ID /
		// description are the same three public fields shown on the frontend.
		foreach ( array( '_wpd_country', '_wpd_mbid', '_wpd_description' ) as $key ) {
			register_post_meta( self::POST_TYPE, $key, $readonly );
		}
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
