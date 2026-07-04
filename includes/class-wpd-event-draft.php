<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates a `draft` dansal_event with pre-set meta.
 *
 * Every "give the user a new event with some fields pre-filled" surface —
 * clone (#9), new-from-template (#11), series add-date (#12) — funnels
 * through here so post creation, meta filtering and the save-hook dance
 * all live in one place.
 */
class WPD_Event_Draft {

	/**
	 * @param array $meta           meta_key => value. Silently drops keys
	 *                              WPD_Event_Fields doesn't know about, so
	 *                              callers can't accidentally seed foreign
	 *                              meta on the new draft.
	 * @param array $post_overrides Extra wp_insert_post args, e.g. post_title,
	 *                              post_content. post_type / post_status are
	 *                              always forced.
	 * @return int New post ID, or 0 on failure.
	 */
	public static function create( array $meta, array $post_overrides = array() ) {
		$known = array_merge(
			WPD_Event_Fields::overlay_keys(),
			WPD_Event_Fields::per_occurrence_keys()
		);

		$post_args = array_merge(
			array(
				'post_title'   => '',
				'post_content' => '',
			),
			$post_overrides,
			array(
				'post_type'   => WPD_CPT_Event::POST_TYPE,
				'post_status' => 'draft',
			)
		);

		$post_id = wp_insert_post( $post_args, true );
		if ( is_wp_error( $post_id ) || ! $post_id ) {
			return 0;
		}

		foreach ( $meta as $key => $value ) {
			if ( in_array( $key, $known, true ) ) {
				update_post_meta( $post_id, $key, $value );
			}
		}

		return (int) $post_id;
	}
}
