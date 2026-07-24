<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared base for the two "person"-shaped dansal CPTs, dansal_musician and
 * dansal_instructor. Both flow the same way:
 *
 *   pull:  overwrite the WP post silently (dansal wins — these are shared
 *          entities other communities may edit, drift is intended)
 *   push:  PATCH on save, merge-patch so dansal-side fields the plugin
 *          doesn't surface (biography, wikidata_id, discogs_id, website,
 *          email, images) survive.
 *
 * Subclasses provide the field map (WP meta key => dansal payload key), the
 * REST resource path, the primary display field name (bandname vs name), and
 * the CPT registration labels. Everything else — meta box render, save,
 * sync, pull tick, refresh-single — lives here.
 */
abstract class WPD_CPT_Person {

	const META_DANSAL_ID      = '_wpd_dansal_id';
	const META_LAST_SYNCED_AT = '_wpd_last_synced_at';
	// Marks a post created by the bulk stub-import (#103) that only carries
	// a name + dansal ID, not the rest of field_map() -- cleared as soon as
	// the post gets real field data, via write_post() or a manual save().
	const META_STUB           = '_wpd_stub';

	/** @var WPD_Api_Client */
	protected $api;
	/** @var WPD_Settings */
	protected $settings;

	public function __construct( WPD_Api_Client $api, WPD_Settings $settings ) {
		$this->api      = $api;
		$this->settings = $settings;

		add_action( 'init', array( $this, 'register_post_type' ) );
		add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ) );
		add_action( 'save_post_' . static::POST_TYPE, array( $this, 'save' ) );
		add_filter( 'manage_' . static::POST_TYPE . '_posts_columns', array( $this, 'columns' ) );
		add_action( 'manage_' . static::POST_TYPE . '_posts_custom_column', array( $this, 'render_column' ), 10, 2 );
		add_action( 'load-edit.php', array( $this, 'maybe_pull_sync' ) );
		add_filter( 'post_row_actions', array( $this, 'row_actions' ), 10, 2 );
		WPD_Admin_Action::register( 'wpd_promote_' . static::POST_TYPE, 'edit_posts', array( $this, 'handle_promote' ) );
	}

	abstract public function register_post_type();

	/**
	 * WP meta key => dansal payload key mapping for the four (musicians) or
	 * two (instructors) fields the plugin surfaces on the edit screen.
	 * Extra fields dansal stores are ignored on push; pull leaves them
	 * untouched dansal-side.
	 *
	 * @return array<string,string>
	 */
	abstract protected function field_map();

	/** Dansal's own primary label field — "bandname" for musicians, "name" for instructors. */
	abstract protected function primary_field();

	/** e.g. '/api/v1/musicians' */
	abstract protected function resource_path();

	/** Rendered on the edit screen next to each meta key. */
	abstract protected function field_labels();

	public function columns( $columns ) {
		$columns['wpd_dansal_id'] = __( 'Dansal ID', 'wp-dansal' );
		$columns['wpd_synced']    = __( 'Synced', 'wp-dansal' );
		return $columns;
	}

	public function render_column( $column, $post_id ) {
		if ( 'wpd_dansal_id' === $column ) {
			$id = get_post_meta( $post_id, self::META_DANSAL_ID, true );
			echo $id ? esc_html( $id ) : esc_html__( 'not synced', 'wp-dansal' );
		} elseif ( 'wpd_synced' === $column ) {
			if ( ! get_post_meta( $post_id, self::META_DANSAL_ID, true ) ) {
				echo '&#8212;';
			} elseif ( get_post_meta( $post_id, self::META_STUB, true ) ) {
				esc_html_e( 'stub', 'wp-dansal' );
			} else {
				esc_html_e( 'full', 'wp-dansal' );
			}
		}
	}

	/**
	 * "Promote" row action for a stub post (#103): re-fetches the full
	 * entity from dansal and fills in the rest of field_map(), the same way
	 * the event picker's pencil-icon promote already does via
	 * upsert_from_dansal() -- just triggered from the list table instead of
	 * requiring the entity to be attached to an event first.
	 */
	public function row_actions( $actions, $post ) {
		if ( static::POST_TYPE !== $post->post_type || ! current_user_can( 'edit_post', $post->ID ) ) {
			return $actions;
		}
		if ( ! get_post_meta( $post->ID, self::META_STUB, true ) ) {
			return $actions;
		}
		$url               = WPD_Admin_Action::url( 'wpd_promote_' . static::POST_TYPE, array( 'post' => $post->ID ) );
		$actions['wpd_promote'] = sprintf( '<a href="%s">%s</a>', esc_url( $url ), esc_html__( 'Fetch full details', 'wp-dansal' ) );
		return $actions;
	}

	public function handle_promote() {
		$post_id = isset( $_REQUEST['post'] ) ? absint( $_REQUEST['post'] ) : 0;
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'wp-dansal' ), 403 );
		}
		$dansal_id = (int) get_post_meta( $post_id, self::META_DANSAL_ID, true );
		if ( $dansal_id ) {
			$entity = $this->api->get_public( $this->resource_path() . '/' . $dansal_id );
			if ( ! is_wp_error( $entity ) && is_array( $entity ) && ! empty( $entity['id'] ) ) {
				$this->upsert_from_dansal( $entity );
			}
		}
		wp_safe_redirect( admin_url( 'edit.php?post_type=' . static::POST_TYPE ) );
		exit;
	}

	public function add_meta_boxes() {
		add_meta_box( 'wpd_person_details', __( 'Dansal details', 'wp-dansal' ), array( $this, 'render_meta_box' ), static::POST_TYPE, 'normal', 'high' );
	}

	public function render_meta_box( $post ) {
		wp_nonce_field( 'wpd_person_save', 'wpd_person_nonce' );
		$labels = $this->field_labels();
		?>
		<table class="form-table">
			<?php foreach ( $this->field_map() as $meta_key => $_dansal_key ) : ?>
				<?php $label = isset( $labels[ $meta_key ] ) ? $labels[ $meta_key ] : $meta_key; ?>
				<?php $value = get_post_meta( $post->ID, $meta_key, true ); ?>
				<tr>
					<th><label for="<?php echo esc_attr( $meta_key ); ?>"><?php echo esc_html( $label ); ?></label></th>
					<td>
						<?php if ( '_wpd_description' === $meta_key ) : ?>
							<textarea id="<?php echo esc_attr( $meta_key ); ?>" name="<?php echo esc_attr( $meta_key ); ?>" rows="4" class="large-text"><?php echo esc_textarea( $value ); ?></textarea>
						<?php else : ?>
							<input type="text" id="<?php echo esc_attr( $meta_key ); ?>" name="<?php echo esc_attr( $meta_key ); ?>" class="regular-text" value="<?php echo esc_attr( $value ); ?>" />
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
		</table>
		<?php
	}

	public function save( $post_id ) {
		$nonce = isset( $_POST['wpd_person_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['wpd_person_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'wpd_person_save' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		foreach ( $this->field_map() as $meta_key => $_dansal_key ) {
			$raw = isset( $_POST[ $meta_key ] ) ? wp_unslash( $_POST[ $meta_key ] ) : '';
			if ( '_wpd_description' === $meta_key ) {
				update_post_meta( $post_id, $meta_key, wp_kses_post( is_array( $raw ) ? '' : $raw ) );
			} else {
				update_post_meta( $post_id, $meta_key, sanitize_text_field( is_array( $raw ) ? '' : $raw ) );
			}
		}
		// A manual edit means this post has real local content now, whether
		// or not it started life as a bulk-imported stub (#103).
		delete_post_meta( $post_id, self::META_STUB );

		if ( ! $this->settings->is_configured() ) {
			return;
		}
		$this->sync_to_dansal( $post_id );
	}

	protected function build_payload( $post_id ) {
		$payload = array( $this->primary_field() => get_the_title( $post_id ) );
		foreach ( $this->field_map() as $meta_key => $dansal_key ) {
			$payload[ $dansal_key ] = (string) get_post_meta( $post_id, $meta_key, true );
		}
		return $payload;
	}

	protected function sync_to_dansal( $post_id ) {
		$payload   = $this->build_payload( $post_id );
		$dansal_id = (int) get_post_meta( $post_id, self::META_DANSAL_ID, true );

		if ( $dansal_id ) {
			$result = $this->api->patch( $this->resource_path() . '/' . $dansal_id, $payload );
			if ( ! is_wp_error( $result ) ) {
				update_post_meta( $post_id, self::META_LAST_SYNCED_AT, time() );
			}
			return;
		}

		$result = $this->api->post( $this->resource_path(), $payload );
		if ( is_wp_error( $result ) || empty( $result['id'] ) ) {
			return;
		}
		update_post_meta( $post_id, self::META_DANSAL_ID, (int) $result['id'] );
		update_post_meta( $post_id, self::META_LAST_SYNCED_AT, time() );
	}

	/**
	 * @return int WordPress post ID linked to this dansal entity, or 0.
	 */
	public static function find_post_id_by_dansal_id( $dansal_id ) {
		$posts = get_posts(
			array(
				'post_type'      => static::POST_TYPE,
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
	 * Creates or refreshes the WP post from a dansal payload. Returns the
	 * (possibly new) post ID, or 0 on failure.
	 */
	public function upsert_from_dansal( array $entity ) {
		if ( empty( $entity['id'] ) ) {
			return 0;
		}
		$dansal_id = (int) $entity['id'];
		$post_id   = static::find_post_id_by_dansal_id( $dansal_id );

		$primary = $this->primary_field();
		$title   = isset( $entity[ $primary ] ) ? (string) $entity[ $primary ] : '';

		if ( ! $post_id ) {
			remove_action( 'save_post_' . static::POST_TYPE, array( $this, 'save' ) );
			$post_id = wp_insert_post(
				array(
					'post_type'   => static::POST_TYPE,
					'post_status' => 'publish',
					'post_title'  => $title,
				),
				true
			);
			add_action( 'save_post_' . static::POST_TYPE, array( $this, 'save' ) );

			if ( is_wp_error( $post_id ) || ! $post_id ) {
				return 0;
			}
			update_post_meta( $post_id, self::META_DANSAL_ID, $dansal_id );
		}

		$this->write_post( $post_id, $entity );
		return (int) $post_id;
	}

	protected function write_post( $post_id, array $entity ) {
		$primary = $this->primary_field();
		if ( isset( $entity[ $primary ] ) && get_the_title( $post_id ) !== $entity[ $primary ] ) {
			remove_action( 'save_post_' . static::POST_TYPE, array( $this, 'save' ) );
			wp_update_post(
				array(
					'ID'         => $post_id,
					'post_title' => (string) $entity[ $primary ],
				)
			);
			add_action( 'save_post_' . static::POST_TYPE, array( $this, 'save' ) );
		}

		foreach ( $this->field_map() as $meta_key => $dansal_key ) {
			$value = isset( $entity[ $dansal_key ] ) ? (string) $entity[ $dansal_key ] : '';
			if ( '_wpd_description' === $meta_key ) {
				$value = wp_kses_post( $value );
			}
			update_post_meta( $post_id, $meta_key, $value );
		}
		update_post_meta( $post_id, self::META_LAST_SYNCED_AT, time() );
		// Real field data now exists locally -- this is no longer a bare
		// stub, regardless of which caller (promote, refresh-already-linked,
		// bulk pull) got us here.
		delete_post_meta( $post_id, self::META_STUB );
	}

	public function maybe_pull_sync() {
		global $typenow;
		if ( static::POST_TYPE !== $typenow || ! $this->settings->is_configured() ) {
			return;
		}
		$lock_key = 'wpd_' . str_replace( 'dansal_', '', static::POST_TYPE ) . '_pull_lock';
		if ( get_transient( $lock_key ) ) {
			return;
		}
		set_transient( $lock_key, 1, 30 );

		// Only refresh entities the WP side already promoted to a full local
		// post — never re-fetch full details for a bare stub just because it
		// exists (that would silently convert every stub into a full post on
		// the next list-screen visit, defeating bulk_import_stubs() below).
		// The event picker attaches dansal-side entities by ID without
		// needing a WP post at all.
		$post_ids = get_posts(
			array(
				'post_type'      => static::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'meta_key'       => self::META_DANSAL_ID,
				'fields'         => 'ids',
			)
		);
		foreach ( $post_ids as $post_id ) {
			if ( get_post_meta( $post_id, self::META_STUB, true ) ) {
				continue;
			}
			$dansal_id = (int) get_post_meta( $post_id, self::META_DANSAL_ID, true );
			if ( ! $dansal_id ) {
				continue;
			}
			$entity = $this->api->get_public( $this->resource_path() . '/' . $dansal_id );
			if ( is_wp_error( $entity ) || ! is_array( $entity ) ) {
				continue;
			}
			$updated_at  = isset( $entity['updated_at'] ) ? (int) $entity['updated_at'] : 0;
			$last_synced = (int) get_post_meta( $post_id, self::META_LAST_SYNCED_AT, true );
			if ( $updated_at > 0 && $updated_at <= $last_synced ) {
				continue;
			}
			$this->write_post( $post_id, $entity );
		}

		$this->bulk_import_stubs();
	}

	/**
	 * Lightweight bulk import (#103): fetches the whole dansal catalogue for
	 * this resource and creates a bare stub post -- name + dansal ID only,
	 * no other field_map() data -- for any entity not yet linked to a local
	 * post. Existing posts (stub or full) are left untouched here; only gaps
	 * are filled. Reuses the same 30s pull lock as the refresh loop above, so
	 * it only runs once per list-screen visit at most.
	 */
	private function bulk_import_stubs() {
		$result = $this->api->get_all_pages( $this->resource_path() );
		if ( is_wp_error( $result ) ) {
			return;
		}
		$entities = is_array( $result ) ? $result : array();
		foreach ( $entities as $entity ) {
			if ( ! is_array( $entity ) || empty( $entity['id'] ) ) {
				continue;
			}
			if ( static::find_post_id_by_dansal_id( (int) $entity['id'] ) ) {
				continue;
			}
			$this->create_stub_from_dansal( $entity );
		}
	}

	/**
	 * Creates a bare stub post for a dansal entity: title + dansal ID only.
	 * The rest of field_map() (biography, MBID, country, website, etc.)
	 * stays empty until an admin explicitly promotes it (row action or the
	 * event picker's pencil icon), both of which go through write_post().
	 *
	 * @return int The new post ID, or 0 on failure.
	 */
	private function create_stub_from_dansal( array $entity ) {
		$primary = $this->primary_field();
		$title   = isset( $entity[ $primary ] ) ? (string) $entity[ $primary ] : '';

		remove_action( 'save_post_' . static::POST_TYPE, array( $this, 'save' ) );
		$post_id = wp_insert_post(
			array(
				'post_type'   => static::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => $title,
			),
			true
		);
		add_action( 'save_post_' . static::POST_TYPE, array( $this, 'save' ) );

		if ( is_wp_error( $post_id ) || ! $post_id ) {
			return 0;
		}
		update_post_meta( $post_id, self::META_DANSAL_ID, (int) $entity['id'] );
		update_post_meta( $post_id, self::META_STUB, 1 );
		return (int) $post_id;
	}
}
