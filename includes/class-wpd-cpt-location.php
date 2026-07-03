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

	const META_DANSAL_ID = '_wpd_dansal_id';
	const POST_TYPE      = 'dansal_location';

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
		add_action( 'admin_notices', array( $this, 'show_sync_notices' ) );
		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( $this, 'columns' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( $this, 'render_column' ), 10, 2 );
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
				'show_in_menu' => true,
				'menu_icon'    => 'dashicons-location-alt',
				'supports'     => array( 'title', 'editor' ),
				'rewrite'      => array( 'slug' => 'dance-locations' ),
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
		?>
		<div id="wpd-location-editor" data-post-id="<?php echo esc_attr( $post->ID ); ?>">
			<?php if ( $dansal_id ) : ?>
				<p><strong><?php esc_html_e( 'Synced with dansal location #', 'wp-dansal' ); ?><?php echo esc_html( $dansal_id ); ?></strong></p>
			<?php else : ?>
				<div class="wpd-field-row">
					<label for="wpd-nominatim-q"><?php esc_html_e( 'Find address (OpenStreetMap / Nominatim)', 'wp-dansal' ); ?></label><br />
					<input type="text" id="wpd-nominatim-q" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. Bürgerhaus Stollwerck, Köln', 'wp-dansal' ); ?>" />
					<button type="button" class="button" id="wpd-nominatim-search"><?php esc_html_e( 'Search', 'wp-dansal' ); ?></button>
				</div>
				<div id="wpd-nominatim-results"></div>
				<div id="wpd-duplicate-results"></div>
				<input type="hidden" id="wpd_use_existing_dansal_id" name="wpd_use_existing_dansal_id" value="" />
				<hr />
			<?php endif; ?>

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
				<tr>
					<th><label for="wpd_parking"><?php esc_html_e( 'Parking', 'wp-dansal' ); ?></label></th>
					<td><input type="text" id="wpd_parking" name="wpd_parking" class="regular-text" value="<?php echo esc_attr( $this->field( $post->ID, '_wpd_parking' ) ); ?>" /></td>
				</tr>
				<tr>
					<th><label for="wpd_floor_condition"><?php esc_html_e( 'Floor condition', 'wp-dansal' ); ?></label></th>
					<td>
						<input type="text" id="wpd_floor_condition" name="wpd_floor_condition" list="wpd-floor-conditions" class="regular-text" value="<?php echo esc_attr( $this->field( $post->ID, '_wpd_floor_condition' ) ); ?>" />
						<datalist id="wpd-floor-conditions">
							<option value="wooden parquet"></option>
							<option value="stone floor"></option>
							<option value="grass"></option>
							<option value="tiles"></option>
							<option value="sand / gravel"></option>
							<option value="pavement"></option>
						</datalist>
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
						<br />
						<label>
							<input type="checkbox" name="wpd_no_street_shoes" value="1" <?php checked( $this->field( $post->ID, '_wpd_no_street_shoes' ), '1' ); ?> />
							<?php esc_html_e( 'No street shoes', 'wp-dansal' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th><label for="wpd_notes_md"><?php esc_html_e( 'Notes (Markdown)', 'wp-dansal' ); ?></label></th>
					<td><textarea id="wpd_notes_md" name="wpd_notes_md" rows="4" class="large-text"><?php echo esc_textarea( $this->field( $post->ID, '_wpd_notes_md' ) ); ?></textarea></td>
				</tr>
			</table>
			<input type="hidden" id="wpd_osm_id" name="wpd_osm_id" value="<?php echo esc_attr( $this->field( $post->ID, '_wpd_osm_id' ) ); ?>" />
			<input type="hidden" id="wpd_osm_type" name="wpd_osm_type" value="<?php echo esc_attr( $this->field( $post->ID, '_wpd_osm_type' ) ); ?>" />
		</div>
		<?php
	}

	public function enqueue_admin_assets( $hook ) {
		$screen = get_current_screen();
		if ( ! $screen || self::POST_TYPE !== $screen->post_type || ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}
		wp_enqueue_style( 'wpd-admin', WPD_PLUGIN_URL . 'assets/css/admin.css', array(), WPD_VERSION );
		wp_enqueue_script( 'wpd-admin-location', WPD_PLUGIN_URL . 'assets/js/admin-location.js', array( 'jquery' ), WPD_VERSION, true );
		wp_localize_script(
            'wpd-admin-location',
            'wpdLocation',
            array(
				'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
				'nonceSearch'      => wp_create_nonce( 'wpd_nominatim_search' ),
				'nonceDuplicate'   => wp_create_nonce( 'wpd_check_location_duplicate' ),
				'i18n'             => array(
					'noResults'      => __( 'No matches found.', 'wp-dansal' ),
					'checking'       => __( 'Checking dansal for existing locations…', 'wp-dansal' ),
					'possibleDup'    => __( 'Possible existing location(s) in dansal:', 'wp-dansal' ),
					'useExisting'    => __( 'Use this existing location', 'wp-dansal' ),
					'createNew'      => __( 'Create new location anyway', 'wp-dansal' ),
					/* translators: %d is replaced client-side (JS .replace('%d', id)) with the dansal location ID. */
					'willAssign'     => __( 'On save, your organization will be assigned to dansal location #%d instead of creating a new one.', 'wp-dansal' ),
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
		if ( ! isset( $_POST['wpd_location_nonce'] ) || ! wp_verify_nonce( $_POST['wpd_location_nonce'], 'wpd_location_save' ) ) {
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
			'_wpd_parking'        => 'wpd_parking',
			'_wpd_floor_condition'=> 'wpd_floor_condition',
			'_wpd_notes_md'       => 'wpd_notes_md',
			'_wpd_osm_id'         => 'wpd_osm_id',
			'_wpd_osm_type'       => 'wpd_osm_type',
		);
		foreach ( $fields as $meta_key => $post_key ) {
			$value = isset( $_POST[ $post_key ] ) ? wp_unslash( $_POST[ $post_key ] ) : '';
			update_post_meta( $post_id, $meta_key, sanitize_text_field( $value ) );
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
				$this->store_notice( $post_id, sprintf( __( 'Failed to assign your organization to existing dansal location #%1$d: %2$s', 'wp-dansal' ), $use_existing_id, $assign->get_error_message() ), 'error' );
				return;
			}
			$dansal_id = $use_existing_id;
			update_post_meta( $post_id, self::META_DANSAL_ID, $dansal_id );
		}

		if ( $dansal_id ) {
			$result = $this->api->patch( "/api/v1/locations/{$dansal_id}", $payload );
			if ( is_wp_error( $result ) ) {
				/* translators: 1: dansal location ID, 2: underlying error message. */
				$this->store_notice( $post_id, sprintf( __( 'Failed to update dansal location #%1$d: %2$s', 'wp-dansal' ), $dansal_id, $result->get_error_message() ), 'error' );
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
			$this->store_notice( $post_id, sprintf( __( 'Failed to create dansal location: %s', 'wp-dansal' ), $result->get_error_message() ), 'error' );
			return;
		}

		// POST /api/v1/locations always responds with a JSON array of
		// {location, similar_locations} objects, even for a single-object
		// request body — see the handler's `json.NewEncoder(w).Encode(results)`
		// where results is []LocationCreateResponse.
		$new_id = isset( $result[0]['location']['id'] ) ? $result[0]['location']['id'] : 0;
		if ( $new_id ) {
			update_post_meta( $post_id, self::META_DANSAL_ID, $new_id );
			/* translators: %d: newly created dansal location ID. */
			$this->store_notice( $post_id, sprintf( __( 'Created dansal location #%d.', 'wp-dansal' ), $new_id ), 'success' );
		}
	}

	private function store_notice( $post_id, $message, $type ) {
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
