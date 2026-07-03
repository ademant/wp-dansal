<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "dansal_event" custom post type. Editing happens in the normal WP post
 * editor (title = event title, content = description); a meta box carries
 * everything dansal-specific. save_post creates the event in dansal on
 * first save (POST) and PATCHes on every subsequent save, per API.md's
 * "Event sync — create or update, never duplicate": the dansal event id is
 * persisted in post meta so we never re-POST the same event.
 */
class WPD_CPT_Event {

	const META_DANSAL_ID      = '_wpd_dansal_id';
	const META_LAST_SYNCED_AT = '_wpd_last_synced_at';
	const POST_TYPE           = 'dansal_event';

	/** @var WPD_Api_Client */
	private $api;
	/** @var WPD_Settings */
	private $settings;

	public function __construct( WPD_Api_Client $api, WPD_Settings $settings ) {
		$this->api      = $api;
		$this->settings = $settings;

		add_action( 'init', array( $this, 'register_post_type' ) );
		add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( $this, 'save' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'wp_ajax_wpd_search_entity', array( $this, 'ajax_search_entity' ) );
		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( $this, 'columns' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( $this, 'render_column' ), 10, 2 );
		add_action( 'load-edit.php', array( $this, 'maybe_pull_sync' ) );
	}

	public function register_post_type() {
		register_post_type(
            self::POST_TYPE,
            array(
				'labels'       => array(
					'name'          => __( 'Dance Events', 'wp-dansal' ),
					'singular_name' => __( 'Dance Event', 'wp-dansal' ),
					'add_new_item'  => __( 'Add New Event', 'wp-dansal' ),
					'edit_item'     => __( 'Edit Event', 'wp-dansal' ),
				),
				'public'       => true,
				'has_archive'  => true,
				'show_in_menu' => true,
				'menu_icon'    => 'dashicons-calendar-alt',
				'supports'     => array( 'title', 'editor' ),
				'rewrite'      => array( 'slug' => 'dance-events' ),
				'show_in_rest' => false,
            )
        );
	}

