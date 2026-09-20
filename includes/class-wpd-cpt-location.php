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
	/**
	 * Dansal id of a room's building. Present only on rooms — dansal models a
	 * room as an ordinary location with `parent_id` set (API.md → Locations →
	 * "Rooms are child locations", #121). Stored as the dansal id, not a WP
	 * post id, so it doesn't depend on which of the two posts was imported
	 * first.
	 */
	const META_PARENT_DANSAL_ID = '_wpd_parent_dansal_id';
	const POST_TYPE             = 'dansal_location';

	/** @var WPD_Api_Client */
	private $api;
	/** @var WPD_Nominatim */
	private $nominatim;
	/** @var WPD_Settings */
	private $settings;
	/**
	 * Dansal ids ensure_local_post() already failed to fetch during this
	 * request, so one unreachable/deleted location doesn't cost a request per
	 * event that references it.
	 *
	 * @var array<int,true>
	 */
	private $unresolvable = array();

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
		$columns['wpd_building']  = __( 'Room of', 'wp-dansal' );
		return $columns;
	}

	public function render_column( $column, $post_id ) {
		if ( 'wpd_dansal_id' === $column ) {
			$id = get_post_meta( $post_id, self::META_DANSAL_ID, true );
			echo $id ? esc_html( $id ) : esc_html__( 'not synced', 'wp-dansal' );
		} elseif ( 'wpd_town' === $column ) {
			echo esc_html( get_post_meta( $post_id, '_wpd_town', true ) );
		} elseif ( 'wpd_building' === $column ) {
			$parent = self::parent_post_id( $post_id );
			echo $parent ? esc_html( get_the_title( $parent ) ) : '';
		}
	}

	public function add_meta_boxes() {
		add_meta_box( 'wpd_location_details', __( 'Dansal Location Details', 'wp-dansal' ), array( $this, 'render_meta_box' ), self::POST_TYPE, 'normal', 'high' );
	}

	private function field( $post_id, $key, $default_value = '' ) {
		$v = get_post_meta( $post_id, $key, true );
		return '' === $v ? $default_value : $v;
	}

	/**
	 * Meta box for a room (a child location, #121): the fields dansal lets a
	 * room own, with the building's address shown read-only since it is
	 * inherited, not stored on the room.
	 */
	private function render_room_meta_box( $post ) {
		$dansal_id   = get_post_meta( $post->ID, self::META_DANSAL_ID, true );
		$parent_post = self::parent_post_id( $post->ID );
		if ( $dansal_id ) {
			printf( '<p><strong>%s%s</strong></p>', esc_html__( 'Synced with dansal location #', 'wp-dansal' ), esc_html( $dansal_id ) );
		}
		?>
		<div id="wpd-location-editor" data-post-id="<?php echo esc_attr( $post->ID ); ?>" data-room="1">
			<p>
				<?php esc_html_e( 'This is a room. Its address and coordinates are inherited from its building and can only be changed there.', 'wp-dansal' ); ?>
				<?php if ( $parent_post ) : ?>
					<br />
					<strong><?php esc_html_e( 'Building:', 'wp-dansal' ); ?></strong>
					<a href="<?php echo esc_url( (string) get_edit_post_link( $parent_post, 'raw' ) ); ?>"><?php echo esc_html( get_the_title( $parent_post ) ); ?></a>
					<?php
					$address = trim( implode( ', ', array_filter( array( $this->field( $post->ID, '_wpd_address' ), trim( $this->field( $post->ID, '_wpd_zipcode' ) . ' ' . $this->field( $post->ID, '_wpd_town' ) ) ) ) ) );
					if ( '' !== $address ) {
						echo ' — ' . esc_html( $address );
					}
					?>
				<?php endif; ?>
			</p>
			<table class="form-table">
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
							'bar'        => __( 'Bar', 'wp-dansal' ),
							'kitchen'    => __( 'Kitchen', 'wp-dansal' ),
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
					<th><label for="wpd_capacity"><?php esc_html_e( 'Capacity', 'wp-dansal' ); ?></label></th>
					<td>
						<input type="number" min="0" id="wpd_capacity" name="wpd_capacity" class="small-text" value="<?php echo esc_attr( $this->field( $post->ID, '_wpd_capacity' ) ); ?>" />
						<span class="description"><?php esc_html_e( 'people (informational)', 'wp-dansal' ); ?></span>
					</td>
				</tr>
				<tr>
					<th><label for="wpd_size_sqm"><?php esc_html_e( 'Floor area', 'wp-dansal' ); ?></label></th>
					<td>
						<input type="number" min="0" id="wpd_size_sqm" name="wpd_size_sqm" class="small-text" value="<?php echo esc_attr( $this->field( $post->ID, '_wpd_size_sqm' ) ); ?>" />
						<span class="description"><?php esc_html_e( 'm² (informational)', 'wp-dansal' ); ?></span>
					</td>
				</tr>
				<tr>
					<th><label for="wpd_notes_md"><?php esc_html_e( 'Notes (Markdown)', 'wp-dansal' ); ?></label></th>
					<td><textarea id="wpd_notes_md" name="wpd_notes_md" rows="4" class="large-text"><?php echo esc_textarea( $this->field( $post->ID, '_wpd_notes_md' ) ); ?></textarea></td>
				</tr>
			</table>
		</div>
		<?php
	}

	public function render_meta_box( $post ) {
		wp_nonce_field( 'wpd_location_save', 'wpd_location_nonce' );
		if ( self::is_room( $post->ID ) ) {
			$this->render_room_meta_box( $post );
			return;
		}
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
					<p class="description"><?php esc_html_e( 'Named sub-areas of this venue (e.g. "Grand Hall", "Studio 2"). A room is a location of its own (with its own page); it uses this venue\'s address and coordinates. Events can be assigned to a specific room.', 'wp-dansal' ); ?></p>
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
	 * The locally imported rooms of a building, shaped for the admin JS.
	 *
	 * @param int $building_post_id WP post ID of the building.
	 * @return array[] List of {id (dansal id), post_id, name, edit_url}.
	 */
	private function local_rooms( $building_post_id ) {
		$out = array();
		foreach ( self::room_posts( $building_post_id ) as $room ) {
			$out[] = array(
				'id'       => (int) get_post_meta( $room->ID, self::META_DANSAL_ID, true ),
				'post_id'  => (int) $room->ID,
				'name'     => (string) $room->post_title,
				'edit_url' => (string) get_edit_post_link( $room->ID, 'raw' ),
			);
		}
		return $out;
	}

	/**
	 * A building's rooms. Dansal is the source of truth (`GET /locations/{id}/children`,
	 * API.md → Locations); every room it returns is imported as a local
	 * dansal_location post so the event form's room picker — and events
	 * themselves — can point at it (#121). When dansal can't be reached, falls
	 * back to whatever rooms are already imported locally.
	 *
	 * @param int $post_id WP post ID of the building.
	 * @return array[] List of {id (dansal id), post_id, name, edit_url}; empty for an unsynced location.
	 */
	public function fetch_rooms_for_post( $post_id ) {
		$dansal_id = (int) get_post_meta( $post_id, self::META_DANSAL_ID, true );
		if ( ! $dansal_id ) {
			return array();
		}

		$children = $this->api->get_public( "/api/v1/locations/{$dansal_id}/children" );
		if ( ! is_wp_error( $children ) && is_array( $children ) ) {
			$seen = array();
			foreach ( $children as $child ) {
				if ( is_array( $child ) && ! empty( $child['id'] ) ) {
					$this->pull_one_location( $child );
					$seen[] = (int) $child['id'];
				}
			}
			$this->prune_rooms( $dansal_id, $seen );
		}

		return $this->local_rooms( $post_id );
	}

	/**
	 * Drop local room posts whose room no longer exists on dansal. Only called
	 * with an authoritative `/children` answer. A room that events still point
	 * at is kept: silently unlinking an event from its venue would be worse
	 * than showing a stale room until the event itself is re-pulled.
	 */
	private function prune_rooms( $parent_dansal_id, array $seen_dansal_ids ) {
		$rooms = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'meta_key'       => self::META_PARENT_DANSAL_ID,
				'meta_value'     => (int) $parent_dansal_id,
			)
		);
		foreach ( $rooms as $room ) {
			$room_dansal_id = (int) get_post_meta( $room->ID, self::META_DANSAL_ID, true );
			if ( ! $room_dansal_id || in_array( $room_dansal_id, $seen_dansal_ids, true ) ) {
				continue;
			}
			if ( $this->events_at_location( $room->ID ) ) {
				continue;
			}
			wp_delete_post( $room->ID, true );
		}
	}

	/**
	 * @return int[] IDs of local events whose venue is this location post.
	 */
	private function events_at_location( $location_post_id ) {
		return get_posts(
			array(
				'post_type'      => WPD_CPT_Event::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_key'       => '_wpd_location_post_id',
				'meta_value'     => (int) $location_post_id,
			)
		);
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
		if ( self::is_room( $post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'A room cannot have rooms of its own.', 'wp-dansal' ) ) );
		}
		// A room is a child location (API.md → Locations); address and
		// coordinates are inherited from the building server-side, so only the
		// name is sent.
		$result = $this->api->post( "/api/v1/locations/{$dansal_id}/children", array( 'name' => $name ) );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		// Re-reading the list (rather than importing the POST response) picks
		// the new room up with its inherited address/coordinates filled in.
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
		// Only ever delete a room of *this* building — room_id comes from the
		// browser, and a room is just a location, so the API itself would
		// happily delete any location id it is given.
		$room_post_id = self::find_post_id_by_dansal_id( $room_id );
		if ( ! $room_post_id || self::parent_dansal_id( $room_post_id ) !== $dansal_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid input.', 'wp-dansal' ) ) );
		}
		// A room is deleted through its own location id; events still using it
		// are moved to the building (`reassign_to`, honored for admin keys —
		// otherwise dansal answers 409 and we show its message).
		$result = $this->api->delete( "/api/v1/locations/{$room_id}?reassign_to={$dansal_id}" );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		foreach ( $this->events_at_location( $room_post_id ) as $event_id ) {
			update_post_meta( $event_id, '_wpd_location_post_id', $post_id );
		}
		wp_delete_post( $room_post_id, true );
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

		// Rooms inherit their building's coordinates, so a proximity lookup
		// returns them alongside the building — they are never a duplicate of a
		// *new* building (#121).
		$matches = array_values(
			array_filter(
				$matches,
				static function ( $m ) {
					return is_array( $m ) && empty( $m['parent_id'] );
				}
			)
		);

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

		// A room only edits what dansal lets a room own — its address and
		// coordinates are inherited from the building (API.md → Locations), so
		// the address inputs aren't even rendered and must not be blanked here.
		if ( self::is_room( $post_id ) ) {
			$this->save_room_fields( $post_id );
			if ( $this->settings->is_configured() ) {
				$this->sync_to_dansal( $post_id );
			}
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

	/**
	 * Saves the form fields a room has (see render_room_meta_box()).
	 *
	 * Only called from save(), after its nonce + capability checks.
	 */
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in save() before this is reached.
	private function save_room_fields( $post_id ) {
		$notes = isset( $_POST['wpd_notes_md'] ) ? sanitize_textarea_field( wp_unslash( $_POST['wpd_notes_md'] ) ) : '';
		update_post_meta( $post_id, '_wpd_notes_md', $notes );

		$floor = isset( $_POST['wpd_floor_condition'] ) ? wp_unslash( $_POST['wpd_floor_condition'] ) : '';
		update_post_meta( $post_id, '_wpd_floor_condition', WPD_Vocab::sanitize( 'floor_condition', $floor ) );
		update_post_meta( $post_id, '_wpd_no_street_shoes', ! empty( $_POST['wpd_no_street_shoes'] ) ? '1' : '' );
		foreach ( array( 'wheelchair', 'bar', 'kitchen' ) as $attr ) {
			update_post_meta( $post_id, '_wpd_attr_' . $attr, ! empty( $_POST[ 'wpd_attr_' . $attr ] ) ? '1' : '' );
		}
		foreach ( array(
			'_wpd_capacity' => 'wpd_capacity',
			'_wpd_size_sqm' => 'wpd_size_sqm',
		) as $meta_key => $post_key ) {
			$raw = isset( $_POST[ $post_key ] ) ? absint( wp_unslash( $_POST[ $post_key ] ) ) : 0;
			update_post_meta( $post_id, $meta_key, $raw ? $raw : '' );
		}
	}
	// phpcs:enable WordPress.Security.NonceVerification.Missing

	/**
	 * PATCH body for a room. Deliberately has no address/zipcode/town/country/
	 * coordinates/OSM fields: dansal treats those as inherited from the
	 * building and read-only on a child, and the copies stored on the local
	 * room post are just that inheritance (#121) — pushing them back would
	 * freeze a stale copy of the building's address onto the room.
	 */
	private function build_room_payload( $post_id, $title ) {
		$get      = function ( $key ) use ( $post_id ) {
			return get_post_meta( $post_id, $key, true );
		};
		$capacity = $get( '_wpd_capacity' );
		$size     = $get( '_wpd_size_sqm' );

		return array(
			'location'        => $title,
			'notes_md'        => $get( '_wpd_notes_md' ),
			'floor_condition' => $get( '_wpd_floor_condition' ),
			'no_street_shoes' => '1' === $get( '_wpd_no_street_shoes' ),
			'attributes'      => array(
				'wheelchair' => '1' === $get( '_wpd_attr_wheelchair' ),
				'bar'        => '1' === $get( '_wpd_attr_bar' ),
				'kitchen'    => '1' === $get( '_wpd_attr_kitchen' ),
			),
			'capacity'        => '' !== $capacity ? (int) $capacity : null,
			'size_sqm'        => '' !== $size ? (int) $size : null,
		);
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
		$is_room   = self::is_room( $post_id );
		$payload   = $is_room ? $this->build_room_payload( $post_id, $title ) : $this->build_payload( $post_id, $title );

		// Rooms only ever come from dansal (created via POST .../children), so
		// there is nothing to create or assign for one that has no dansal id.
		if ( $is_room && ! $dansal_id ) {
			return;
		}

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
		$this->pull_related_locations( $loc );
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
	 * Dansal id of the building this room belongs to, or 0 for a building.
	 */
	public static function parent_dansal_id( $post_id ) {
		return (int) get_post_meta( $post_id, self::META_PARENT_DANSAL_ID, true );
	}

	public static function is_room( $post_id ) {
		return self::parent_dansal_id( $post_id ) > 0;
	}

	/**
	 * @return int WP post ID of a room's building, or 0 if $post_id isn't a
	 *             room or its building hasn't been imported yet.
	 */
	public static function parent_post_id( $post_id ) {
		$parent = self::parent_dansal_id( $post_id );
		return $parent ? self::find_post_id_by_dansal_id( $parent ) : 0;
	}

	/**
	 * Locally imported rooms of a building.
	 *
	 * @param int $building_post_id WP post ID of the building.
	 * @return WP_Post[]
	 */
	public static function room_posts( $building_post_id ) {
		$dansal_id = (int) get_post_meta( $building_post_id, self::META_DANSAL_ID, true );
		if ( ! $dansal_id ) {
			return array();
		}
		return get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'meta_key'       => self::META_PARENT_DANSAL_ID,
				'meta_value'     => $dansal_id,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
	}

	/**
	 * Display name of a location: a room reads "Building — Room", a building
	 * is just its title. Events point at whichever level was chosen (#121), so
	 * anything that prints an event's venue should go through this.
	 */
	public static function label( $post_id ) {
		$title  = get_the_title( $post_id );
		$parent = self::parent_post_id( $post_id );
		return $parent ? sprintf( '%1$s — %2$s', get_the_title( $parent ), $title ) : $title;
	}

	/**
	 * Local post for a dansal location, fetching and importing it on demand
	 * when it isn't there yet. Needed because an event's location can be a
	 * room (or any location outside this org's list) that the org-wide pull
	 * never imported — see write_event_post() (#121).
	 *
	 * @param int $dansal_id Dansal location id (building or room).
	 * @return int WP post ID, or 0 when dansal couldn't be reached / has no such location.
	 */
	public function ensure_local_post( $dansal_id ) {
		$dansal_id = (int) $dansal_id;
		if ( $dansal_id <= 0 ) {
			return 0;
		}
		$post_id = self::find_post_id_by_dansal_id( $dansal_id );
		if ( $post_id ) {
			return $post_id;
		}
		if ( isset( $this->unresolvable[ $dansal_id ] ) ) {
			return 0;
		}

		$loc = $this->api->get_public( "/api/v1/locations/{$dansal_id}" );
		if ( is_wp_error( $loc ) || ! is_array( $loc ) || empty( $loc['id'] ) ) {
			$this->unresolvable[ $dansal_id ] = true;
			return 0;
		}

		$this->pull_one_location( $loc );
		return self::find_post_id_by_dansal_id( $dansal_id );
	}

	/**
	 * @return string|null 'created', 'updated', or null if skipped/unchanged.
	 */
	private function pull_one_location( array $loc ) {
		if ( empty( $loc['id'] ) ) {
			return null;
		}
		$status = $this->pull_location_row( $loc );
		$this->pull_related_locations( $loc );
		return $status;
	}

	/**
	 * A room's building, and a building's rooms, are locations too (#121):
	 * pull whichever side of the relationship this payload names so the
	 * building → room picker has something to offer and an event that points
	 * at a room can always resolve its venue.
	 */
	private function pull_related_locations( array $loc ) {
		if ( ! empty( $loc['parent_id'] ) ) {
			$this->ensure_local_post( (int) $loc['parent_id'] );
		}
		if ( ! empty( $loc['children'] ) && is_array( $loc['children'] ) ) {
			foreach ( $loc['children'] as $child ) {
				if ( is_array( $child ) ) {
					$this->pull_one_location( $child );
				}
			}
		}
	}

	/**
	 * @return string|null 'created', 'updated', or null if skipped/unchanged.
	 */
	private function pull_location_row( array $loc ) {
		$dansal_id  = (int) $loc['id'];
		$updated_at = isset( $loc['updated_at'] ) ? (int) $loc['updated_at'] : 0;

		$post_id = self::find_post_id_by_dansal_id( $dansal_id );

		if ( $post_id ) {
			// Backfill runs even when the location payload itself is current,
			// so upgrading to a version that added the link fixes existing
			// installs on the next Dance → Locations visit without needing
			// a separate migration step.
			$this->backfill_events_for_location( $dansal_id, $post_id );
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
		$this->backfill_events_for_location( $dansal_id, $post_id );
		return 'created';
	}

	/**
	 * Backfill events that carry this location's dansal id in
	 * _wpd_last_synced_location_dansal_id but have an empty
	 * _wpd_location_post_id. Two triggering cases:
	 *   1. Fresh install: events were pulled before their location existed,
	 *      so find_post_id_by_dansal_id() returned 0 → empty meta.
	 *   2. Upgrade: an older plugin version failed to set the link at pull
	 *      time; running this on every location pull heals those installs
	 *      the next time Dance → Locations is visited.
	 * Bounded by wpd_full_sync_cap so a runaway org can't stall the tick.
	 */
	private function backfill_events_for_location( $dansal_id, $post_id ) {
		if ( $dansal_id <= 0 || $post_id <= 0 ) {
			return;
		}
		$cap = (int) apply_filters( 'wpd_full_sync_cap', 5000, '/api/v1/events' );
		$q   = new WP_Query(
			array(
				'post_type'      => WPD_CPT_Event::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => $cap,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array(
					'relation' => 'AND',
					array(
						'key'   => WPD_CPT_Event::META_LAST_SYNCED_LOCATION,
						'value' => (int) $dansal_id,
					),
					array(
						'relation' => 'OR',
						array(
							'key' => '_wpd_location_post_id',
							'compare' => 'NOT EXISTS',
						),
						array(
							'key' => '_wpd_location_post_id',
							'value' => '',
							'compare' => '=',
						),
					),
				),
			)
		);
		foreach ( $q->posts as $eid ) {
			update_post_meta( (int) $eid, '_wpd_location_post_id', (int) $post_id );
		}
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

		// Rooms are child locations (#121): only a room carries parent_id. A
		// building keeps no marker at all, so "is this a room?" stays a plain
		// meta-exists check and queries can use NOT EXISTS for buildings.
		if ( ! empty( $loc['parent_id'] ) ) {
			update_post_meta( $post_id, self::META_PARENT_DANSAL_ID, (int) $loc['parent_id'] );
		} else {
			delete_post_meta( $post_id, self::META_PARENT_DANSAL_ID );
		}
		update_post_meta( $post_id, '_wpd_capacity', isset( $loc['capacity'] ) && null !== $loc['capacity'] ? (int) $loc['capacity'] : '' );
		update_post_meta( $post_id, '_wpd_size_sqm', isset( $loc['size_sqm'] ) && null !== $loc['size_sqm'] ? (int) $loc['size_sqm'] : '' );
		// Legacy snapshot of the pre-#121 /rooms list; nothing reads it anymore.
		delete_post_meta( $post_id, '_wpd_rooms_cache' );

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
