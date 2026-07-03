<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Classic widget wrapping [dansal_events view="mini"] for sidebar/widget
 * areas (Appearance → Widgets, or the "Legacy Widget" block in a block
 * theme). Registered directly via widgets_init at the bottom of this file
 * rather than instantiated by WPD_Plugin like the other classes, since
 * WordPress itself constructs WP_Widget subclasses when needed.
 */
class WPD_Widget_Mini_Calendar extends WP_Widget {

	public function __construct() {
		parent::__construct(
			'wpd_mini_calendar',
			__( 'Dansal Mini Calendar', 'wp-dansal' ),
			array(
				'description' => __( 'Compact monthly calendar of dance events, color-coded by type.', 'wp-dansal' ),
			)
		);
	}

	public function widget( $args, $instance ) {
		$title = ! empty( $instance['title'] ) ? $instance['title'] : '';

		echo $args['before_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- sidebar-controlled markup from register_sidebar(), not user input.

		if ( $title ) {
			echo $args['before_title'] . esc_html( apply_filters( 'widget_title', $title, $instance, $this->id_base ) ) . $args['after_title']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- before_title/after_title are sidebar-controlled markup; the title text itself is escaped.
		}

		// Reuses the existing [dansal_events view="mini"] renderer rather than
		// duplicating markup; its output is already escaped internally.
		echo wpd_plugin()->frontend->shortcode_events( array( 'view' => 'mini' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- shortcode_events() output is already escaped.

		echo $args['after_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- sidebar-controlled markup from register_sidebar(), not user input.
	}

	public function form( $instance ) {
		$title = ! empty( $instance['title'] ) ? $instance['title'] : '';
		?>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"><?php esc_html_e( 'Title:', 'wp-dansal' ); ?></label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>" type="text" value="<?php echo esc_attr( $title ); ?>" />
		</p>
		<?php
	}

	public function update( $new_instance, $old_instance ) {
		$instance          = array();
		$instance['title'] = ! empty( $new_instance['title'] ) ? sanitize_text_field( $new_instance['title'] ) : '';
		return $instance;
	}
}

add_action(
	'widgets_init',
	function () {
		register_widget( 'WPD_Widget_Mini_Calendar' );
	}
);
