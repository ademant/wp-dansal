<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Canonical registry of dansal_event post meta keys.
 *
 * Every feature that needs "which fields do I touch?" reads from here so the
 * event metabox, org-wide defaults (#10), templates (#11), series overlay
 * (#12) and clone (#9) can't drift apart on which fields are shared vs.
 * per-occurrence vs. system-managed.
 *
 * The overlay subset also gets a shared renderer (render_field_group) and a
 * shared sanitizer (sanitize_field_group). Both operate on values in their
 * *stored* form (get_post_meta shape), so callers hand values straight in
 * and take the return value straight to update_post_meta.
 */
class WPD_Event_Fields {

	/**
	 * Meta keys that carry across events and are defaultable / templatable /
	 * carried by a series. Everything the shared render_field_group() knows
	 * how to draw.
	 */
	public static function overlay_keys() {
		return array(
			'_wpd_location_post_id',
			'_wpd_tags',
			'_wpd_dance_ids',
			'_wpd_has_ball',
			'_wpd_has_workshop',
			'_wpd_has_festival',
			'_wpd_workshop_difficulty',
			'_wpd_booking_url',
			'_wpd_pricing_type',
			'_wpd_pricing_amount',
			'_wpd_pricing_currency',
			'_wpd_food',
			'_wpd_drink',
			'_wpd_floor_condition',
			'_wpd_attr_wheelchair',
			'_wpd_attr_bar',
			'_wpd_attr_kitchen',
			'_wpd_contact_name',
			'_wpd_contact_email',
		);
	}

	/**
	 * Meta keys that are meaningful only for one occurrence — never copied
	 * from a template, series, clone source, or org-wide default.
	 */
	public static function per_occurrence_keys() {
		return array(
			'_wpd_start_time',
			'_wpd_end_time',
			'_wpd_is_cancelled',
		);
	}

	/**
	 * Meta keys the plugin manages itself (dansal linkage, sync bookkeeping,
	 * AJAX-picker payloads). Not user-editable via the shared field group.
	 */
	public static function system_managed_keys() {
		return array(
			'_wpd_dansal_id',
			'_wpd_last_synced_at',
			'_wpd_musician_ids',
			'_wpd_musician_names',
			'_wpd_instructor_ids',
			'_wpd_instructor_names',
			'_wpd_search_entity',
			// Prefill-source marker stashed on drafts created via a series
			// so the metabox can render the time-of-day hint.
			'_wpd_series_post_id',
		);
	}

	/** @var WPD_Api_Client */
	private $api;

	public function __construct( WPD_Api_Client $api ) {
		$this->api = $api;
	}

	/**
	 * Renders the overlay-field controls as <tr> rows inside a form-table.
	 * Caller is responsible for the surrounding <table class="form-table">.
	 *
	 * @param array  $values      meta_key => stored value (get_post_meta shape).
	 * @param string $name_prefix HTML input name prefix, e.g. "wpd_event" →
	 *                            inputs are named wpd_event[<meta_key>].
	 */
	public function render_field_group( array $values, $name_prefix ) {
		$v = function ( $key, $fallback = '' ) use ( $values ) {
			return isset( $values[ $key ] ) && '' !== $values[ $key ] ? $values[ $key ] : $fallback;
		};
		$name = function ( $key, $multi = false ) use ( $name_prefix ) {
			return $name_prefix . '[' . $key . ']' . ( $multi ? '[]' : '' );
		};

		$location_posts = $this->get_location_posts();
		$tags_by_cat    = array();
		foreach ( $this->get_tags_vocabulary() as $tag ) {
			$tags_by_cat[ $tag['category'] ][] = $tag;
		}
		$selected_tags   = array_filter( explode( ',', (string) $v( '_wpd_tags' ) ) );
		$selected_dances = array_filter( explode( ',', (string) $v( '_wpd_dance_ids' ) ) );
		?>
		<tr>
			<th><label><?php esc_html_e( 'Location', 'wp-dansal' ); ?></label></th>
			<td>
				<select name="<?php echo esc_attr( $name( '_wpd_location_post_id' ) ); ?>">
					<option value=""><?php esc_html_e( '— select a synced location —', 'wp-dansal' ); ?></option>
					<?php foreach ( $location_posts as $loc ) : ?>
						<option value="<?php echo esc_attr( $loc->ID ); ?>" <?php selected( $v( '_wpd_location_post_id' ), $loc->ID ); ?>><?php echo esc_html( $loc->post_title ); ?></option>
					<?php endforeach; ?>
				</select>
				<p class="description"><?php esc_html_e( 'Only locations already synced to dansal (see Dance Locations) can be attached to an event.', 'wp-dansal' ); ?></p>
			</td>
		</tr>
		<tr>
			<th><?php esc_html_e( 'Tags', 'wp-dansal' ); ?></th>
			<td>
				<?php foreach ( $tags_by_cat as $category => $tags ) : ?>
					<p><strong><?php echo esc_html( ucfirst( $category ) ); ?>:</strong>
					<?php foreach ( $tags as $tag ) : ?>
						<label style="margin-right:1em;display:inline-block;">
							<input type="checkbox" name="<?php echo esc_attr( $name( '_wpd_tags', true ) ); ?>" value="<?php echo esc_attr( $tag['slug'] ); ?>" <?php checked( in_array( $tag['slug'], $selected_tags, true ) ); ?> />
							<?php echo esc_html( $tag['name'] ); ?>
						</label>
					<?php endforeach; ?>
					</p>
				<?php endforeach; ?>
			</td>
		</tr>
		<tr>
			<th><label><?php esc_html_e( 'Dances', 'wp-dansal' ); ?></label></th>
			<td>
				<select name="<?php echo esc_attr( $name( '_wpd_dance_ids', true ) ); ?>" multiple size="6" style="min-width:260px;">
					<?php foreach ( $this->get_dances_vocabulary() as $dance ) : ?>
						<option value="<?php echo esc_attr( $dance['id'] ); ?>" <?php selected( in_array( (string) $dance['id'], $selected_dances, true ) ); ?>><?php echo esc_html( $dance['name'] ); ?></option>
					<?php endforeach; ?>
				</select>
			</td>
		</tr>
		<tr>
			<th><?php esc_html_e( 'Event type', 'wp-dansal' ); ?></th>
			<td>
				<?php
				foreach ( array(
					'_wpd_has_ball'     => __( 'Ball', 'wp-dansal' ),
					'_wpd_has_workshop' => __( 'Workshop', 'wp-dansal' ),
					'_wpd_has_festival' => __( 'Festival', 'wp-dansal' ),
				) as $key => $label ) :
					?>
					<label style="margin-right:1em;display:inline-block;">
						<input type="checkbox" name="<?php echo esc_attr( $name( $key ) ); ?>" value="1" <?php checked( $v( $key ), '1' ); ?> />
						<?php echo esc_html( $label ); ?>
					</label>
				<?php endforeach; ?>
			</td>
		</tr>
		<tr>
			<th><label><?php esc_html_e( 'Workshop difficulty', 'wp-dansal' ); ?></label></th>
			<td>
				<input type="text" name="<?php echo esc_attr( $name( '_wpd_workshop_difficulty' ) ); ?>" list="wpd-difficulties" value="<?php echo esc_attr( $v( '_wpd_workshop_difficulty' ) ); ?>" />
				<datalist id="wpd-difficulties">
					<option value="beginner"></option>
					<option value="intermediate"></option>
					<option value="advanced"></option>
					<option value="open"></option>
				</datalist>
			</td>
		</tr>
		<tr>
			<th><label><?php esc_html_e( 'Booking URL', 'wp-dansal' ); ?></label></th>
			<td><input type="url" name="<?php echo esc_attr( $name( '_wpd_booking_url' ) ); ?>" class="regular-text" value="<?php echo esc_attr( $v( '_wpd_booking_url' ) ); ?>" /></td>
		</tr>
		<tr>
			<th><?php esc_html_e( 'Pricing', 'wp-dansal' ); ?></th>
			<td>
				<select name="<?php echo esc_attr( $name( '_wpd_pricing_type' ) ); ?>">
					<?php
					foreach ( array(
						'free'     => __( 'Free', 'wp-dansal' ),
						'fixed'    => __( 'Fixed price', 'wp-dansal' ),
						'donation' => __( 'Donation', 'wp-dansal' ),
					) as $key => $label ) :
						?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $v( '_wpd_pricing_type', 'free' ), $key ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<input type="number" step="0.01" min="0" name="<?php echo esc_attr( $name( '_wpd_pricing_amount' ) ); ?>" placeholder="<?php esc_attr_e( 'Amount', 'wp-dansal' ); ?>" value="<?php echo esc_attr( $v( '_wpd_pricing_amount' ) ); ?>" class="small-text" />
				<input type="text" name="<?php echo esc_attr( $name( '_wpd_pricing_currency' ) ); ?>" placeholder="EUR" maxlength="3" value="<?php echo esc_attr( $v( '_wpd_pricing_currency', 'EUR' ) ); ?>" class="small-text" />
			</td>
		</tr>
		<tr>
			<th><?php esc_html_e( 'Food & drink', 'wp-dansal' ); ?></th>
			<td>
				<input type="text" name="<?php echo esc_attr( $name( '_wpd_food' ) ); ?>" placeholder="<?php esc_attr_e( 'Food', 'wp-dansal' ); ?>" value="<?php echo esc_attr( $v( '_wpd_food' ) ); ?>" class="regular-text" />
				<input type="text" name="<?php echo esc_attr( $name( '_wpd_drink' ) ); ?>" placeholder="<?php esc_attr_e( 'Drink', 'wp-dansal' ); ?>" value="<?php echo esc_attr( $v( '_wpd_drink' ) ); ?>" class="regular-text" />
			</td>
		</tr>
		<tr>
			<th><label><?php esc_html_e( 'Floor condition override', 'wp-dansal' ); ?></label></th>
			<td>
				<input type="text" name="<?php echo esc_attr( $name( '_wpd_floor_condition' ) ); ?>" list="wpd-floor-conditions" value="<?php echo esc_attr( $v( '_wpd_floor_condition' ) ); ?>" />
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
					'_wpd_attr_wheelchair' => __( 'Wheelchair accessible', 'wp-dansal' ),
					'_wpd_attr_bar'        => __( 'Bar', 'wp-dansal' ),
					'_wpd_attr_kitchen'    => __( 'Kitchen', 'wp-dansal' ),
				) as $key => $label ) :
					?>
					<label style="margin-right:1em;display:inline-block;">
						<input type="checkbox" name="<?php echo esc_attr( $name( $key ) ); ?>" value="1" <?php checked( $v( $key ), '1' ); ?> />
						<?php echo esc_html( $label ); ?>
					</label>
				<?php endforeach; ?>
			</td>
		</tr>
		<tr>
			<th><?php esc_html_e( 'Contact', 'wp-dansal' ); ?></th>
			<td>
				<input type="text" name="<?php echo esc_attr( $name( '_wpd_contact_name' ) ); ?>" placeholder="<?php esc_attr_e( 'Name', 'wp-dansal' ); ?>" value="<?php echo esc_attr( $v( '_wpd_contact_name' ) ); ?>" class="regular-text" />
				<input type="email" name="<?php echo esc_attr( $name( '_wpd_contact_email' ) ); ?>" placeholder="<?php esc_attr_e( 'Email', 'wp-dansal' ); ?>" value="<?php echo esc_attr( $v( '_wpd_contact_email' ) ); ?>" class="regular-text" />
			</td>
		</tr>
		<?php
	}

	/**
	 * Normalises a raw $_POST-shape overlay-field array into stored form,
	 * ready to feed straight into update_post_meta (or an option). Keys
	 * absent from $input are returned as empty string — i.e. explicitly
	 * "cleared" — so caller can unconditionally write every overlay key.
	 *
	 * @param array $input Raw group input, e.g. $_POST['wpd_event'] or
	 *                     $_POST['wpd_settings']['defaults'].
	 * @return array meta_key => normalised value.
	 */
	public static function sanitize_field_group( array $input ) {
		$out = array();

		$text_keys = array(
			'_wpd_location_post_id',
			'_wpd_workshop_difficulty',
			'_wpd_booking_url',
			'_wpd_pricing_type',
			'_wpd_pricing_amount',
			'_wpd_pricing_currency',
			'_wpd_food',
			'_wpd_drink',
			'_wpd_floor_condition',
			'_wpd_contact_name',
			'_wpd_contact_email',
		);
		foreach ( $text_keys as $key ) {
			$raw           = isset( $input[ $key ] ) ? wp_unslash( $input[ $key ] ) : '';
			$out[ $key ]   = sanitize_text_field( is_array( $raw ) ? '' : $raw );
		}

		$flag_keys = array(
			'_wpd_has_ball',
			'_wpd_has_workshop',
			'_wpd_has_festival',
			'_wpd_attr_wheelchair',
			'_wpd_attr_bar',
			'_wpd_attr_kitchen',
		);
		foreach ( $flag_keys as $key ) {
			$out[ $key ] = ! empty( $input[ $key ] ) ? '1' : '';
		}

		$tags        = isset( $input['_wpd_tags'] ) ? array_map( 'sanitize_key', (array) $input['_wpd_tags'] ) : array();
		$tags        = array_values( array_filter( $tags ) );
		// Padded with boundary commas so frontend meta_query LIKE lookups
		// match whole slugs — same encoding used elsewhere in the plugin.
		$out['_wpd_tags'] = $tags ? ',' . implode( ',', $tags ) . ',' : '';

		$dances               = isset( $input['_wpd_dance_ids'] ) ? array_map( 'absint', (array) $input['_wpd_dance_ids'] ) : array();
		$dances               = array_values( array_filter( $dances ) );
		$out['_wpd_dance_ids'] = implode( ',', $dances );

		return $out;
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
		$cached = get_transient( 'wpd_tags_vocab_' . WPD_VERSION );
		if ( false !== $cached ) {
			return $cached;
		}
		$tags = $this->api->get_public( '/api/v1/tags' );
		if ( is_wp_error( $tags ) || ! is_array( $tags ) ) {
			return array();
		}
		set_transient( 'wpd_tags_vocab_' . WPD_VERSION, $tags, HOUR_IN_SECONDS );
		return $tags;
	}

	private function get_dances_vocabulary() {
		$cached = get_transient( 'wpd_dances_vocab_' . WPD_VERSION );
		if ( false !== $cached ) {
			return $cached;
		}
		$dances = $this->api->get_public( '/api/v1/dances' );
		if ( is_wp_error( $dances ) || ! is_array( $dances ) ) {
			return array();
		}
		set_transient( 'wpd_dances_vocab_' . WPD_VERSION, $dances, HOUR_IN_SECONDS );
		return $dances;
	}
}
