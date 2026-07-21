<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "dansal_location" custom post type: the WP-side editing surface for a
 * dansal location. Creation flow mirrors dansal's own dedup rules (API.md,
 * "Location sync — check before creating"):
 *
 *   1. Admin searches an address via Nominatim (server-proxied).
 *   2. Picking a result checks dansal for an existing location by exact
 *      osm_id/osm_type, then by lat/lng proximity.
 *   3. If a match exists, the admin is offered "assign my org to the
 *      existing location" instead of creating a duplicate.
 *   4. On save, the post is synced to dansal: assign-org+PATCH for a
 *      matched existing location, POST for a genuinely new one, PATCH for
 *      any location already linked from a previous save.
 */
class WPD_CPT_Location {

	const META_DANSAL_ID      = '_wpd_dansal_id';
	const META_LAST_SYNCED_AT = '_wpd_last_synced_at';
	const POST_TYPE           = 'dansal_location';

	/** @var WPD_Api_Client */
	private $api;
	/** @var WPD_Nominatim */
	private $nominatim;
	/** @var WPD_Settings */
	private $settings;

	public function __construct( WPD_Api_Client $api, WPD_Nominatim $nominatim, WPD_Settings $settings ) {
		$this->api       = $api;
		$this->nominatim = $nominatim;
		$this->settings  = $settings;

		add_action( 'init', array( $this, 'register_post_type' ) );
		add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( $this, 'save' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'wp_ajax_wpd_check_location_duplicate', array( $this, 'ajax_check_duplicate' ) );
		add_action( 'wp_ajax_wpd_list_rooms', array( $this, 'ajax_list_rooms' ) );
		add_action( 'wp_ajax_wpd_add_room', array( $this, 'ajax_add_room' ) );
		add_action( 'wp_ajax_wpd_delete_room', array( $this, 'ajax_delete_room' ) );
		add_action( 'admin_notices', array( $this, 'show_sync_notices' ) );
		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( $this, 'columns' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( $this, 'render_column' ), 10, 2 );
		add_action( 'load-edit.php', array( $this, 'maybe_pull_sync' ) );
	}

	public function register_post_type() {
		register_post_type(
            self::POST_TYPE,
            array(
				'labels'       => array(
					'name'          => __( 'Dance Locations', 'wp-dansal' ),
					'singular_name' => __( 'Dance Location', 'wp-dansal' ),
					'add_new_item'  => __( 'Add New Location', 'wp-dansal' ),
					'edit_item'     => __( 'Edit Location', 'wp-dansal' ),
				),
				'public'       => true,
				'has_archive'  => true,
				'show_in_menu' => WPD_Admin_Menu::SLUG,
				'supports'     => array( 'title', 'editor' ),
				'rewrite'      => array( 'slug' => 'dance-locations' ),
				// Classic editor by design — see the matching comment on
				// WPD_CPT_Event::register_post_type().
				'show_in_rest' => false,
            )
        );
	}

	public function columns( $columns ) {
		$columns['wpd_dansal_id'] = __( 'Dansal ID', 'wp-dansal' );
		$columns['wpd_town']      = __( 'Town', 'wp-dansal' );
		return $columns;
	}

	public function render_column( $column, $post_id ) {
		if ( 'wpd_dansal_id' === $column ) {
			$id = get_post_meta( $post_id, self::META_DANSAL_ID, true );
			echo $id ? esc_html( $id ) : esc_html__( 'not synced', 'wp-dansal' );
		} elseif ( 'wpd_town' === $column ) {
			echo esc_html( get_post_meta( $post_id, '_wpd_town', true ) );
		}
	}

	public function add_meta_boxes() {
		add_meta_box( 'wpd_location_details', __( 'Dansal Location Details', 'wp-dansal' ), array( $this, 'render_meta_box' ), self::POST_TYPE, 'normal', 'high' );
	}

	private function field( $post_id, $key, $default_value = '' ) {
		$v = get_post_meta( $post_id, $key, true );
		return '' === $v ? $default_value : $v;
	}