	public function columns( $columns ) {
		$new = array();
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'title' === $key ) {
				$new['wpd_start']     = __( 'Start', 'wp-dansal' );
				$new['wpd_location']  = __( 'Location', 'wp-dansal' );
				$new['wpd_dansal_id'] = __( 'Dansal ID', 'wp-dansal' );
			}
		}
		return $new;
	}

	public function render_column( $column, $post_id ) {
		switch ( $column ) {
			case 'wpd_start':
				echo esc_html( get_post_meta( $post_id, '_wpd_start_time', true ) );
				break;
			case 'wpd_location':
				$loc_id = get_post_meta( $post_id, '_wpd_location_post_id', true );
				echo $loc_id ? esc_html( get_the_title( $loc_id ) ) : '';
				break;
			case 'wpd_dansal_id':
				$id = get_post_meta( $post_id, self::META_DANSAL_ID, true );
				echo $id ? esc_html( $id ) : esc_html__( 'not synced', 'wp-dansal' );
				break;
		}
	}

	public function add_meta_boxes() {
		add_meta_box( 'wpd_event_details', __( 'Dansal Event Details', 'wp-dansal' ), array( $this, 'render_meta_box' ), self::POST_TYPE, 'normal', 'high' );
	}

	private function field( $post_id, $key, $default_value = '' ) {
		$v = get_post_meta( $post_id, $key, true );
		return '' === $v ? $default_value : $v;
	}

	private function get_location_posts() {
		return get_posts(
            array(
				'post_type'      => WPD_CPT_Location::POST_TYPE,
				'posts_per_page' => -1,
				'meta_key'       => WPD_CPT_Location::META_DANSAL_ID,
				'orderby'        => 'title',
				'order'          => 'ASC',
            )
        );
	}

	private function get_tags_vocabulary() {
		$cached = get_transient( 'wpd_tags_vocab' );
		if ( false !== $cached ) {
			return $cached;
		}
		$tags = $this->api->get_public( '/api/v1/tags' );
		if ( is_wp_error( $tags ) || ! is_array( $tags ) ) {
			return array();
		}
		set_transient( 'wpd_tags_vocab', $tags, HOUR_IN_SECONDS );
		return $tags;
	}

	private function get_dances_vocabulary() {
		$cached = get_transient( 'wpd_dances_vocab' );
		if ( false !== $cached ) {
			return $cached;
		}
		$dances = $this->api->get_public( '/api/v1/dances' );
		if ( is_wp_error( $dances ) || ! is_array( $dances ) ) {
			return array();
		}
		set_transient( 'wpd_dances_vocab', $dances, HOUR_IN_SECONDS );
		return $dances;
	}

	public function render_meta_box( $post ) {
		wp_nonce_field( 'wpd_event_save', 'wpd_event_nonce' );
		$dansal_id = get_post_meta( $post->ID, self::META_DANSAL_ID, true );
		if ( $dansal_id ) {
			printf( '<p><strong>%s%s</strong></p>', esc_html__( 'Synced with dansal event #', 'wp-dansal' ), esc_html( $dansal_id ) );
		}
		?>
		<table class="form-table">
			<tr>
				<th><label for="wpd_start_time"><?php esc_html_e( 'Start', 'wp-dansal' ); ?></label></th>
				<td><input type="datetime-local" id="wpd_start_time" name="wpd_start_time" value="<?php echo esc_attr( $this->field( $post->ID, '_wpd_start_time' ) ); ?>" required /></td>
			</tr>
			<tr>
				<th><label for="wpd_end_time"><?php esc_html_e( 'End', 'wp-dansal' ); ?></label></th>
				<td><input type="datetime-local" id="wpd_end_time" name="wpd_end_time" value="<?php echo esc_attr( $this->field( $post->ID, '_wpd_end_time' ) ); ?>" required /></td>
			</tr>
			<tr>
				<th><label for="wpd_location_post_id"><?php esc_html_e( 'Location', 'wp-dansal' ); ?></label></th>
				<td>
					<select id="wpd_location_post_id" name="wpd_location_post_id">
						<option value=""><?php esc_html_e( '— select a synced location —', 'wp-dansal' ); ?></option>
						<?php foreach ( $this->get_location_posts() as $loc ) : ?>
							<option value="<?php echo esc_attr( $loc->ID ); ?>" <?php selected( $this->field( $post->ID, '_wpd_location_post_id' ), $loc->ID ); ?>><?php echo esc_html( $loc->post_title ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'Only locations already synced to dansal (see Dance Locations) can be attached to an event.', 'wp-dansal' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Tags', 'wp-dansal' ); ?></th>
				<td>
					<?php
					$selected_tags = array_filter( explode( ',', $this->field( $post->ID, '_wpd_tags' ) ) );
					$tags_by_cat   = array();
					foreach ( $this->get_tags_vocabulary() as $tag ) {
						$tags_by_cat[ $tag['category'] ][] = $tag;
					}
					foreach ( $tags_by_cat as $category => $tags ) :
						?>
						<p><strong><?php echo esc_html( ucfirst( $category ) ); ?>:</strong>
						<?php foreach ( $tags as $tag ) : ?>
							<label style="margin-right:1em;display:inline-block;">
								<input type="checkbox" name="wpd_tags[]" value="<?php echo esc_attr( $tag['slug'] ); ?>" <?php checked( in_array( $tag['slug'], $selected_tags, true ) ); ?> />
								<?php echo esc_html( $tag['name'] ); ?>
							</label>
						<?php endforeach; ?>
						</p>
					<?php endforeach; ?>
				</td>
			</tr>
			<tr>
				<th><label for="wpd_dance_ids"><?php esc_html_e( 'Dances', 'wp-dansal' ); ?></label></th>
				<td>
					<?php $selected_dances = array_filter( explode( ',', $this->field( $post->ID, '_wpd_dance_ids' ) ) ); ?>
					<select id="wpd_dance_ids" name="wpd_dance_ids[]" multiple size="6" style="min-width:260px;">
						<?php foreach ( $this->get_dances_vocabulary() as $dance ) : ?>
							<option value="<?php echo esc_attr( $dance['id'] ); ?>" <?php selected( in_array( (string) $dance['id'], $selected_dances, true ) ); ?>><?php echo esc_html( $dance['name'] ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Musicians', 'wp-dansal' ); ?></th>
				<td><?php $this->render_entity_picker( $post->ID, 'musician', __( 'Search musicians…', 'wp-dansal' ) ); ?></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Instructors', 'wp-dansal' ); ?></th>
				<td><?php $this->render_entity_picker( $post->ID, 'instructor', __( 'Search instructors…', 'wp-dansal' ) ); ?></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Event type', 'wp-dansal' ); ?></th>
				<td>
					<?php
                    foreach ( array(
						'has_ball' => __( 'Ball', 'wp-dansal' ),
						'has_workshop' => __( 'Workshop', 'wp-dansal' ),
						'has_festival' => __( 'Festival', 'wp-dansal' ),
					) as $key => $label ) :
						?>
						<label style="margin-right:1em;display:inline-block;">
							<input type="checkbox" name="wpd_<?php echo esc_attr( $key ); ?>" value="1" <?php checked( $this->field( $post->ID, '_wpd_' . $key ), '1' ); ?> />
							<?php echo esc_html( $label ); ?>
						</label>
					<?php endforeach; ?>
				</td>
			</tr>
			<tr>
				<th><label for="wpd_workshop_difficulty"><?php esc_html_e( 'Workshop difficulty', 'wp-dansal' ); ?></label></th>
				<td>
					<input type="text" id="wpd_workshop_difficulty" name="wpd_workshop_difficulty" list="wpd-difficulties" value="<?php echo esc_attr( $this->field( $post->ID, '_wpd_workshop_difficulty' ) ); ?>" />
					<datalist id="wpd-difficulties">
						<option value="beginner"></option>
						<option value="intermediate"></option>
						<option value="advanced"></option>
						<option value="open"></option>
					</datalist>
				</td>
			</tr>
			<tr>
				<th><label for="wpd_booking_url"><?php esc_html_e( 'Booking URL', 'wp-dansal' ); ?></label></th>
				<td><input type="url" id="wpd_booking_url" name="wpd_booking_url" class="regular-text" value="<?php echo esc_attr( $this->field( $post->ID, '_wpd_booking_url' ) ); ?>" /></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Pricing', 'wp-dansal' ); ?></th>
				<td>
					<select name="wpd_pricing_type">
						<?php
                        foreach ( array(
							'free' => __( 'Free', 'wp-dansal' ),
							'fixed' => __( 'Fixed price', 'wp-dansal' ),
							'donation' => __( 'Donation', 'wp-dansal' ),
						) as $key => $label ) :
							?>
							<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $this->field( $post->ID, '_wpd_pricing_type', 'free' ), $key ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<input type="number" step="0.01" min="0" name="wpd_pricing_amount" placeholder="<?php esc_attr_e( 'Amount', 'wp-dansal' ); ?>" value="<?php echo esc_attr( $this->field( $post->ID, '_wpd_pricing_amount' ) ); ?>" class="small-text" />
					<input type="text" name="wpd_pricing_currency" placeholder="EUR" maxlength="3" value="<?php echo esc_attr( $this->field( $post->ID, '_wpd_pricing_currency', 'EUR' ) ); ?>" class="small-text" />
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Food & drink', 'wp-dansal' ); ?></th>
				<td>
					<input type="text" name="wpd_food" placeholder="<?php esc_attr_e( 'Food', 'wp-dansal' ); ?>" value="<?php echo esc_attr( $this->field( $post->ID, '_wpd_food' ) ); ?>" class="regular-text" />
					<input type="text" name="wpd_drink" placeholder="<?php esc_attr_e( 'Drink', 'wp-dansal' ); ?>" value="<?php echo esc_attr( $this->field( $post->ID, '_wpd_drink' ) ); ?>" class="regular-text" />
				</td>
			</tr>
			<tr>
				<th><label for="wpd_floor_condition"><?php esc_html_e( 'Floor condition override', 'wp-dansal' ); ?></label></th>
				<td>
					<input type="text" id="wpd_floor_condition" name="wpd_floor_condition" list="wpd-floor-conditions" value="<?php echo esc_attr( $this->field( $post->ID, '_wpd_floor_condition' ) ); ?>" />
					<datalist id="wpd-floor-conditions">
						<option value="wooden parquet"></option>
						<option value="stone floor"></option>
						<option value="grass"></option>
						<option value="tiles"></option>
						<option value="sand / gravel"></option>
						<option value="pavement"></option>
					</datalist>
					<p class="description"><?php esc_html_e( 'Leave blank to use the location\'s floor condition.', 'wp-dansal' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Amenities override', 'wp-dansal' ); ?></th>
				<td>
					<?php
                    foreach ( array(
						'wheelchair' => __( 'Wheelchair accessible', 'wp-dansal' ),
						'bar' => __( 'Bar', 'wp-dansal' ),
						'kitchen' => __( 'Kitchen', 'wp-dansal' ),
					) as $key => $label ) :
						?>
						<label style="margin-right:1em;display:inline-block;">
							<input type="checkbox" name="wpd_attr_<?php echo esc_attr( $key ); ?>" value="1" <?php checked( $this->field( $post->ID, '_wpd_attr_' . $key ), '1' ); ?> />
							<?php echo esc_html( $label ); ?>
						</label>
					<?php endforeach; ?>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Contact', 'wp-dansal' ); ?></th>
				<td>
					<input type="text" name="wpd_contact_name" placeholder="<?php esc_attr_e( 'Name', 'wp-dansal' ); ?>" value="<?php echo esc_attr( $this->field( $post->ID, '_wpd_contact_name' ) ); ?>" class="regular-text" />
					<input type="email" name="wpd_contact_email" placeholder="<?php esc_attr_e( 'Email', 'wp-dansal' ); ?>" value="<?php echo esc_attr( $this->field( $post->ID, '_wpd_contact_email' ) ); ?>" class="regular-text" />
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Status', 'wp-dansal' ); ?></th>
				<td>
					<p class="description">
						<?php
						if ( 'publish' === get_post_status( $post->ID ) ) {
							esc_html_e( 'This post is published, so it is synced to dansal as a public event. Switch it back to Draft/Pending to unpublish it in dansal too.', 'wp-dansal' );
						} else {
							esc_html_e( 'This post is not published yet, so it is synced to dansal as a draft (not publicly visible). Publishing this WordPress post makes it public in dansal.', 'wp-dansal' );
						}
						?>
					</p>
					<label>
						<input type="checkbox" name="wpd_is_cancelled" value="1" <?php checked( $this->field( $post->ID, '_wpd_is_cancelled' ), '1' ); ?> />
						<?php esc_html_e( 'Cancelled', 'wp-dansal' ); ?>
					</label>
				</td>
			</tr>
		</table>
		<?php
	}

	private function render_entity_picker( $post_id, $type, $placeholder ) {
		$ids   = array_values( array_filter( explode( ',', $this->field( $post_id, '_wpd_' . $type . '_ids' ) ) ) );
		$names = array_values( array_filter( explode( '|', $this->field( $post_id, '_wpd_' . $type . '_names' ) ) ) );
		$names = array_slice( array_pad( $names, count( $ids ), '' ), 0, count( $ids ) );
		$chips = array_combine( $ids, $names );
		?>
		<div class="wpd-entity-picker" data-type="<?php echo esc_attr( $type ); ?>">
			<div class="wpd-entity-chips">
				<?php foreach ( $chips as $id => $name ) : ?>
					<span class="wpd-chip" data-id="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $name ); ?> <a href="#" class="wpd-chip-remove">&times;</a></span>
				<?php endforeach; ?>
			</div>
			<input type="text" class="wpd-entity-search regular-text" placeholder="<?php echo esc_attr( $placeholder ); ?>" />
			<div class="wpd-entity-results"></div>
			<input type="hidden" class="wpd-entity-ids" name="wpd_<?php echo esc_attr( $type ); ?>_ids" value="<?php echo esc_attr( implode( ',', array_keys( $chips ) ) ); ?>" />
			<input type="hidden" class="wpd-entity-names" name="wpd_<?php echo esc_attr( $type ); ?>_names" value="<?php echo esc_attr( implode( '|', $chips ) ); ?>" />
		</div>
		<?php
	}

	public function enqueue_admin_assets( $hook ) {
		$screen = get_current_screen();
		if ( ! $screen || self::POST_TYPE !== $screen->post_type || ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}
		wp_enqueue_style( 'wpd-admin', WPD_PLUGIN_URL . 'assets/css/admin.css', array(), WPD_VERSION );
		wp_enqueue_script( 'wpd-admin-event', WPD_PLUGIN_URL . 'assets/js/admin-event.js', array( 'jquery' ), WPD_VERSION, true );
		wp_localize_script(
            'wpd-admin-event',
            'wpdEvent',
            array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'wpd_search_entity' ),
            )
        );
	}

	public function ajax_search_entity() {
		check_ajax_referer( 'wpd_search_entity' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'wp-dansal' ) ), 403 );
		}

		$type = isset( $_GET['type'] ) ? sanitize_key( $_GET['type'] ) : '';
		$q    = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
		if ( ! in_array( $type, array( 'musician', 'instructor' ), true ) || strlen( $q ) < 2 ) {
			wp_send_json_error( array( 'message' => __( 'Invalid search.', 'wp-dansal' ) ) );
		}

		$path = 'musician' === $type ? '/api/v1/musicians' : '/api/v1/instructors';
		// Musician's display field is "bandname"; Instructor's is "name".
		$name_key = 'musician' === $type ? 'bandname' : 'name';
		$result   = $this->api->get_public( $path, array( 'name' => $q ) );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		$list = is_array( $result ) ? $result : array();
		$out  = array();
		foreach ( $list as $item ) {
			if ( isset( $item['id'], $item[ $name_key ] ) ) {
				$out[] = array(
					'id' => $item['id'],
					'name' => $item[ $name_key ],
				);
			}
		}
		wp_send_json_success( $out );
	}

	public function save( $post_id ) {
		if ( ! isset( $_POST['wpd_event_nonce'] ) || ! wp_verify_nonce( $_POST['wpd_event_nonce'], 'wpd_event_save' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$text_fields = array(
			'_wpd_start_time'          => 'wpd_start_time',
			'_wpd_end_time'            => 'wpd_end_time',
			'_wpd_location_post_id'    => 'wpd_location_post_id',
			'_wpd_workshop_difficulty' => 'wpd_workshop_difficulty',
			'_wpd_booking_url'         => 'wpd_booking_url',
			'_wpd_pricing_type'        => 'wpd_pricing_type',
			'_wpd_pricing_amount'      => 'wpd_pricing_amount',
			'_wpd_pricing_currency'    => 'wpd_pricing_currency',
			'_wpd_food'                => 'wpd_food',
			'_wpd_drink'               => 'wpd_drink',
			'_wpd_floor_condition'     => 'wpd_floor_condition',
			'_wpd_contact_name'        => 'wpd_contact_name',
			'_wpd_contact_email'       => 'wpd_contact_email',
			'_wpd_musician_ids'        => 'wpd_musician_ids',
			'_wpd_musician_names'      => 'wpd_musician_names',
			'_wpd_instructor_ids'      => 'wpd_instructor_ids',
			'_wpd_instructor_names'    => 'wpd_instructor_names',
		);
		foreach ( $text_fields as $meta_key => $post_key ) {
			$value = isset( $_POST[ $post_key ] ) ? wp_unslash( $_POST[ $post_key ] ) : '';
			update_post_meta( $post_id, $meta_key, sanitize_text_field( $value ) );
		}

		$tags = isset( $_POST['wpd_tags'] ) ? array_map( 'sanitize_key', (array) $_POST['wpd_tags'] ) : array();
		// Padded with boundary commas (",slug1,slug2,") so frontend meta_query
		// LIKE lookups can match a whole slug without false-positiving on
		// substrings (e.g. "ball" inside "small-ball").
		update_post_meta( $post_id, '_wpd_tags', $tags ? ',' . implode( ',', $tags ) . ',' : '' );
		update_post_meta( $post_id, '_wpd_dance_ids', isset( $_POST['wpd_dance_ids'] ) ? implode( ',', array_map( 'absint', (array) $_POST['wpd_dance_ids'] ) ) : '' );

		foreach ( array( 'has_ball', 'has_workshop', 'has_festival', 'is_cancelled' ) as $flag ) {
			update_post_meta( $post_id, '_wpd_' . $flag, ! empty( $_POST[ 'wpd_' . $flag ] ) ? '1' : '' );
		}
		foreach ( array( 'wheelchair', 'bar', 'kitchen' ) as $attr ) {
			update_post_meta( $post_id, '_wpd_attr_' . $attr, ! empty( $_POST[ 'wpd_attr_' . $attr ] ) ? '1' : '' );
		}

		if ( ! $this->settings->is_configured() ) {
			return;
		}

		$this->sync_to_dansal( $post_id );
	}

	private function to_rfc3339( $datetime_local ) {
		if ( ! $datetime_local ) {
			return '';
		}
		try {
			$dt = new DateTime( $datetime_local, wp_timezone() );
			return $dt->format( DateTime::RFC3339 );
		} catch ( Exception $e ) {
			return '';
		}
	}

	private function build_payload( $post_id ) {
		$get = function ( $key ) use ( $post_id ) {
			return get_post_meta( $post_id, $key, true );
		};

		$location_post_id   = (int) $get( '_wpd_location_post_id' );
		$location_dansal_id = $location_post_id ? (int) get_post_meta( $location_post_id, WPD_CPT_Location::META_DANSAL_ID, true ) : 0;

		$pricing = null;
		if ( '' !== $get( '_wpd_pricing_type' ) ) {
			$pricing = array(
				'type'     => $get( '_wpd_pricing_type' ),
				'amount'   => (float) $get( '_wpd_pricing_amount' ),
				'currency' => $get( '_wpd_pricing_currency' ) ? $get( '_wpd_pricing_currency' ) : 'EUR',
			);
		}

		$post = get_post( $post_id );

		return array(
			'title'              => get_the_title( $post_id ),
			'description'        => $post ? $post->post_content : '',
			'start_time'         => $this->to_rfc3339( $get( '_wpd_start_time' ) ),
			'end_time'           => $this->to_rfc3339( $get( '_wpd_end_time' ) ),
			'has_ball'           => '1' === $get( '_wpd_has_ball' ),
			'has_workshop'       => '1' === $get( '_wpd_has_workshop' ),
			'has_festival'       => '1' === $get( '_wpd_has_festival' ),
			'workshop_difficulty'=> $get( '_wpd_workshop_difficulty' ),
			'is_cancelled'       => '1' === $get( '_wpd_is_cancelled' ),
			// Mirrors WordPress's own editorial workflow instead of a separate
			// checkbox: WP core already refuses to let a post reach 'publish'
			// status unless the saving user has the publish_posts capability
			// (Contributors are forced to 'pending'), so this can't be used
			// to bypass approval the way an independent checkbox could.
			'is_published'       => 'publish' === get_post_status( $post_id ),
			'tags'               => array_values( array_filter( explode( ',', $get( '_wpd_tags' ) ) ) ),
			'organization_id'    => $this->settings->get_org_id(),
			'location_id'        => $location_dansal_id ? $location_dansal_id : null,
			'pricing'            => $pricing,
			'musicians'          => array_values( array_filter( array_map( 'absint', explode( ',', $get( '_wpd_musician_ids' ) ) ) ) ),
			'instructors'        => array_values( array_filter( array_map( 'absint', explode( ',', $get( '_wpd_instructor_ids' ) ) ) ) ),
			'dances'             => array_values( array_filter( array_map( 'absint', explode( ',', $get( '_wpd_dance_ids' ) ) ) ) ),
			'booking_url'        => $get( '_wpd_booking_url' ),
			'food'               => $get( '_wpd_food' ),
			'drink'              => $get( '_wpd_drink' ),
			'floor_condition'    => $get( '_wpd_floor_condition' ),
			'attributes'         => array(
				'wheelchair' => '1' === $get( '_wpd_attr_wheelchair' ),
				'bar'        => '1' === $get( '_wpd_attr_bar' ),
				'kitchen'    => '1' === $get( '_wpd_attr_kitchen' ),
			),
			'contact_name'       => $get( '_wpd_contact_name' ),
			'contact_email'      => $get( '_wpd_contact_email' ),
		);
	}

	private function sync_to_dansal( $post_id ) {
		$payload   = $this->build_payload( $post_id );
		$dansal_id = (int) get_post_meta( $post_id, self::META_DANSAL_ID, true );

		if ( empty( $payload['location_id'] ) ) {
			/* translators: %s: event title. */
			$this->store_notice( sprintf( __( 'Event "%s" was saved locally but not synced to dansal: select a synced location first.', 'wp-dansal' ), get_the_title( $post_id ) ), 'error' );
			return;
		}

		if ( $dansal_id ) {
			// dansal's event-update route is registered as PUT, not PATCH,
			// despite API.md documenting PATCH (confirmed against
			// cmd/dansal/main.go: `smux.Handle("PUT /api/v1/events/{id}", ...)`
			// — there is no PATCH route for events on the server at all).
			$result = $this->api->put( "/api/v1/events/{$dansal_id}", $payload );
			if ( is_wp_error( $result ) ) {
				/* translators: 1: dansal event ID, 2: underlying error message. */
				$this->store_notice( sprintf( __( 'Failed to update dansal event #%1$d: %2$s', 'wp-dansal' ), $dansal_id, $result->get_error_message() ), 'error' );
			} else {
				// Marks this push as the most recent known sync point, so a
				// pull-sync (maybe_pull_sync()) right after doesn't consider
				// dansal "newer" than what we just wrote and pull it right
				// back on top of itself.
				update_post_meta( $post_id, self::META_LAST_SYNCED_AT, time() );
			}
			return;
		}

		$result = $this->api->post( '/api/v1/events', $payload );
		if ( is_wp_error( $result ) ) {
			/* translators: %s: underlying error message. */
			$this->store_notice( sprintf( __( 'Failed to create dansal event: %s', 'wp-dansal' ), $result->get_error_message() ), 'error' );
			return;
		}

		// POST /api/v1/events always responds with a JSON array of created
		// events (even for a single-object request body) — see
		// createEvent()'s `json.NewEncoder(w).Encode(allCreatedEvents)`.
		$new_id = isset( $result[0]['id'] ) ? $result[0]['id'] : 0;
		if ( $new_id ) {
			update_post_meta( $post_id, self::META_DANSAL_ID, $new_id );
			update_post_meta( $post_id, self::META_LAST_SYNCED_AT, time() );
			/* translators: %d: newly created dansal event ID. */
			$this->store_notice( sprintf( __( 'Created dansal event #%d.', 'wp-dansal' ), $new_id ), 'success' );
		}
	}

	/**
	 * Pull-sync: import/refresh the org's dansal events into WordPress.
	 * Runs lazily whenever an admin opens the Dance Events list screen (see
	 * load-edit.php hook). Authenticated (not get_public()) so it also
	 * picks up the org's unpublished/draft events, per API.md: "Authenticated
	 * users see their organization's draft events too."
	 */
	public function maybe_pull_sync() {
		global $typenow;
		if ( self::POST_TYPE !== $typenow || ! $this->settings->is_configured() ) {
			return;
		}
		if ( get_transient( 'wpd_event_pull_lock' ) ) {
			return;
		}
		set_transient( 'wpd_event_pull_lock', 1, 30 );

		$result = $this->api->get( '/api/v1/events', array( 'organization_id' => $this->settings->get_org_id() ) );
		if ( is_wp_error( $result ) ) {
			return;
		}

		$created = 0;
		$updated = 0;
		$events  = is_array( $result ) ? $result : array();
		foreach ( $events as $event ) {
			if ( ! is_array( $event ) ) {
				continue;
			}
			$status = $this->pull_one_event( $event );
			if ( 'created' === $status ) {
				++$created;
			} elseif ( 'updated' === $status ) {
				++$updated;
			}
		}

		if ( $created || $updated ) {
			$this->store_notice(
				sprintf(
					/* translators: 1: number of newly imported events, 2: number of refreshed events. */
					__( 'Synced with dansal: %1$d new event(s) imported, %2$d refreshed.', 'wp-dansal' ),
					$created,
					$updated
				),
				'success'
			);
		}
	}

	/**
	 * Frontend visitors trigger a lightweight single-item refresh (one
	 * GET /api/v1/events/{id}, not the whole org list) when viewing this
	 * event's page, rate-limited per post so repeated page views/bot
	 * traffic don't hammer dansal. This exists because maybe_pull_sync()
	 * only runs when someone opens the Dance Events list in wp-admin — a
	 * public page would otherwise show stale data until that happens.
	 */
	public function maybe_refresh_single( $post_id ) {
		if ( ! $this->settings->is_configured() ) {
			return;
		}
		$dansal_id = (int) get_post_meta( $post_id, self::META_DANSAL_ID, true );
		if ( ! $dansal_id ) {
			return;
		}
		$lock_key = 'wpd_event_refresh_' . $post_id;
		if ( get_transient( $lock_key ) ) {
			return;
		}
		set_transient( $lock_key, 1, 5 * MINUTE_IN_SECONDS );

		$event = $this->api->get( "/api/v1/events/{$dansal_id}" );
		if ( is_wp_error( $event ) || ! is_array( $event ) || empty( $event['id'] ) ) {
			return;
		}

		$changed_at  = ! empty( $event['changed_at'] ) ? strtotime( $event['changed_at'] ) : 0;
		$last_synced = (int) get_post_meta( $post_id, self::META_LAST_SYNCED_AT, true );
		if ( $changed_at && $changed_at <= $last_synced ) {
			return; // Already current.
		}

		$this->write_event_post( $post_id, $event );
	}

	/**
	 * @return int WordPress post ID linked to this dansal event, or 0.
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
	private function pull_one_event( array $event ) {
		if ( empty( $event['id'] ) ) {
			return null;
		}
		$dansal_id  = (int) $event['id'];
		$changed_at = ! empty( $event['changed_at'] ) ? strtotime( $event['changed_at'] ) : 0;

		$post_id = self::find_post_id_by_dansal_id( $dansal_id );

		if ( $post_id ) {
			$last_synced = (int) get_post_meta( $post_id, self::META_LAST_SYNCED_AT, true );
			if ( $changed_at && $changed_at <= $last_synced ) {
				return null; // Local copy is already current.
			}
			$this->write_event_post( $post_id, $event );
			return 'updated';
		}

		remove_action( 'save_post_' . self::POST_TYPE, array( $this, 'save' ) );
		$post_id = wp_insert_post(
			array(
				'post_type'    => self::POST_TYPE,
				'post_status'  => ! empty( $event['is_published'] ) ? 'publish' : 'draft',
				'post_title'   => isset( $event['title'] ) ? $event['title'] : '',
				'post_content' => isset( $event['description'] ) ? $event['description'] : '',
			),
			true
		);
		add_action( 'save_post_' . self::POST_TYPE, array( $this, 'save' ) );

		if ( is_wp_error( $post_id ) || ! $post_id ) {
			return null;
		}

		$this->write_event_post( $post_id, $event );
		return 'created';
	}

	private function from_rfc3339( $value ) {
		if ( ! $value ) {
			return '';
		}
		try {
			$dt = new DateTime( $value );
			$dt->setTimezone( wp_timezone() );
			return $dt->format( 'Y-m-d\TH:i' );
		} catch ( Exception $e ) {
			return '';
		}
	}

	/**
	 * Resolves dansal's dance_names (the list endpoint has no dance IDs) back
	 * to local dance IDs by matching against the cached /api/v1/dances
	 * vocabulary, which is also what the meta box's dance <select> uses.
	 */
	private function resolve_dance_ids( $names ) {
		if ( empty( $names ) || ! is_array( $names ) ) {
			return array();
		}
		$by_name = array();
		foreach ( $this->get_dances_vocabulary() as $dance ) {
			if ( isset( $dance['name'], $dance['id'] ) ) {
				$by_name[ $dance['name'] ] = (int) $dance['id'];
			}
		}
		$ids = array();
		foreach ( $names as $name ) {
			if ( isset( $by_name[ $name ] ) ) {
				$ids[] = $by_name[ $name ];
			}
		}
		return $ids;
	}

	/**
	 * Writes a dansal event object onto a (new or existing) local post.
	 * Only touches title/content/status via wp_update_post() if something
	 * actually changed; save_post is unhooked around that call so the pull
	 * can't re-trigger a push right back to dansal.
	 */
	private function write_event_post( $post_id, array $event ) {
		$title       = isset( $event['title'] ) ? $event['title'] : '';
		$description = isset( $event['description'] ) ? $event['description'] : '';
		$status      = ! empty( $event['is_published'] ) ? 'publish' : 'draft';

		$post = get_post( $post_id );
		if ( $post && ( $post->post_title !== $title || $post->post_content !== $description || $post->post_status !== $status ) ) {
			remove_action( 'save_post_' . self::POST_TYPE, array( $this, 'save' ) );
			wp_update_post(
				array(
					'ID'           => $post_id,
					'post_title'   => $title,
					'post_content' => $description,
					'post_status'  => $status,
				)
			);
			add_action( 'save_post_' . self::POST_TYPE, array( $this, 'save' ) );
		}

		update_post_meta( $post_id, '_wpd_start_time', $this->from_rfc3339( isset( $event['start_time'] ) ? $event['start_time'] : '' ) );
		update_post_meta( $post_id, '_wpd_end_time', $this->from_rfc3339( isset( $event['end_time'] ) ? $event['end_time'] : '' ) );

		$location_id      = isset( $event['location_id'] ) ? (int) $event['location_id'] : 0;
		$location_post_id = $location_id ? WPD_CPT_Location::find_post_id_by_dansal_id( $location_id ) : 0;
		update_post_meta( $post_id, '_wpd_location_post_id', $location_post_id ? $location_post_id : '' );

		$tags = isset( $event['tags'] ) && is_array( $event['tags'] ) ? array_map( 'sanitize_key', $event['tags'] ) : array();
		update_post_meta( $post_id, '_wpd_tags', $tags ? ',' . implode( ',', $tags ) . ',' : '' );

		update_post_meta( $post_id, '_wpd_dance_ids', implode( ',', $this->resolve_dance_ids( isset( $event['dance_names'] ) ? $event['dance_names'] : array() ) ) );

		$musicians = isset( $event['musicians'] ) && is_array( $event['musicians'] ) ? $event['musicians'] : array();
		update_post_meta( $post_id, '_wpd_musician_ids', implode( ',', wp_list_pluck( $musicians, 'id' ) ) );
		update_post_meta( $post_id, '_wpd_musician_names', implode( '|', wp_list_pluck( $musicians, 'bandname' ) ) );

		$instructors = isset( $event['instructors'] ) && is_array( $event['instructors'] ) ? $event['instructors'] : array();
		update_post_meta( $post_id, '_wpd_instructor_ids', implode( ',', wp_list_pluck( $instructors, 'id' ) ) );
		update_post_meta( $post_id, '_wpd_instructor_names', implode( '|', wp_list_pluck( $instructors, 'name' ) ) );

		foreach ( array( 'has_ball', 'has_workshop', 'has_festival', 'is_cancelled' ) as $flag ) {
			update_post_meta( $post_id, '_wpd_' . $flag, ! empty( $event[ $flag ] ) ? '1' : '' );
		}

		update_post_meta( $post_id, '_wpd_workshop_difficulty', isset( $event['workshop_difficulty'] ) ? $event['workshop_difficulty'] : '' );
		update_post_meta( $post_id, '_wpd_booking_url', isset( $event['booking_url'] ) ? $event['booking_url'] : '' );
		update_post_meta( $post_id, '_wpd_food', isset( $event['food'] ) ? $event['food'] : '' );
		update_post_meta( $post_id, '_wpd_drink', isset( $event['drink'] ) ? $event['drink'] : '' );
		update_post_meta( $post_id, '_wpd_floor_condition', isset( $event['floor_condition'] ) ? $event['floor_condition'] : '' );
		update_post_meta( $post_id, '_wpd_contact_name', isset( $event['contact_name'] ) ? $event['contact_name'] : '' );
		update_post_meta( $post_id, '_wpd_contact_email', isset( $event['contact_email'] ) ? $event['contact_email'] : '' );

		$pricing = isset( $event['pricing'] ) && is_array( $event['pricing'] ) ? $event['pricing'] : array();
		update_post_meta( $post_id, '_wpd_pricing_type', isset( $pricing['type'] ) ? $pricing['type'] : '' );
		update_post_meta( $post_id, '_wpd_pricing_amount', isset( $pricing['amount'] ) ? $pricing['amount'] : '' );
		update_post_meta( $post_id, '_wpd_pricing_currency', isset( $pricing['currency'] ) ? $pricing['currency'] : '' );

		$attrs = isset( $event['attributes'] ) && is_array( $event['attributes'] ) ? $event['attributes'] : array();
		foreach ( array( 'wheelchair', 'bar', 'kitchen' ) as $attr ) {
			update_post_meta( $post_id, '_wpd_attr_' . $attr, ! empty( $attrs[ $attr ] ) ? '1' : '' );
		}

		update_post_meta( $post_id, self::META_DANSAL_ID, (int) $event['id'] );
		update_post_meta( $post_id, self::META_LAST_SYNCED_AT, time() );
	}

	private function store_notice( $message, $type ) {
		$key       = 'wpd_admin_notices_' . get_current_user_id();
		$notices   = get_transient( $key );
		$notices   = is_array( $notices ) ? $notices : array();
		$notices[] = array(
			'message' => $message,
			'type' => $type,
		);
		set_transient( $key, $notices, MINUTE_IN_SECONDS * 5 );
	}
}
