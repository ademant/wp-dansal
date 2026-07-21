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
			'_wpd_room_id',
			'_wpd_tags',
			'_wpd_dance_ids',
			'_wpd_has_ball',
			'_wpd_has_workshop',
			'_wpd_has_festival',
			'_wpd_booking_url',
			'_wpd_pricing_type',
			'_wpd_pricing_amount',
			'_wpd_pricing_currency',
			// Array of {label, amount} maps, used only when pricing_type is
			// "multiple" — dansal's own multi-tier pricing table. WP's
			// get_post_meta()/update_post_meta() (de)serialize the array
			// value transparently, same as the existing _wpd_rooms_cache.
			'_wpd_pricing_tiers',
			// Array of timetable entry maps (start_time/end_time/title/
			// entry_type plus description/room/location_id/musician_id
			// round-tripped but not editable here yet, see #85) — dansal's
			// own /api/v1/events/{id}/timetable sub-resource.
			'_wpd_timetable',
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
	 * Builds the $v()/$name() value+input-name closures shared by every
	 * render_*_fields() method below, so each stays independently callable
	 * (e.g. from the event metabox's own <details> segments — see
	 * WPD_CPT_Event::render_meta_box()) without re-deriving this boilerplate.
	 */
	private function field_accessors( array $values, $name_prefix ) {
		$v = function ( $key, $fallback = '' ) use ( $values ) {
			return isset( $values[ $key ] ) && '' !== $values[ $key ] ? $values[ $key ] : $fallback;
		};
		$name = function ( $key, $multi = false ) use ( $name_prefix ) {
			return $name_prefix . '[' . $key . ']' . ( $multi ? '[]' : '' );
		};
		return array( $v, $name );
	}

	/**
	 * Renders all overlay-field controls as <tr> rows inside a single
	 * form-table, in the historical flat order. Used by callers that don't
	 * need the event metabox's <details> segmentation (settings page's
	 * "Event defaults", series edit screen). Caller is responsible for the
	 * surrounding <table class="form-table">.
	 *
	 * @param array  $values      meta_key => stored value (get_post_meta shape).
	 * @param string $name_prefix HTML input name prefix, e.g. "wpd_event" →
	 *                            inputs are named wpd_event[<meta_key>].
	 */
	public function render_field_group( array $values, $name_prefix ) {
		$this->render_location_room_fields( $values, $name_prefix );
		$this->render_classification_fields( $values, $name_prefix );
		$this->render_pricing_fields( $values, $name_prefix );
		$this->render_timetable_fields( $values, $name_prefix );
		$this->render_amenities_fields( $values, $name_prefix );
		$this->render_contact_fields( $values, $name_prefix );
	}

	/**
	 * Location + Room rows.
	 */
	public function render_location_room_fields( array $values, $name_prefix ) {
		list( $v, $name ) = $this->field_accessors( $values, $name_prefix );
		$location_posts    = $this->get_location_posts();
		?>
		<tr>
			<th><label><?php esc_html_e( 'Location', 'wp-dansal' ); ?></label></th>
			<td>
				<select name="<?php echo esc_attr( $name( '_wpd_location_post_id' ) ); ?>" class="wpd-location-select">
					<option value=""><?php esc_html_e( '— select a synced location —', 'wp-dansal' ); ?></option>
					<?php foreach ( $location_posts as $loc ) : ?>
						<option value="<?php echo esc_attr( $loc->ID ); ?>" <?php selected( $v( '_wpd_location_post_id' ), $loc->ID ); ?>><?php echo esc_html( $loc->post_title ); ?></option>
					<?php endforeach; ?>
				</select>
				<p class="description"><?php esc_html_e( 'Only locations already synced to dansal (see Dance Locations) can be attached to an event.', 'wp-dansal' ); ?></p>
			</td>
		</tr>
		<tr class="wpd-room-row">
			<th><label><?php esc_html_e( 'Room', 'wp-dansal' ); ?></label></th>
			<td>
				<?php
				$current_location_post = (int) $v( '_wpd_location_post_id' );
				$rooms_cache           = $current_location_post ? get_post_meta( $current_location_post, '_wpd_rooms_cache', true ) : array();
				$rooms_cache           = is_array( $rooms_cache ) ? $rooms_cache : array();
				$current_room          = (int) $v( '_wpd_room_id' );
				?>
				<select name="<?php echo esc_attr( $name( '_wpd_room_id' ) ); ?>" class="wpd-room-select">
					<option value="0"><?php esc_html_e( '— no specific room —', 'wp-dansal' ); ?></option>
					<?php foreach ( $rooms_cache as $room ) : ?>
						<option value="<?php echo esc_attr( $room['id'] ); ?>" <?php selected( $current_room, $room['id'] ); ?>><?php echo esc_html( $room['name'] ); ?></option>
					<?php endforeach; ?>
				</select>
				<p class="description"><?php esc_html_e( 'Optional — pick a specific named room within the venue. Rooms are managed on the location edit screen.', 'wp-dansal' ); ?></p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Tags + Dances + Event type rows.
	 */
	public function render_classification_fields( array $values, $name_prefix ) {
		list( $v, $name ) = $this->field_accessors( $values, $name_prefix );

		$tags_by_cat = array();
		foreach ( $this->get_tags_vocabulary() as $tag ) {
			$tags_by_cat[ $tag['category'] ][] = $tag;
		}
		$selected_tags   = array_filter( explode( ',', (string) $v( '_wpd_tags' ) ) );
		$selected_dances = array_filter( explode( ',', (string) $v( '_wpd_dance_ids' ) ) );
		?>
		<tr>
			<th><?php esc_html_e( 'Tags', 'wp-dansal' ); ?></th>
			<td>
				<?php
				// The dansal tags vocabulary's "level" category (Beginners/
				// Intermediate/Advanced) *is* the workshop difficulty picker —
				// labelled accordingly here rather than as a separate field,
				// since dansal only accepts a fixed enum for difficulty and a
				// free-text input could send it a value it would reject.
				$category_labels = array(
					'level' => __( 'Workshop difficulty', 'wp-dansal' ),
				);
				foreach ( $tags_by_cat as $category => $tags ) :
					?>
					<p><strong><?php echo esc_html( isset( $category_labels[ $category ] ) ? $category_labels[ $category ] : ucfirst( $category ) ); ?>:</strong>
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
		<?php
	}

	/**
	 * Booking URL + Pricing rows.
	 */
	public function render_pricing_fields( array $values, $name_prefix ) {
		list( $v, $name ) = $this->field_accessors( $values, $name_prefix );

		$current_type = $v( '_wpd_pricing_type', 'free' );
		$tiers        = $v( '_wpd_pricing_tiers' );
		$tiers        = is_array( $tiers ) && $tiers ? $tiers : array( array( 'label' => '', 'amount' => '' ) );
		?>
		<tr>
			<th><label><?php esc_html_e( 'Booking URL', 'wp-dansal' ); ?></label></th>
			<td><input type="url" name="<?php echo esc_attr( $name( '_wpd_booking_url' ) ); ?>" class="regular-text" value="<?php echo esc_attr( $v( '_wpd_booking_url' ) ); ?>" /></td>
		</tr>
		<tr>
			<th><?php esc_html_e( 'Pricing', 'wp-dansal' ); ?></th>
			<td>
				<?php
				// Vocabulary matches dansal's Pricing.Type exactly (API.md:
				// pricing_types = free, donation, single, multiple) — "single"
				// is one flat amount, "multiple" is the tiers table below,
				// both sharing the one currency field.
				?>
				<select name="<?php echo esc_attr( $name( '_wpd_pricing_type' ) ); ?>" class="wpd-pricing-type">
					<?php
					foreach ( array(
						'free'     => __( 'Free', 'wp-dansal' ),
						'donation' => __( 'Donation', 'wp-dansal' ),
						'single'   => __( 'Single price', 'wp-dansal' ),
						'multiple' => __( 'Multiple tiers', 'wp-dansal' ),
					) as $key => $label ) :
						?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $current_type, $key ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<input type="text" name="<?php echo esc_attr( $name( '_wpd_pricing_currency' ) ); ?>" placeholder="EUR" maxlength="3" value="<?php echo esc_attr( $v( '_wpd_pricing_currency', 'EUR' ) ); ?>" class="small-text" />

				<span class="wpd-pricing-single-fields" style="<?php echo 'single' === $current_type ? '' : 'display:none;'; ?>">
					<input type="number" step="0.01" min="0" name="<?php echo esc_attr( $name( '_wpd_pricing_amount' ) ); ?>" placeholder="<?php esc_attr_e( 'Amount', 'wp-dansal' ); ?>" value="<?php echo esc_attr( $v( '_wpd_pricing_amount' ) ); ?>" class="small-text" />
				</span>

				<div class="wpd-pricing-tiers wpd-grow-table" style="<?php echo 'multiple' === $current_type ? '' : 'display:none;'; ?>">
					<table class="wpd-pricing-tiers-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Label', 'wp-dansal' ); ?></th>
								<th><?php esc_html_e( 'Amount', 'wp-dansal' ); ?></th>
								<th></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $tiers as $tier ) : ?>
								<tr class="wpd-pricing-tier-row">
									<td><input type="text" name="<?php echo esc_attr( $name( '_wpd_pricing_tier_label', true ) ); ?>" value="<?php echo esc_attr( isset( $tier['label'] ) ? $tier['label'] : '' ); ?>" list="wpd-pricing-tier-suggestions" /></td>
									<td><input type="number" step="0.01" min="0" name="<?php echo esc_attr( $name( '_wpd_pricing_tier_amount', true ) ); ?>" value="<?php echo esc_attr( isset( $tier['amount'] ) ? $tier['amount'] : '' ); ?>" class="small-text" /></td>
									<td><button type="button" class="button-link wpd-grow-table-remove" aria-label="<?php esc_attr_e( 'Remove', 'wp-dansal' ); ?>">&times;</button></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
					<datalist id="wpd-pricing-tier-suggestions">
						<?php
						// Suggested (not enforced by dansal) tier labels — API.md
						// price_labels: normal, reduced, presale, member, supporter.
						foreach ( array( 'normal', 'reduced', 'presale', 'member', 'supporter' ) as $suggestion ) :
							?>
							<option value="<?php echo esc_attr( $suggestion ); ?>"></option>
						<?php endforeach; ?>
					</datalist>
					<button type="button" class="button wpd-grow-table-add"><?php esc_html_e( 'Add tier', 'wp-dansal' ); ?></button>
				</div>
			</td>
		</tr>
		<?php
	}

	/**
	 * Timetable row: a growable table of {start, end, title, type} slots for
	 * a multi-part event (e.g. a workshop followed by a ball). dansal's own
	 * TimetableEntry also carries description/room/location_id/musician_id
	 * (see API.md, POST|PUT /api/v1/events/{id}/timetable) which this screen
	 * doesn't expose editing yet (#85) — those are round-tripped via hidden
	 * fields per row so a WP-side save doesn't clobber richer entries set
	 * via dansal-web.
	 */
	public function render_timetable_fields( array $values, $name_prefix ) {
		list( $v, $name ) = $this->field_accessors( $values, $name_prefix );

		$entries = $v( '_wpd_timetable' );
		$entries = is_array( $entries ) && $entries ? $entries : array( array() );
		$field   = function ( $entry, $key, $fallback = '' ) {
			return isset( $entry[ $key ] ) && '' !== $entry[ $key ] ? $entry[ $key ] : $fallback;
		};
		?>
		<tr>
			<th><?php esc_html_e( 'Timetable', 'wp-dansal' ); ?></th>
			<td>
				<p class="description"><?php esc_html_e( 'Optional multi-slot schedule (e.g. a workshop followed by a ball). Leave empty for a single continuous event.', 'wp-dansal' ); ?></p>
				<div class="wpd-grow-table">
					<table class="wpd-timetable-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Start', 'wp-dansal' ); ?></th>
								<th><?php esc_html_e( 'End', 'wp-dansal' ); ?></th>
								<th><?php esc_html_e( 'Title', 'wp-dansal' ); ?></th>
								<th><?php esc_html_e( 'Type', 'wp-dansal' ); ?></th>
								<th></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $entries as $entry ) : ?>
								<tr class="wpd-timetable-row">
									<td><input type="time" name="<?php echo esc_attr( $name( '_wpd_tt_start', true ) ); ?>" value="<?php echo esc_attr( $field( $entry, 'start_time' ) ); ?>" /></td>
									<td><input type="time" name="<?php echo esc_attr( $name( '_wpd_tt_end', true ) ); ?>" value="<?php echo esc_attr( $field( $entry, 'end_time' ) ); ?>" /></td>
									<td><input type="text" name="<?php echo esc_attr( $name( '_wpd_tt_title', true ) ); ?>" value="<?php echo esc_attr( $field( $entry, 'title' ) ); ?>" class="regular-text" /></td>
									<td>
										<select name="<?php echo esc_attr( $name( '_wpd_tt_type', true ) ); ?>">
											<option value="bal" <?php selected( $field( $entry, 'entry_type', 'bal' ), 'bal' ); ?>><?php esc_html_e( 'Ball', 'wp-dansal' ); ?></option>
											<option value="workshop" <?php selected( $field( $entry, 'entry_type', 'bal' ), 'workshop' ); ?>><?php esc_html_e( 'Workshop', 'wp-dansal' ); ?></option>
										</select>
									</td>
									<td>
										<button type="button" class="button-link wpd-grow-table-remove" aria-label="<?php esc_attr_e( 'Remove', 'wp-dansal' ); ?>">&times;</button>
										<input type="hidden" name="<?php echo esc_attr( $name( '_wpd_tt_description', true ) ); ?>" value="<?php echo esc_attr( $field( $entry, 'description' ) ); ?>" />
										<input type="hidden" name="<?php echo esc_attr( $name( '_wpd_tt_room', true ) ); ?>" value="<?php echo esc_attr( $field( $entry, 'room' ) ); ?>" />
										<input type="hidden" name="<?php echo esc_attr( $name( '_wpd_tt_location_id', true ) ); ?>" value="<?php echo esc_attr( $field( $entry, 'location_id' ) ); ?>" />
										<input type="hidden" name="<?php echo esc_attr( $name( '_wpd_tt_musician_id', true ) ); ?>" value="<?php echo esc_attr( $field( $entry, 'musician_id' ) ); ?>" />
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
					<button type="button" class="button wpd-grow-table-add"><?php esc_html_e( 'Add entry', 'wp-dansal' ); ?></button>
				</div>
			</td>
		</tr>
		<?php
	}

	/**
	 * Food & drink + Floor condition override + Amenities override rows.
	 */
	public function render_amenities_fields( array $values, $name_prefix ) {
		list( $v, $name ) = $this->field_accessors( $values, $name_prefix );

		// Unlike floor_condition, dansal has no per-event "no street shoes"
		// field at all (Event struct has none — only Location does), so this
		// can only ever be shown, never overridden here.
		$location_post_id = (int) $v( '_wpd_location_post_id' );
		$venue_no_shoes    = $location_post_id ? '1' === get_post_meta( $location_post_id, '_wpd_no_street_shoes', true ) : null;
		?>
		<tr>
			<th><?php esc_html_e( 'Food & drink', 'wp-dansal' ); ?></th>
			<td>
				<label style="margin-right:1em;">
					<?php esc_html_e( 'Food', 'wp-dansal' ); ?>
					<select name="<?php echo esc_attr( $name( '_wpd_food' ) ); ?>">
						<option value=""><?php esc_html_e( '— not set —', 'wp-dansal' ); ?></option>
						<?php foreach ( WPD_Vocab::options( 'food' ) as $slug => $label ) : ?>
							<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $v( '_wpd_food' ), $slug ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label>
					<?php esc_html_e( 'Drink', 'wp-dansal' ); ?>
					<select name="<?php echo esc_attr( $name( '_wpd_drink' ) ); ?>">
						<option value=""><?php esc_html_e( '— not set —', 'wp-dansal' ); ?></option>
						<?php foreach ( WPD_Vocab::options( 'drink' ) as $slug => $label ) : ?>
							<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $v( '_wpd_drink' ), $slug ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
			</td>
		</tr>
		<tr>
			<th><label><?php esc_html_e( 'Floor condition override', 'wp-dansal' ); ?></label></th>
			<td>
				<select name="<?php echo esc_attr( $name( '_wpd_floor_condition' ) ); ?>">
					<option value=""><?php esc_html_e( '— inherit from venue —', 'wp-dansal' ); ?></option>
					<?php foreach ( WPD_Vocab::options( 'floor_condition' ) as $slug => $label ) : ?>
						<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $v( '_wpd_floor_condition' ), $slug ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<?php if ( null !== $venue_no_shoes ) : ?>
					<span class="description" style="margin-left:1em;">
						<?php
						echo esc_html(
							$venue_no_shoes
								? __( 'No street shoes: yes (set on the venue; not overridable per event)', 'wp-dansal' )
								: __( 'No street shoes: no (set on the venue; not overridable per event)', 'wp-dansal' )
						);
						?>
					</span>
				<?php endif; ?>
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
		<?php
	}

	/**
	 * Contact row.
	 */
	public function render_contact_fields( array $values, $name_prefix ) {
		list( $v, $name ) = $this->field_accessors( $values, $name_prefix );
		?>
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
			'_wpd_booking_url',
			'_wpd_pricing_amount',
			'_wpd_pricing_currency',
			'_wpd_contact_name',
			'_wpd_contact_email',
		);
		foreach ( $text_keys as $key ) {
			$raw           = isset( $input[ $key ] ) ? wp_unslash( $input[ $key ] ) : '';
			$out[ $key ]   = sanitize_text_field( is_array( $raw ) ? '' : $raw );
		}

		// Matches dansal's Pricing.Type exactly (API.md pricing_types) — an
		// off-vocab value (e.g. a stale "fixed" from before this was aligned
		// to dansal's actual enum) falls back to "free" rather than being
		// sent to dansal as-is.
		$pricing_type = isset( $input['_wpd_pricing_type'] ) ? sanitize_key( wp_unslash( $input['_wpd_pricing_type'] ) ) : '';
		$out['_wpd_pricing_type'] = in_array( $pricing_type, array( 'free', 'donation', 'single', 'multiple' ), true ) ? $pricing_type : 'free';

		$tier_labels  = isset( $input['_wpd_pricing_tier_label'] ) ? (array) $input['_wpd_pricing_tier_label'] : array();
		$tier_amounts = isset( $input['_wpd_pricing_tier_amount'] ) ? (array) $input['_wpd_pricing_tier_amount'] : array();
		$tiers        = array();
		foreach ( $tier_labels as $i => $label ) {
			$label = sanitize_text_field( wp_unslash( is_string( $label ) ? $label : '' ) );
			if ( '' === $label ) {
				continue;
			}
			$tiers[] = array(
				'label'  => $label,
				'amount' => isset( $tier_amounts[ $i ] ) ? (float) $tier_amounts[ $i ] : 0.0,
			);
		}
		$out['_wpd_pricing_tiers'] = $tiers;

		// Rows with a blank title, or a start/end that isn't a valid HH:MM
		// (dansal's own validTimeSlot format), are dropped rather than sent
		// — dansal's PUT /timetable rejects the *whole* array if any single
		// entry fails validation, so one empty template row shouldn't take
		// every other properly-filled row down with it on save.
		$time_re    = '/^([01]\d|2[0-3]):[0-5]\d$/';
		$tt_start   = isset( $input['_wpd_tt_start'] ) ? (array) $input['_wpd_tt_start'] : array();
		$tt_end     = isset( $input['_wpd_tt_end'] ) ? (array) $input['_wpd_tt_end'] : array();
		$tt_title   = isset( $input['_wpd_tt_title'] ) ? (array) $input['_wpd_tt_title'] : array();
		$tt_type    = isset( $input['_wpd_tt_type'] ) ? (array) $input['_wpd_tt_type'] : array();
		$tt_desc    = isset( $input['_wpd_tt_description'] ) ? (array) $input['_wpd_tt_description'] : array();
		$tt_room    = isset( $input['_wpd_tt_room'] ) ? (array) $input['_wpd_tt_room'] : array();
		$tt_loc     = isset( $input['_wpd_tt_location_id'] ) ? (array) $input['_wpd_tt_location_id'] : array();
		$tt_mus     = isset( $input['_wpd_tt_musician_id'] ) ? (array) $input['_wpd_tt_musician_id'] : array();
		$timetable  = array();
		foreach ( $tt_title as $i => $title ) {
			$title = sanitize_text_field( wp_unslash( is_string( $title ) ? $title : '' ) );
			$start = isset( $tt_start[ $i ] ) ? sanitize_text_field( wp_unslash( $tt_start[ $i ] ) ) : '';
			$end   = isset( $tt_end[ $i ] ) ? sanitize_text_field( wp_unslash( $tt_end[ $i ] ) ) : '';
			if ( '' === $title || ! preg_match( $time_re, $start ) || ! preg_match( $time_re, $end ) ) {
				continue;
			}
			$timetable[] = array(
				'start_time'  => $start,
				'end_time'    => $end,
				'title'       => $title,
				'entry_type'  => isset( $tt_type[ $i ] ) && 'workshop' === $tt_type[ $i ] ? 'workshop' : 'bal',
				// Not editable on this screen yet (#85) — carried through
				// as-is from whatever was last pulled from dansal.
				'description' => isset( $tt_desc[ $i ] ) ? sanitize_text_field( wp_unslash( $tt_desc[ $i ] ) ) : '',
				'room'        => isset( $tt_room[ $i ] ) ? sanitize_text_field( wp_unslash( $tt_room[ $i ] ) ) : '',
				'location_id' => isset( $tt_loc[ $i ] ) ? absint( $tt_loc[ $i ] ) : 0,
				'musician_id' => isset( $tt_mus[ $i ] ) ? absint( $tt_mus[ $i ] ) : 0,
			);
		}
		$out['_wpd_timetable'] = $timetable;

		$vocab_map = array(
			'_wpd_food'            => 'food',
			'_wpd_drink'           => 'drink',
			'_wpd_floor_condition' => 'floor_condition',
		);
		foreach ( $vocab_map as $meta_key => $vocab_key ) {
			$raw            = isset( $input[ $meta_key ] ) ? wp_unslash( $input[ $meta_key ] ) : '';
			$out[ $meta_key ] = WPD_Vocab::sanitize( $vocab_key, is_array( $raw ) ? '' : $raw );
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

		$out['_wpd_room_id'] = isset( $input['_wpd_room_id'] ) ? (string) absint( $input['_wpd_room_id'] ) : '';
		if ( '0' === $out['_wpd_room_id'] ) {
			$out['_wpd_room_id'] = '';
		}

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