	public function render_meta_box( $post ) {
		wp_nonce_field( 'wpd_location_save', 'wpd_location_nonce' );
		$dansal_id = get_post_meta( $post->ID, self::META_DANSAL_ID, true );
		$osm_id    = get_post_meta( $post->ID, '_wpd_osm_id', true );
		$has_coords = '' !== $this->field( $post->ID, '_wpd_latitude' ) && '' !== $this->field( $post->ID, '_wpd_longitude' );
		// Already resolved (a specific OSM match, or coordinates set some other
		// way — manual entry, import, etc.) — collapse by default so a
		// resolved location doesn't lead with search UI it rarely needs again.
		$geo_resolved = $osm_id || $has_coords;
		if ( $dansal_id ) {
			printf( '<p><strong>%s%s</strong></p>', esc_html__( 'Synced with dansal location #', 'wp-dansal' ), esc_html( $dansal_id ) );
		}
		?>
		<div id="wpd-location-editor" data-post-id="<?php echo esc_attr( $post->ID ); ?>">
			<details class="wpd-fieldset" open>
				<summary><?php esc_html_e( 'Address & geocoding', 'wp-dansal' ); ?></summary>

				<details class="wpd-fieldset wpd-geo-widget" <?php echo $geo_resolved ? '' : 'open'; ?>>
					<summary>
						<?php
						echo $geo_resolved
							? esc_html__( 'Re-match location (OpenStreetMap / Nominatim)', 'wp-dansal' )
							: esc_html__( 'Find address (OpenStreetMap / Nominatim)', 'wp-dansal' );
						?>
					</summary>
					<?php if ( $dansal_id ) : ?>
						<p class="description"><?php esc_html_e( 'Already synced with dansal — re-matching only updates the fields below on next save, it will not create a second location.', 'wp-dansal' ); ?></p>
					<?php endif; ?>
					<div class="wpd-field-row">
						<label for="wpd-nominatim-q"><?php esc_html_e( 'Search', 'wp-dansal' ); ?></label><br />
						<input type="text" id="wpd-nominatim-q" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. Bürgerhaus Stollwerck, Köln', 'wp-dansal' ); ?>" />
						<button type="button" class="button" id="wpd-nominatim-search"><?php esc_html_e( 'Search', 'wp-dansal' ); ?></button>
						<button type="button" class="button" id="wpd-nominatim-fill"><?php esc_html_e( 'Search from fields below', 'wp-dansal' ); ?></button>
						<button type="button" class="button" id="wpd-nominatim-reverse"><?php esc_html_e( 'Reverse geocode from lat/long', 'wp-dansal' ); ?></button>
					</div>
					<div id="wpd-nominatim-results"></div>
					<div id="wpd-duplicate-results"></div>
					<input type="hidden" id="wpd_use_existing_dansal_id" name="wpd_use_existing_dansal_id" value="" />
					<div id="wpd-location-map" class="wpd-location-map"></div>
					<p class="description"><?php esc_html_e( 'Drag the marker or click the map to fine-tune the coordinates.', 'wp-dansal' ); ?></p>
				</details>

				<table class="form-table">
					<tr>
						<th><label for="wpd_short_name"><?php esc_html_e( 'Short name', 'wp-dansal' ); ?></label></th>
						<td><input type="text" id="wpd_short_name" name="wpd_short_name" class="regular-text" value="<?php echo esc_attr( $this->field( $post->ID, '_wpd_short_name' ) ); ?>" /></td>
					</tr>
					<tr>
						<th><label for="wpd_address"><?php esc_html_e( 'Address', 'wp-dansal' ); ?></label></th>
						<td><input type="text" id="wpd_address" name="wpd_address" class="regular-text" value="<?php echo esc_attr( $this->field( $post->ID, '_wpd_address' ) ); ?>" /></td>
					</tr>
					<tr>
						<th><label for="wpd_zipcode"><?php esc_html_e( 'Zipcode', 'wp-dansal' ); ?></label></th>
						<td><input type="text" id="wpd_zipcode" name="wpd_zipcode" class="small-text" value="<?php echo esc_attr( $this->field( $post->ID, '_wpd_zipcode' ) ); ?>" /></td>
					</tr>
					<tr>
						<th><label for="wpd_town"><?php esc_html_e( 'Town', 'wp-dansal' ); ?></label></th>
						<td><input type="text" id="wpd_town" name="wpd_town" class="regular-text" value="<?php echo esc_attr( $this->field( $post->ID, '_wpd_town', 'Köln' ) ); ?>" /></td>
					</tr>
					<tr>
						<th><label for="wpd_country_code"><?php esc_html_e( 'Country code', 'wp-dansal' ); ?></label></th>
						<td><input type="text" id="wpd_country_code" name="wpd_country_code" maxlength="2" class="small-text" value="<?php echo esc_attr( $this->field( $post->ID, '_wpd_country_code', 'DE' ) ); ?>" /></td>
					</tr>
					<tr>
						<th><label for="wpd_country"><?php esc_html_e( 'Country', 'wp-dansal' ); ?></label></th>
						<td><input type="text" id="wpd_country" name="wpd_country" class="regular-text" value="<?php echo esc_attr( $this->field( $post->ID, '_wpd_country', 'Germany' ) ); ?>" /></td>
					</tr>
					<tr>
						<th><label for="wpd_region"><?php esc_html_e( 'Region', 'wp-dansal' ); ?></label></th>
						<td><input type="text" id="wpd_region" name="wpd_region" class="regular-text" value="<?php echo esc_attr( $this->field( $post->ID, '_wpd_region' ) ); ?>" /></td>
					</tr>
					<tr>
						<th><label for="wpd_latitude"><?php esc_html_e( 'Latitude', 'wp-dansal' ); ?></label></th>
						<td><input type="text" id="wpd_latitude" name="wpd_latitude" class="regular-text" value="<?php echo esc_attr( $this->field( $post->ID, '_wpd_latitude' ) ); ?>" /></td>
					</tr>
					<tr>
						<th><label for="wpd_longitude"><?php esc_html_e( 'Longitude', 'wp-dansal' ); ?></label></th>
						<td><input type="text" id="wpd_longitude" name="wpd_longitude" class="regular-text" value="<?php echo esc_attr( $this->field( $post->ID, '_wpd_longitude' ) ); ?>" /></td>
					</tr>
					<tr>
						<th><label for="wpd_internetsite"><?php esc_html_e( 'Website', 'wp-dansal' ); ?></label></th>
						<td><input type="url" id="wpd_internetsite" name="wpd_internetsite" class="regular-text" value="<?php echo esc_attr( $this->field( $post->ID, '_wpd_internetsite' ) ); ?>" /></td>
					</tr>
				</table>
			</details>

			<details class="wpd-fieldset">
				<summary><?php esc_html_e( 'Details', 'wp-dansal' ); ?></summary>
				<table class="form-table">
					<tr>
						<th><label for="wpd_parking"><?php esc_html_e( 'Parking', 'wp-dansal' ); ?></label></th>
						<td>
							<select id="wpd_parking" name="wpd_parking">
								<option value=""><?php esc_html_e( '— not set —', 'wp-dansal' ); ?></option>
								<?php $current_parking = $this->field( $post->ID, '_wpd_parking' ); ?>
								<?php foreach ( WPD_Vocab::options( 'parking' ) as $slug => $label ) : ?>
									<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $current_parking, $slug ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th><label for="wpd_floor_condition"><?php esc_html_e( 'Floor condition', 'wp-dansal' ); ?></label></th>
						<td>
							<select id="wpd_floor_condition" name="wpd_floor_condition">
								<option value=""><?php esc_html_e( '— not set —', 'wp-dansal' ); ?></option>
								<?php $current_floor = $this->field( $post->ID, '_wpd_floor_condition' ); ?>
								<?php foreach ( WPD_Vocab::options( 'floor_condition' ) as $slug => $label ) : ?>
									<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $current_floor, $slug ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<label style="margin-left:1em;display:inline-block;">
								<input type="checkbox" name="wpd_no_street_shoes" value="1" <?php checked( $this->field( $post->ID, '_wpd_no_street_shoes' ), '1' ); ?> />
								<?php esc_html_e( 'No street shoes', 'wp-dansal' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Amenities', 'wp-dansal' ); ?></th>
						<td>
							<?php
                            foreach ( array(
								'wheelchair' => __( 'Wheelchair accessible', 'wp-dansal' ),
								'bar' => __( 'Bar', 'wp-dansal' ),
								'kitchen' => __( 'Kitchen', 'wp-dansal' ),
							) as $key => $label ) :
								?>
								<label style="display:inline-block;margin-right:1em;">
									<input type="checkbox" name="wpd_attr_<?php echo esc_attr( $key ); ?>" value="1" <?php checked( $this->field( $post->ID, '_wpd_attr_' . $key ), '1' ); ?> />
									<?php echo esc_html( $label ); ?>
								</label>
							<?php endforeach; ?>
						</td>
					</tr>
					<tr>
						<th><label for="wpd_notes_md"><?php esc_html_e( 'Notes (Markdown)', 'wp-dansal' ); ?></label></th>
						<td><textarea id="wpd_notes_md" name="wpd_notes_md" rows="4" class="large-text"><?php echo esc_textarea( $this->field( $post->ID, '_wpd_notes_md' ) ); ?></textarea></td>
					</tr>
				</table>
			</details>

			<input type="hidden" id="wpd_osm_id" name="wpd_osm_id" value="<?php echo esc_attr( $this->field( $post->ID, '_wpd_osm_id' ) ); ?>" />
			<input type="hidden" id="wpd_osm_type" name="wpd_osm_type" value="<?php echo esc_attr( $this->field( $post->ID, '_wpd_osm_type' ) ); ?>" />
			<?php if ( $dansal_id ) : ?>
				<details class="wpd-fieldset">
					<summary><?php esc_html_e( 'Rooms', 'wp-dansal' ); ?></summary>
					<p class="description"><?php esc_html_e( 'Named sub-areas of this venue (e.g. "Grand Hall", "Studio 2"). Events can be assigned to a specific room.', 'wp-dansal' ); ?></p>
					<div id="wpd-rooms" data-post-id="<?php echo esc_attr( $post->ID ); ?>"></div>
					<p>
						<input type="text" id="wpd-room-new-name" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. Grand Hall', 'wp-dansal' ); ?>" />
						<button type="button" class="button" id="wpd-room-add"><?php esc_html_e( 'Add room', 'wp-dansal' ); ?></button>
					</p>
				</details>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Rooms live server-side (dansal is the source of truth) but we snapshot
	 * the list into post meta on every pull-sync so the event picker can
	 * render without a round-trip. Callers that need a fresh list (metabox,
	 * "location changed" AJAX in the event editor) should fetch it here.
	 *
	 * @return array List of {id, name} rooms; empty on error.
	 */
	public function fetch_rooms_for_post( $post_id ) {
		$dansal_id = (int) get_post_meta( $post_id, self::META_DANSAL_ID, true );
		if ( ! $dansal_id ) {
			return array();
		}
		$rooms = $this->api->get_public( "/api/v1/locations/{$dansal_id}/rooms" );
		if ( is_wp_error( $rooms ) || ! is_array( $rooms ) ) {
			// Fall back to the cached list from the last successful sync so
			// the picker keeps working while dansal is briefly unreachable.
			$cached = get_post_meta( $post_id, '_wpd_rooms_cache', true );
			return is_array( $cached ) ? $cached : array();
		}
		$out = array();
		foreach ( $rooms as $room ) {
			if ( is_array( $room ) && isset( $room['id'], $room['name'] ) ) {
				$out[] = array(
					'id'   => (int) $room['id'],
					'name' => (string) $room['name'],
				);
			}
		}
		update_post_meta( $post_id, '_wpd_rooms_cache', $out );
		return $out;
	}

	public function ajax_list_rooms() {
		check_ajax_referer( 'wpd_rooms' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'wp-dansal' ) ), 403 );
		}
		$post_id = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0;
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid location.', 'wp-dansal' ) ) );
		}
		wp_send_json_success( array( 'rooms' => $this->fetch_rooms_for_post( $post_id ) ) );
	}

	public function ajax_add_room() {
		check_ajax_referer( 'wpd_rooms' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'wp-dansal' ) ), 403 );
		}
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$name    = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) || '' === trim( $name ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid input.', 'wp-dansal' ) ) );
		}
		$dansal_id = (int) get_post_meta( $post_id, self::META_DANSAL_ID, true );
		if ( ! $dansal_id ) {
			wp_send_json_error( array( 'message' => __( 'Location is not synced yet — save it first.', 'wp-dansal' ) ) );
		}
		$result = $this->api->post( "/api/v1/locations/{$dansal_id}/rooms", array( 'name' => $name ) );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( array( 'rooms' => $this->fetch_rooms_for_post( $post_id ) ) );
	}

	public function ajax_delete_room() {
		check_ajax_referer( 'wpd_rooms' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'wp-dansal' ) ), 403 );
		}
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$room_id = isset( $_POST['room_id'] ) ? absint( $_POST['room_id'] ) : 0;
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) || ! $room_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid input.', 'wp-dansal' ) ) );
		}
		$dansal_id = (int) get_post_meta( $post_id, self::META_DANSAL_ID, true );
		if ( ! $dansal_id ) {
			wp_send_json_error( array( 'message' => __( 'Location is not synced yet.', 'wp-dansal' ) ) );
		}
		$result = $this->api->delete( "/api/v1/locations/{$dansal_id}/rooms/{$room_id}" );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( array( 'rooms' => $this->fetch_rooms_for_post( $post_id ) ) );
	}

	public function enqueue_admin_assets( $hook ) {
		$screen = get_current_screen();
		if ( ! $screen || self::POST_TYPE !== $screen->post_type || ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}
		wp_enqueue_style( 'wpd-admin', WPD_PLUGIN_URL . 'assets/css/admin.css', array(), wpd_asset_ver( 'assets/css/admin.css' ) );
		wp_enqueue_style( 'wpd-leaflet', WPD_PLUGIN_URL . 'assets/vendor/leaflet/leaflet.css', array(), '1.9.4' );
		wp_enqueue_script( 'wpd-leaflet', WPD_PLUGIN_URL . 'assets/vendor/leaflet/leaflet.js', array(), '1.9.4', true );
		wp_enqueue_script( 'wpd-admin-location', WPD_PLUGIN_URL . 'assets/js/admin-location.js', array( 'jquery', 'wpd-leaflet' ), wpd_asset_ver( 'assets/js/admin-location.js' ), true );
		wp_localize_script(
            'wpd-admin-location',
            'wpdLocation',
            array(
				'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
				'nonceSearch'      => wp_create_nonce( 'wpd_nominatim_search' ),
				'nonceDuplicate'   => wp_create_nonce( 'wpd_check_location_duplicate' ),
				'nonceRooms'       => wp_create_nonce( 'wpd_rooms' ),
				// Reuses the frontend map's own tile config (wpd_tile_url_template
				// et al filters) so an admin pointing tiles at a self-hosted proxy
				// doesn't have to configure it twice.
				'tiles'            => wpd_plugin()->frontend->tile_config(),
				'i18n'             => array(
					'noResults'      => __( 'No matches found.', 'wp-dansal' ),
					'checking'       => __( 'Checking dansal for existing locations…', 'wp-dansal' ),
					'possibleDup'    => __( 'Possible existing location(s) in dansal:', 'wp-dansal' ),
					'useExisting'    => __( 'Use this existing location', 'wp-dansal' ),
					'createNew'      => __( 'Create new location anyway', 'wp-dansal' ),
					/* translators: %d is replaced client-side (JS .replace('%d', id)) with the dansal location ID. */
					'willAssign'     => __( 'On save, your organization will be assigned to dansal location #%d instead of creating a new one.', 'wp-dansal' ),
					'noRooms'        => __( 'No rooms yet.', 'wp-dansal' ),
					'removeRoom'     => __( 'Remove', 'wp-dansal' ),
					'confirmRemove'  => __( 'Remove this room? Any event using it will lose its room assignment.', 'wp-dansal' ),
					'roomsError'     => __( 'Failed to update rooms.', 'wp-dansal' ),
					'reverseNeedsCoords' => __( 'Enter a latitude and longitude first.', 'wp-dansal' ),
					'reversing'          => __( 'Reverse geocoding…', 'wp-dansal' ),
				),
            )
        );
	}

	/**
	 * AJAX: given osm_id/osm_type/lat/lng picked from Nominatim, ask dansal
	 * whether a matching location already exists (exact OSM match, then
	 * proximity).
	 */
	public function ajax_check_duplicate() {
		check_ajax_referer( 'wpd_check_location_duplicate' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'wp-dansal' ) ), 403 );
		}

		$osm_id   = isset( $_GET['osm_id'] ) ? absint( $_GET['osm_id'] ) : 0;
		$osm_type = isset( $_GET['osm_type'] ) ? sanitize_key( $_GET['osm_type'] ) : '';
		$lat      = isset( $_GET['lat'] ) ? (float) $_GET['lat'] : null;
		$lng      = isset( $_GET['lng'] ) ? (float) $_GET['lng'] : null;

		$matches = array();

		if ( $osm_id && $osm_type ) {
			$result = $this->api->get_public(
                '/api/v1/locations',
                array(
					'osm_id'   => $osm_id,
					'osm_type' => $osm_type,
                )
            );
			if ( ! is_wp_error( $result ) ) {
				$matches = $this->extract_locations( $result );
			}
		}

		if ( empty( $matches ) && null !== $lat && null !== $lng ) {
			$result = $this->api->get_public(
                '/api/v1/locations',
                array(
					'lat'    => $lat,
					'lng'    => $lng,
					'radius' => $this->settings->get_dedup_radius_km(),
                )
            );
			if ( ! is_wp_error( $result ) ) {
				$matches = $this->extract_locations( $result );
			}
		}

		wp_send_json_success( array( 'matches' => $matches ) );
	}

	private function extract_locations( $result ) {
		if ( isset( $result['locations'] ) && is_array( $result['locations'] ) ) {
			return $result['locations'];
		}
		// Some list endpoints return a bare JSON array (decoded as a
		// sequential PHP array, i.e. keys 0..n-1).
		if ( is_array( $result ) && ( empty( $result ) || array_keys( $result ) === range( 0, count( $result ) - 1 ) ) ) {
			return $result;
		}
		return array();
	}

	public function save( $post_id ) {
		$nonce = isset( $_POST['wpd_location_nonce'] )
			? sanitize_text_field( wp_unslash( $_POST['wpd_location_nonce'] ) )
			: '';
		if ( ! wp_verify_nonce( $nonce, 'wpd_location_save' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$fields = array(
			'_wpd_short_name'     => 'wpd_short_name',
			'_wpd_address'        => 'wpd_address',
			'_wpd_zipcode'        => 'wpd_zipcode',
			'_wpd_town'           => 'wpd_town',
			'_wpd_country_code'   => 'wpd_country_code',
			'_wpd_country'        => 'wpd_country',
			'_wpd_region'         => 'wpd_region',
			'_wpd_latitude'       => 'wpd_latitude',
			'_wpd_longitude'      => 'wpd_longitude',
			'_wpd_internetsite'   => 'wpd_internetsite',
			'_wpd_notes_md'       => 'wpd_notes_md',
			'_wpd_osm_id'         => 'wpd_osm_id',
			'_wpd_osm_type'       => 'wpd_osm_type',
		);
		foreach ( $fields as $meta_key => $post_key ) {
			$value = isset( $_POST[ $post_key ] ) ? wp_unslash( $_POST[ $post_key ] ) : '';
			update_post_meta( $post_id, $meta_key, sanitize_text_field( $value ) );
		}

		foreach ( array(
			'_wpd_parking'         => 'parking',
			'_wpd_floor_condition' => 'floor_condition',
		) as $meta_key => $vocab_key ) {
			$raw = isset( $_POST[ 'wpd_' . $vocab_key ] ) ? wp_unslash( $_POST[ 'wpd_' . $vocab_key ] ) : '';
			update_post_meta( $post_id, $meta_key, WPD_Vocab::sanitize( $vocab_key, $raw ) );
		}

		foreach ( array( 'wheelchair', 'bar', 'kitchen' ) as $attr ) {
			update_post_meta( $post_id, '_wpd_attr_' . $attr, ! empty( $_POST[ 'wpd_attr_' . $attr ] ) ? '1' : '' );
		}
		update_post_meta( $post_id, '_wpd_no_street_shoes', ! empty( $_POST['wpd_no_street_shoes'] ) ? '1' : '' );

		if ( ! $this->settings->is_configured() ) {
			return;
		}

		$use_existing_id = isset( $_POST['wpd_use_existing_dansal_id'] ) ? absint( $_POST['wpd_use_existing_dansal_id'] ) : 0;
		$this->sync_to_dansal( $post_id, $use_existing_id );
	}

	private function build_payload( $post_id, $title ) {
		$get = function ( $key ) use ( $post_id ) {
			return get_post_meta( $post_id, $key, true );
		};
		$lat = $get( '_wpd_latitude' );
		$lng = $get( '_wpd_longitude' );

		return array(
			'location'        => $title,
			'short_name'      => $get( '_wpd_short_name' ),
			'address'         => $get( '_wpd_address' ),
			'zipcode'         => $get( '_wpd_zipcode' ),
			'town'            => $get( '_wpd_town' ),
			'country'         => $get( '_wpd_country' ),
			'country_code'    => $get( '_wpd_country_code' ),
			'region'          => $get( '_wpd_region' ),
			'latitude'        => '' !== $lat ? (float) $lat : null,
			'longitude'       => '' !== $lng ? (float) $lng : null,
			'internetsite'    => $get( '_wpd_internetsite' ),
			'notes_md'        => $get( '_wpd_notes_md' ),
			'parking'         => $get( '_wpd_parking' ),
			'floor_condition' => $get( '_wpd_floor_condition' ),
			'no_street_shoes' => '1' === $get( '_wpd_no_street_shoes' ),
			'attributes'      => array(
				'wheelchair' => '1' === $get( '_wpd_attr_wheelchair' ),
				'bar'        => '1' === $get( '_wpd_attr_bar' ),
				'kitchen'    => '1' === $get( '_wpd_attr_kitchen' ),
			),
		);
	}

	private function sync_to_dansal( $post_id, $use_existing_id = 0 ) {
		$title     = get_the_title( $post_id );
		$dansal_id = (int) get_post_meta( $post_id, self::META_DANSAL_ID, true );
		$org_id    = $this->settings->get_org_id();
		$payload   = $this->build_payload( $post_id, $title );

		if ( ! $dansal_id && $use_existing_id ) {
			$assign = $this->api->post( "/api/v1/locations/{$use_existing_id}/assign-org", array( 'organization_id' => $org_id ) );
			if ( is_wp_error( $assign ) ) {
				/* translators: 1: dansal location ID, 2: underlying error message. */
				$this->store_notice( sprintf( __( 'Failed to assign your organization to existing dansal location #%1$d: %2$s', 'wp-dansal' ), $use_existing_id, $assign->get_error_message() ), 'error' );
				return;
			}
			$dansal_id = $use_existing_id;
			update_post_meta( $post_id, self::META_DANSAL_ID, $dansal_id );
		}

		if ( $dansal_id ) {
			$result = $this->api->patch( "/api/v1/locations/{$dansal_id}", $payload );
			if ( is_wp_error( $result ) ) {
				/* translators: 1: dansal location ID, 2: underlying error message. */
				$this->store_notice( sprintf( __( 'Failed to update dansal location #%1$d: %2$s', 'wp-dansal' ), $dansal_id, $result->get_error_message() ), 'error' );
			} else {
				// Marks this push as the most recent known sync point, so a
				// pull-sync (maybe_pull_sync()) right after doesn't consider
				// dansal "newer" than what we just wrote and pull it right
				// back on top of itself.
				update_post_meta( $post_id, self::META_LAST_SYNCED_AT, time() );
			}
			return;
		}

		$create_payload                     = $payload;
		$create_payload['organization_ids'] = array( $org_id );
		$osm_id                             = get_post_meta( $post_id, '_wpd_osm_id', true );
		if ( $osm_id ) {
			$create_payload['osm_id']   = (int) $osm_id;
			$create_payload['osm_type'] = get_post_meta( $post_id, '_wpd_osm_type', true );
		}

		$result = $this->api->post( '/api/v1/locations', $create_payload );
		if ( is_wp_error( $result ) ) {
			/* translators: %s: underlying error message. */
			$this->store_notice( sprintf( __( 'Failed to create dansal location: %s', 'wp-dansal' ), $result->get_error_message() ), 'error' );
			return;
		}

		// POST /api/v1/locations always responds with a JSON array of
		// {location, similar_locations} objects, even for a single-object
		// request body — see the handler's `json.NewEncoder(w).Encode(results)`
		// where results is []LocationCreateResponse.
		$new_id = isset( $result[0]['location']['id'] ) ? $result[0]['location']['id'] : 0;
		if ( $new_id ) {
			update_post_meta( $post_id, self::META_DANSAL_ID, $new_id );
			update_post_meta( $post_id, self::META_LAST_SYNCED_AT, time() );
			/* translators: %d: newly created dansal location ID. */
			$this->store_notice( sprintf( __( 'Created dansal location #%d.', 'wp-dansal' ), $new_id ), 'success' );
		}
	}

	/**
	 * Pull-sync: import/refresh the org's dansal locations into WordPress.
	 * Runs lazily whenever an admin opens the Dance Locations list screen
	 * (see load-edit.php hook), rather than on a schedule, so it never
	 * fights with an in-progress edit and needs no WP-Cron infrastructure.
	 */
	public function maybe_pull_sync() {
		global $typenow;
		if ( self::POST_TYPE !== $typenow || ! $this->settings->is_configured() ) {
			return;
		}
		// Short cooldown so repeatedly reloading the list screen doesn't
		// hammer the dansal API.
		if ( get_transient( 'wpd_location_pull_lock' ) ) {
			return;
		}
		set_transient( 'wpd_location_pull_lock', 1, 30 );

		$result = $this->api->get_all_pages( '/api/v1/locations', array( 'org_id' => $this->settings->get_org_id() ) );
		if ( is_wp_error( $result ) ) {
			return;
		}

		$created = 0;
		$updated = 0;
		foreach ( $this->extract_locations( $result ) as $loc ) {
			$status = $this->pull_one_location( $loc );
			if ( 'created' === $status ) {
				++$created;
			} elseif ( 'updated' === $status ) {
				++$updated;
			}
		}

		if ( $created || $updated ) {
			$this->store_notice(
				sprintf(
					/* translators: 1: number of newly imported locations, 2: number of refreshed locations. */
					__( 'Synced with dansal: %1$d new location(s) imported, %2$d refreshed.', 'wp-dansal' ),
					$created,
					$updated
				),
				'success'
			);
		}
	}

	/**
	 * Frontend visitors trigger a lightweight single-item refresh (one
	 * GET /api/v1/locations/{id}, not the whole org list) when viewing this
	 * location's page, rate-limited per post so repeated page views/bot
	 * traffic don't hammer dansal. This exists because maybe_pull_sync()
	 * only runs when someone opens the Dance Locations list in wp-admin —
	 * a public page would otherwise show stale data until that happens.
	 */
	public function maybe_refresh_single( $post_id ) {
		if ( ! $this->settings->is_configured() ) {
			return;
		}
		// Only refresh public posts; drafts/trash/private have no public page
		// to keep fresh and should never trigger backend calls from visitors.
		if ( 'publish' !== get_post_status( $post_id ) ) {
			return;
		}
		$dansal_id = (int) get_post_meta( $post_id, self::META_DANSAL_ID, true );
		if ( ! $dansal_id ) {
			return;
		}
		// Global short lock caps fan-out across all posts under traffic bursts
		// (bots crawling many distinct URLs at once); per-post lock throttles
		// repeated hits on the same URL.
		$global_lock_key = 'wpd_location_refresh_global';
		if ( get_transient( $global_lock_key ) ) {
			return;
		}
		$lock_key = 'wpd_location_refresh_' . $post_id;
		if ( get_transient( $lock_key ) ) {
			return;
		}
		set_transient( $global_lock_key, 1, 5 );
		set_transient( $lock_key, 1, 5 * MINUTE_IN_SECONDS );

		$loc = $this->api->get( "/api/v1/locations/{$dansal_id}" );
		if ( is_wp_error( $loc ) || ! is_array( $loc ) || empty( $loc['id'] ) ) {
			return;
		}

		$updated_at  = isset( $loc['updated_at'] ) ? (int) $loc['updated_at'] : 0;
		$last_synced = (int) get_post_meta( $post_id, self::META_LAST_SYNCED_AT, true );
		if ( $updated_at > 0 && $updated_at <= $last_synced ) {
			return; // Already current.
		}

		$this->write_location_post( $post_id, $loc );
	}

	/**
	 * @return int WordPress post ID linked to this dansal location, or 0.
	 */
	public static function find_post_id_by_dansal_id( $dansal_id ) {
		$posts = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'meta_key'       => self::META_DANSAL_ID,
				'meta_value'     => (int) $dansal_id,
				'fields'         => 'ids',
			)
		);
		return $posts ? (int) $posts[0] : 0;
	}

	/**
	 * @return string|null 'created', 'updated', or null if skipped/unchanged.
	 */
	private function pull_one_location( array $loc ) {
		if ( empty( $loc['id'] ) ) {
			return null;
		}
		$dansal_id  = (int) $loc['id'];
		$updated_at = isset( $loc['updated_at'] ) ? (int) $loc['updated_at'] : 0;

		$post_id = self::find_post_id_by_dansal_id( $dansal_id );

		if ( $post_id ) {
			$last_synced = (int) get_post_meta( $post_id, self::META_LAST_SYNCED_AT, true );
			if ( $updated_at > 0 && $updated_at <= $last_synced ) {
				return null; // Local copy is already current.
			}
			$this->write_location_post( $post_id, $loc );
			return 'updated';
		}

		remove_action( 'save_post_' . self::POST_TYPE, array( $this, 'save' ) );
		$post_id = wp_insert_post(
			array(
				'post_type'   => self::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => isset( $loc['location'] ) ? $loc['location'] : '',
			),
			true
		);
		add_action( 'save_post_' . self::POST_TYPE, array( $this, 'save' ) );

		if ( is_wp_error( $post_id ) || ! $post_id ) {
			return null;
		}

		$this->write_location_post( $post_id, $loc );
		return 'created';
	}

	/**
	 * Writes a dansal location object onto a (new or existing) local post.
	 * Only touches post_title via wp_update_post() if it actually changed,
	 * to avoid pointless revisions; save_post is unhooked around that call
	 * so the pull can't re-trigger a push right back to dansal.
	 */
	private function write_location_post( $post_id, array $loc ) {
		if ( isset( $loc['location'] ) && get_the_title( $post_id ) !== $loc['location'] ) {
			remove_action( 'save_post_' . self::POST_TYPE, array( $this, 'save' ) );
			wp_update_post(
				array(
					'ID'         => $post_id,
					'post_title' => $loc['location'],
				)
			);
			add_action( 'save_post_' . self::POST_TYPE, array( $this, 'save' ) );
		}

		$fields = array(
			'_wpd_short_name'      => 'short_name',
			'_wpd_address'         => 'address',
			'_wpd_zipcode'         => 'zipcode',
			'_wpd_town'            => 'town',
			'_wpd_country'         => 'country',
			'_wpd_country_code'    => 'country_code',
			'_wpd_region'          => 'region',
			'_wpd_internetsite'    => 'internetsite',
			'_wpd_notes_md'        => 'notes_md',
			'_wpd_parking'         => 'parking',
			'_wpd_floor_condition' => 'floor_condition',
			'_wpd_osm_type'        => 'osm_type',
		);
		foreach ( $fields as $meta_key => $field ) {
			update_post_meta( $post_id, $meta_key, isset( $loc[ $field ] ) ? $loc[ $field ] : '' );
		}
		update_post_meta( $post_id, '_wpd_latitude', isset( $loc['latitude'] ) ? $loc['latitude'] : '' );
		update_post_meta( $post_id, '_wpd_longitude', isset( $loc['longitude'] ) ? $loc['longitude'] : '' );
		update_post_meta( $post_id, '_wpd_osm_id', isset( $loc['osm_id'] ) ? $loc['osm_id'] : '' );
		update_post_meta( $post_id, '_wpd_no_street_shoes', ! empty( $loc['no_street_shoes'] ) ? '1' : '' );

		$attrs = isset( $loc['attributes'] ) && is_array( $loc['attributes'] ) ? $loc['attributes'] : array();
		foreach ( array( 'wheelchair', 'bar', 'kitchen' ) as $attr ) {
			update_post_meta( $post_id, '_wpd_attr_' . $attr, ! empty( $attrs[ $attr ] ) ? '1' : '' );
		}

		// dansal now embeds a location's rooms (API.md → Locations → Rooms).
		// Snapshot them so the event picker can render without a round-trip;
		// authoritative list stays server-side.
		$rooms_cache = array();
		if ( isset( $loc['rooms'] ) && is_array( $loc['rooms'] ) ) {
			foreach ( $loc['rooms'] as $room ) {
				if ( is_array( $room ) && isset( $room['id'], $room['name'] ) ) {
					$rooms_cache[] = array(
						'id'   => (int) $room['id'],
						'name' => (string) $room['name'],
					);
				}
			}
		}
		update_post_meta( $post_id, '_wpd_rooms_cache', $rooms_cache );

		update_post_meta( $post_id, self::META_DANSAL_ID, (int) $loc['id'] );
		update_post_meta( $post_id, self::META_LAST_SYNCED_AT, time() );
	}

	private function store_notice( $message, $type ) {
		$message   = wp_kses( (string) $message, array() );
		$notices   = get_transient( 'wpd_admin_notices_' . get_current_user_id() );
		$notices   = is_array( $notices ) ? $notices : array();
		$notices[] = array(
			'message' => $message,
			'type' => $type,
		);
		set_transient( 'wpd_admin_notices_' . get_current_user_id(), $notices, MINUTE_IN_SECONDS * 5 );
	}

	public function show_sync_notices() {
		$key     = 'wpd_admin_notices_' . get_current_user_id();
		$notices = get_transient( $key );
		if ( empty( $notices ) ) {
			return;
		}
		delete_transient( $key );
		foreach ( $notices as $notice ) {
			printf(
				'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
				'error' === $notice['type'] ? 'error' : 'success',
				esc_html( $notice['message'] )
			);
		}
	}
}
