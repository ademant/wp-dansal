<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Classic widget wrapping [dansal_events view="simple"] for sidebar/widget
 * areas. Compact one-line-per-event list with optional colored type dots,
 * matching the mini-calendar's color palette. Registered on widgets_init at
 * the bottom of this file.
 */
class WPD_Widget_Upcoming_Events extends WP_Widget {

	const TYPE_KEYS = array( 'ball', 'workshop', 'festival', 'other' );

	public function __construct() {
		parent::__construct(
			'wpd_upcoming_events',
			__( 'Dansal Upcoming Events', 'wp-dansal' ),
			array(
				'description' => __( 'Compact list of upcoming dance events with optional type icons.', 'wp-dansal' ),
			)
		);
	}

	public function widget( $args, $instance ) {
		$title       = ! empty( $instance['title'] ) ? $instance['title'] : '';
		$limit       = isset( $instance['limit'] ) ? max( 1, min( 100, (int) $instance['limit'] ) ) : 5;
		$tag         = ! empty( $instance['tag'] ) ? sanitize_key( $instance['tag'] ) : '';
		$show_types  = ! empty( $instance['show_types'] ) ? 1 : 0;
		$types       = isset( $instance['types'] ) && is_array( $instance['types'] )
			? array_values( array_intersect( $instance['types'], self::TYPE_KEYS ) )
			: array();

		echo $args['before_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- sidebar-controlled markup from register_sidebar(), not user input.

		if ( $title ) {
			echo $args['before_title'] . esc_html( apply_filters( 'widget_title', $title, $instance, $this->id_base ) ) . $args['after_title']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		$sc_atts = array(
			'view'       => 'simple',
			'limit'      => $limit,
			'show_types' => $show_types,
		);
		if ( '' !== $tag ) {
			$sc_atts['tag'] = $tag;
		}
		if ( ! empty( $types ) ) {
			$sc_atts['type'] = implode( ',', $types );
		}

		echo wpd_plugin()->frontend->shortcode_events( $sc_atts ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- shortcode_events() output is already escaped.

		echo $args['after_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	public function form( $instance ) {
		$title      = ! empty( $instance['title'] ) ? $instance['title'] : '';
		$limit      = isset( $instance['limit'] ) ? (int) $instance['limit'] : 5;
		$tag        = ! empty( $instance['tag'] ) ? $instance['tag'] : '';
		$show_types = ! empty( $instance['show_types'] );
		$types      = isset( $instance['types'] ) && is_array( $instance['types'] ) ? $instance['types'] : array();
		$labels     = array(
			'ball'     => __( 'Ball', 'wp-dansal' ),
			'workshop' => __( 'Workshop', 'wp-dansal' ),
			'festival' => __( 'Festival', 'wp-dansal' ),
			'other'    => __( 'Other', 'wp-dansal' ),
		);
		?>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"><?php esc_html_e( 'Title:', 'wp-dansal' ); ?></label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>" type="text" value="<?php echo esc_attr( $title ); ?>" />
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'limit' ) ); ?>"><?php esc_html_e( 'Number of events:', 'wp-dansal' ); ?></label>
			<input class="tiny-text" id="<?php echo esc_attr( $this->get_field_id( 'limit' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'limit' ) ); ?>" type="number" min="1" max="100" value="<?php echo esc_attr( $limit ); ?>" />
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'tag' ) ); ?>"><?php esc_html_e( 'Tag (optional):', 'wp-dansal' ); ?></label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'tag' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'tag' ) ); ?>" type="text" value="<?php echo esc_attr( $tag ); ?>" placeholder="bal-folk" />
		</p>
		<p>
			<strong><?php esc_html_e( 'Event types (optional):', 'wp-dansal' ); ?></strong><br />
			<?php foreach ( self::TYPE_KEYS as $key ) : ?>
				<label style="margin-right:0.75em;">
					<input type="checkbox" name="<?php echo esc_attr( $this->get_field_name( 'types' ) ); ?>[]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, $types, true ) ); ?> />
					<?php echo esc_html( $labels[ $key ] ); ?>
				</label>
			<?php endforeach; ?>
		</p>
		<p>
			<label>
				<input type="checkbox" name="<?php echo esc_attr( $this->get_field_name( 'show_types' ) ); ?>" value="1" <?php checked( $show_types ); ?> />
				<?php esc_html_e( 'Show type icons', 'wp-dansal' ); ?>
			</label>
		</p>
		<?php
	}

	public function update( $new_instance, $old_instance ) {
		$instance               = array();
		$instance['title']      = ! empty( $new_instance['title'] ) ? sanitize_text_field( $new_instance['title'] ) : '';
		$instance['limit']      = isset( $new_instance['limit'] ) ? max( 1, min( 100, (int) $new_instance['limit'] ) ) : 5;
		$instance['tag']        = ! empty( $new_instance['tag'] ) ? sanitize_key( $new_instance['tag'] ) : '';
		$instance['show_types'] = ! empty( $new_instance['show_types'] ) ? 1 : 0;
		$raw_types              = isset( $new_instance['types'] ) && is_array( $new_instance['types'] )
			? array_map( 'sanitize_key', $new_instance['types'] )
			: array();
		$instance['types']      = array_values( array_intersect( $raw_types, self::TYPE_KEYS ) );
		return $instance;
	}
}

add_action(
	'widgets_init',
	function () {
		register_widget( 'WPD_Widget_Upcoming_Events' );
	}
);
