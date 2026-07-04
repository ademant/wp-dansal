<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "dansal_series" custom post type. A named, recurring event shape (e.g.
 * "Balfolk im Münster") that lives both here and in dansal via
 * /api/v1/series. Storage/lifecycle mirrors WPD_CPT_Event (POST first,
 * PUT on subsequent saves, pull-sync on list-screen load), sharing the
 * shared field group + preset helpers introduced in #10 and #11.
 */
class WPD_CPT_Series {

	const POST_TYPE           = 'dansal_series';
	const META_DANSAL_ID      = '_wpd_series_dansal_id';
	const META_LAST_SYNCED_AT = '_wpd_series_last_synced_at';

	/** @var WPD_Api_Client */
	private $api;
	/** @var WPD_Settings */
	private $settings;
	/** @var WPD_Event_Fields */
	private $fields;

	public function __construct( WPD_Api_Client $api, WPD_Settings $settings, WPD_Event_Fields $fields ) {
		$this->api      = $api;
		$this->settings = $settings;
		$this->fields   = $fields;

		add_action( 'init', array( $this, 'register_post_type' ) );
		add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( $this, 'save' ) );
		add_action( 'load-edit.php', array( $this, 'maybe_pull_sync' ) );
		add_action( 'post_submitbox_misc_actions', array( $this, 'render_add_date_button' ) );

		WPD_Admin_Action::register( 'wpd_series_add_date', 'edit_posts', array( $this, 'handle_add_date' ) );

		// Series-driven "Add {series title}" entry points on the event list —
		// same pattern as templates in #11, different preset callback.
		$series_presets = array( $this, 'get_series_presets' );
		/* translators: %s: series name (e.g. "Balfolk im Münster"). */
		$label_template = __( 'Add %s', 'wp-dansal' );
		WPD_Preset_Menu::register(
			'edit.php?post_type=' . WPD_CPT_Event::POST_TYPE,
			$series_presets,
			'wpd_series_add_date',
			'series',
			$label_template
		);
		WPD_Preset_Buttons::register(
			WPD_CPT_Event::POST_TYPE,
			$series_presets,
			'wpd_series_add_date',
			'series',
			$label_template
		);
	}

	public function register_post_type() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'       => array(
					'name'          => __( 'Dance Series', 'wp-dansal' ),
					'singular_name' => __( 'Dance Series', 'wp-dansal' ),
					'add_new_item'  => __( 'Add New Series', 'wp-dansal' ),
					'edit_item'     => __( 'Edit Series', 'wp-dansal' ),
				),
				'public'       => false,
				'show_ui'      => true,
				'show_in_menu' => true,
				'menu_icon'    => 'dashicons-update',
				'supports'     => array( 'title', 'editor' ),
				'show_in_rest' => false,
			)
		);
	}

	public function add_meta_boxes() {
		add_meta_box( 'wpd_series_details', __( 'Dansal Series Details', 'wp-dansal' ), array( $this, 'render_meta_box' ), self::POST_TYPE, 'normal', 'high' );
	}

	public function render_meta_box( $post ) {
		wp_nonce_field( 'wpd_series_save', 'wpd_series_nonce' );
		$dansal_id = get_post_meta( $post->ID, self::META_DANSAL_ID, true );
		if ( $dansal_id ) {
			printf( '<p><strong>%s%s</strong></p>', esc_html__( 'Synced with dansal series #', 'wp-dansal' ), esc_html( $dansal_id ) );
		}

		$stored = array();
		foreach ( WPD_Event_Fields::overlay_keys() as $key ) {
			$stored[ $key ] = get_post_meta( $post->ID, $key, true );
		}

		$start_time = get_post_meta( $post->ID, '_wpd_series_start_time_of_day', true );
		$end_time   = get_post_meta( $post->ID, '_wpd_series_end_time_of_day', true );
		?>
		<table class="form-table">
			<tr>
				<th><label for="wpd_series_start_time"><?php esc_html_e( 'Default start time of day', 'wp-dansal' ); ?></label></th>
				<td><input type="time" id="wpd_series_start_time" name="wpd_series_start_time_of_day" value="<?php echo esc_attr( $start_time ); ?>" /></td>
			</tr>
			<tr>
				<th><label for="wpd_series_end_time"><?php esc_html_e( 'Default end time of day', 'wp-dansal' ); ?></label></th>
				<td><input type="time" id="wpd_series_end_time" name="wpd_series_end_time_of_day" value="<?php echo esc_attr( $end_time ); ?>" /></td>
			</tr>
			<?php $this->fields->render_field_group( $stored, 'wpd_series' ); ?>
		</table>
		<?php
	}

	public function render_add_date_button( $post ) {
		if ( self::POST_TYPE !== $post->post_type || ! current_user_can( 'edit_post', $post->ID ) ) {
			return;
		}
		if ( 'auto-draft' === $post->post_status ) {
			return;
		}
		// The Publish metabox lives inside <form id="post">, so we can't
		// nest another <form>. Instead, emit a plain input + button and
		// build the target URL in JS on click.
		$base_url = WPD_Admin_Action::url( 'wpd_series_add_date', array( 'series' => $post->ID ) );
		?>
		<div class="misc-pub-section wpd-series-add-date">
			<label for="wpd_series_add_date_input" style="display:block; margin-bottom:0.5em;"><?php esc_html_e( 'Add a date to this series', 'wp-dansal' ); ?></label>
			<span style="display:flex; gap:0.5em;">
				<input type="date" id="wpd_series_add_date_input" style="flex:1;" />
				<button type="button" class="button" id="wpd_series_add_date_button" data-base="<?php echo esc_attr( $base_url ); ?>"><?php esc_html_e( 'Create draft', 'wp-dansal' ); ?></button>
			</span>
		</div>
		<script>
		(function () {
			var btn = document.getElementById('wpd_series_add_date_button');
			var input = document.getElementById('wpd_series_add_date_input');
			if (!btn || !input) { return; }
			btn.addEventListener('click', function () {
				if (!input.value) { input.focus(); return; }
				var base = btn.getAttribute('data-base');
				var sep = base.indexOf('?') === -1 ? '?' : '&';
				window.location.href = base + sep + 'date=' + encodeURIComponent(input.value);
			});
		})();
		</script>
		<?php
	}

	public function save( $post_id ) {
		if ( ! isset( $_POST['wpd_series_nonce'] ) || ! wp_verify_nonce( $_POST['wpd_series_nonce'], 'wpd_series_save' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		update_post_meta( $post_id, '_wpd_series_start_time_of_day', $this->sanitize_time_of_day( isset( $_POST['wpd_series_start_time_of_day'] ) ? wp_unslash( $_POST['wpd_series_start_time_of_day'] ) : '' ) );
		update_post_meta( $post_id, '_wpd_series_end_time_of_day', $this->sanitize_time_of_day( isset( $_POST['wpd_series_end_time_of_day'] ) ? wp_unslash( $_POST['wpd_series_end_time_of_day'] ) : '' ) );

		$overlay_input = isset( $_POST['wpd_series'] ) && is_array( $_POST['wpd_series'] ) ? $_POST['wpd_series'] : array();
		$overlay       = WPD_Event_Fields::sanitize_field_group( $overlay_input );
		foreach ( WPD_Event_Fields::overlay_keys() as $key ) {
			update_post_meta( $post_id, $key, isset( $overlay[ $key ] ) ? $overlay[ $key ] : '' );
		}

		if ( ! $this->settings->is_configured() ) {
			return;
		}

		$this->sync_to_dansal( $post_id );
	}

	private function sanitize_time_of_day( $value ) {
		$v = trim( (string) $value );
		if ( '' === $v ) {
			return '';
		}
		return preg_match( '/^\d{1,2}:\d{2}$/', $v ) ? sprintf( '%02d:%02d', ...array_map( 'intval', explode( ':', $v ) ) ) : '';
	}

	private function build_payload( $post_id ) {
		$post             = get_post( $post_id );
		$location_post_id = (int) get_post_meta( $post_id, '_wpd_location_post_id', true );
		$location_dansal  = $location_post_id ? (int) get_post_meta( $location_post_id, WPD_CPT_Location::META_DANSAL_ID, true ) : 0;

		return array(
			'title'              => get_the_title( $post_id ),
			'description'        => $post ? $post->post_content : '',
			'organization_id'    => $this->settings->get_org_id(),
			'default_location_id' => $location_dansal ? $location_dansal : null,
			'default_start_time' => (string) get_post_meta( $post_id, '_wpd_series_start_time_of_day', true ),
			'default_end_time'   => (string) get_post_meta( $post_id, '_wpd_series_end_time_of_day', true ),
		);
	}

	private function sync_to_dansal( $post_id ) {
		$payload   = $this->build_payload( $post_id );
		$dansal_id = (int) get_post_meta( $post_id, self::META_DANSAL_ID, true );

		if ( $dansal_id ) {
			$result = $this->api->put( "/api/v1/series/{$dansal_id}", $payload );
			if ( is_wp_error( $result ) ) {
				$this->store_notice( sprintf( __( 'Failed to update dansal series #%1$d: %2$s', 'wp-dansal' ), $dansal_id, $result->get_error_message() ), 'error' );
			} else {
				update_post_meta( $post_id, self::META_LAST_SYNCED_AT, time() );
			}
			return;
		}

		$result = $this->api->post( '/api/v1/series', $payload );
		if ( is_wp_error( $result ) ) {
			$this->store_notice( sprintf( __( 'Failed to create dansal series: %s', 'wp-dansal' ), $result->get_error_message() ), 'error' );
			return;
		}
		$new_id = isset( $result['id'] ) ? (int) $result['id'] : 0;
		if ( $new_id ) {
			update_post_meta( $post_id, self::META_DANSAL_ID, $new_id );
			update_post_meta( $post_id, self::META_LAST_SYNCED_AT, time() );
			$this->store_notice( sprintf( __( 'Created dansal series #%d.', 'wp-dansal' ), $new_id ), 'success' );
		}
	}

	public function maybe_pull_sync() {
		global $typenow;
		if ( self::POST_TYPE !== $typenow || ! $this->settings->is_configured() ) {
			return;
		}
		if ( get_transient( 'wpd_series_pull_lock' ) ) {
			return;
		}
		set_transient( 'wpd_series_pull_lock', 1, 30 );

		$result = $this->api->get( '/api/v1/series', array( 'org_id' => $this->settings->get_org_id() ) );
		if ( is_wp_error( $result ) || ! is_array( $result ) ) {
			return;
		}
		foreach ( $result as $series ) {
			if ( ! is_array( $series ) || empty( $series['id'] ) ) {
				continue;
			}
			$this->pull_one_series( $series );
		}
	}

	private function pull_one_series( array $series ) {
		$dansal_id = (int) $series['id'];
		$post_id   = self::find_post_id_by_dansal_id( $dansal_id );

		$title       = isset( $series['title'] ) ? $series['title'] : '';
		$description = isset( $series['description'] ) ? $series['description'] : '';

		remove_action( 'save_post_' . self::POST_TYPE, array( $this, 'save' ) );
		if ( $post_id ) {
			$existing = get_post( $post_id );
			if ( $existing && ( $existing->post_title !== $title || $existing->post_content !== $description ) ) {
				wp_update_post(
					array(
						'ID'           => $post_id,
						'post_title'   => $title,
						'post_content' => $description,
					)
				);
			}
		} else {
			$post_id = wp_insert_post(
				array(
					'post_type'    => self::POST_TYPE,
					'post_status'  => 'publish',
					'post_title'   => $title,
					'post_content' => $description,
				),
				true
			);
		}
		add_action( 'save_post_' . self::POST_TYPE, array( $this, 'save' ) );

		if ( is_wp_error( $post_id ) || ! $post_id ) {
			return;
		}

		$location_dansal_id = isset( $series['default_location_id'] ) ? (int) $series['default_location_id'] : 0;
		$location_post_id   = $location_dansal_id ? WPD_CPT_Location::find_post_id_by_dansal_id( $location_dansal_id ) : 0;
		update_post_meta( $post_id, '_wpd_location_post_id', $location_post_id ? $location_post_id : '' );
		update_post_meta( $post_id, '_wpd_series_start_time_of_day', isset( $series['default_start_time'] ) ? $series['default_start_time'] : '' );
		update_post_meta( $post_id, '_wpd_series_end_time_of_day', isset( $series['default_end_time'] ) ? $series['default_end_time'] : '' );
		update_post_meta( $post_id, self::META_DANSAL_ID, $dansal_id );
		update_post_meta( $post_id, self::META_LAST_SYNCED_AT, time() );
	}

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
	 * Preset list callback for WPD_Preset_Menu / WPD_Preset_Buttons. Keyed
	 * on the WP post ID because a locally-created series may not have been
	 * pushed to dansal yet — the handler resolves either shape.
	 */
	public function get_series_presets() {
		$out   = array();
		$posts = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		foreach ( $posts as $p ) {
			$out[] = array(
				'slug' => (string) $p->ID,
				'name' => $p->post_title,
			);
		}
		return $out;
	}

	/**
	 * Creates a draft dansal_event linked to this series. When called from
	 * the series edit screen with a specific date, both start/end are
	 * pre-filled by combining the date with the series' time-of-day. When
	 * called from a sidebar/list-screen preset link with no date, both are
	 * left empty and _wpd_series_post_id drives the metabox hint (same
	 * mechanism as templates).
	 */
	public function handle_add_date() {
		$series_id = isset( $_GET['series'] ) ? absint( $_GET['series'] ) : 0;
		$series    = $series_id ? get_post( $series_id ) : null;
		if ( ! $series || self::POST_TYPE !== $series->post_type ) {
			wp_die( esc_html__( 'Unknown series.', 'wp-dansal' ), '', array( 'response' => 404 ) );
		}
		if ( ! current_user_can( 'edit_post', $series_id ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'wp-dansal' ), '', array( 'response' => 403 ) );
		}

		$series_overlay = array();
		foreach ( WPD_Event_Fields::overlay_keys() as $key ) {
			$series_overlay[ $key ] = get_post_meta( $series_id, $key, true );
		}

		$meta = WPD_Event_Prefill::resolve(
			$this->settings->get_event_defaults(),
			$series_overlay
		);

		// If a specific date came from the series edit screen, pre-fill
		// concrete datetimes. Otherwise leave them empty; the metabox will
		// render the hint and the JS auto-fill on date-pick.
		$date       = isset( $_GET['date'] ) ? sanitize_text_field( wp_unslash( $_GET['date'] ) ) : '';
		$start_time = get_post_meta( $series_id, '_wpd_series_start_time_of_day', true );
		$end_time   = get_post_meta( $series_id, '_wpd_series_end_time_of_day', true );
		if ( $date && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			if ( $start_time ) {
				$meta['_wpd_start_time'] = $date . 'T' . $start_time;
			}
			if ( $end_time ) {
				$meta['_wpd_end_time'] = $date . 'T' . $end_time;
			}
		}

		$new_id = WPD_Event_Draft::create(
			$meta,
			array(
				'post_title'   => $series->post_title,
				'post_content' => $series->post_content,
			)
		);
		if ( ! $new_id ) {
			wp_die( esc_html__( 'Failed to create event from series.', 'wp-dansal' ), '', array( 'response' => 500 ) );
		}

		update_post_meta( $new_id, '_wpd_series_post_id', $series_id );

		wp_safe_redirect( get_edit_post_link( $new_id, '' ) );
		exit;
	}

	private function store_notice( $message, $type ) {
		$key       = 'wpd_admin_notices_' . get_current_user_id();
		$notices   = get_transient( $key );
		$notices   = is_array( $notices ) ? $notices : array();
		$notices[] = array(
			'message' => $message,
			'type'    => $type,
		);
		set_transient( $key, $notices, MINUTE_IN_SECONDS * 5 );
	}
}
