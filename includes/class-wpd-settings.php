<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Connection settings: one dansal server + one publisher API key + org_id,
 * matching dansal's documented "one connection per org" integration model
 * (see API.md, "Building a third-party integration on a publisher account").
 */
class WPD_Settings {

	const OPTION = 'wpd_settings';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'wp_ajax_wpd_test_connection', array( $this, 'ajax_test_connection' ) );
	}

	private function defaults() {
		return array(
			'base_url'        => '',
			'org_id'          => '',
			'api_key'         => '',
			'nominatim_email' => get_option( 'admin_email' ),
			'dedup_radius_km' => 0.2,
		);
	}

	public function get_all() {
		$opts = get_option( self::OPTION, array() );
		return wp_parse_args( $opts, $this->defaults() );
	}

	public function get( $key ) {
		$all = $this->get_all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	public function get_base_url() {
		return untrailingslashit( trim( (string) $this->get( 'base_url' ) ) );
	}

	public function get_org_id() {
		return (int) $this->get( 'org_id' );
	}

	public function get_api_key() {
		return (string) $this->get( 'api_key' );
	}

	public function get_nominatim_email() {
		return (string) $this->get( 'nominatim_email' );
	}

	public function get_dedup_radius_km() {
		$v = (float) $this->get( 'dedup_radius_km' );
		return $v > 0 ? $v : 0.2;
	}

	public function is_configured() {
		return '' !== $this->get_base_url() && '' !== $this->get_api_key() && $this->get_org_id() > 0;
	}

	public function add_menu() {
		add_options_page(
			__( 'Dansal Connection', 'wp-dansal' ),
			__( 'Dansal', 'wp-dansal' ),
			'manage_options',
			'wpd-settings',
			array( $this, 'render_page' )
		);
	}

	public function register_settings() {
		register_setting( 'wpd_settings_group', self::OPTION, array( $this, 'sanitize' ) );
	}

	public function sanitize( $input ) {
		$existing = $this->get_all();
		$out      = array();

		$out['base_url']        = isset( $input['base_url'] ) ? esc_url_raw( untrailingslashit( trim( $input['base_url'] ) ) ) : $existing['base_url'];
		$out['org_id']          = isset( $input['org_id'] ) ? absint( $input['org_id'] ) : $existing['org_id'];
		$out['nominatim_email'] = isset( $input['nominatim_email'] ) ? sanitize_email( $input['nominatim_email'] ) : $existing['nominatim_email'];
		$out['dedup_radius_km'] = isset( $input['dedup_radius_km'] ) ? (float) $input['dedup_radius_km'] : $existing['dedup_radius_km'];

		// Only overwrite the API key if a new value was actually typed in;
		// the settings form re-renders a masked placeholder, never the real key.
		if ( ! empty( $input['api_key'] ) ) {
			$out['api_key'] = sanitize_text_field( $input['api_key'] );
		} else {
			$out['api_key'] = $existing['api_key'];
		}

		// Credentials or org changed: any cached publisher session token is invalid now.
		if ( $out['api_key'] !== $existing['api_key'] || $out['base_url'] !== $existing['base_url'] ) {
			delete_transient( WPD_Api_Client::TOKEN_TRANSIENT );
		}

		return $out;
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$o = $this->get_all();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Dansal Connection', 'wp-dansal' ); ?></h1>
			<p>
				<?php esc_html_e( 'Connect this site to a dansal server (https://github.com/ademant/dansal). Configure one publisher API key scoped to a single organization — dansal issues this when an admin runs "dansal_admin publisher create" or POSTs /api/v1/publishers for your organization.', 'wp-dansal' ); ?>
			</p>
			<form method="post" action="options.php">
				<?php settings_fields( 'wpd_settings_group' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="wpd_base_url"><?php esc_html_e( 'Dansal Base URL', 'wp-dansal' ); ?></label></th>
						<td>
							<input type="url" id="wpd_base_url" name="<?php echo esc_attr( self::OPTION ); ?>[base_url]" value="<?php echo esc_attr( $o['base_url'] ); ?>" class="regular-text" placeholder="https://api.dansal.example.com" required />
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wpd_org_id"><?php esc_html_e( 'Organization ID', 'wp-dansal' ); ?></label></th>
						<td>
							<input type="number" min="1" id="wpd_org_id" name="<?php echo esc_attr( self::OPTION ); ?>[org_id]" value="<?php echo esc_attr( $o['org_id'] ); ?>" class="small-text" required />
							<p class="description"><?php esc_html_e( 'The org_id returned when the publisher API key was created. All events and locations created from this site are attributed to this organization.', 'wp-dansal' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wpd_api_key"><?php esc_html_e( 'Publisher API Key', 'wp-dansal' ); ?></label></th>
						<td>
							<input type="password" id="wpd_api_key" name="<?php echo esc_attr( self::OPTION ); ?>[api_key]" value="" class="regular-text" autocomplete="new-password" placeholder="<?php echo $o['api_key'] ? esc_attr__( '(unchanged — leave blank to keep current key)', 'wp-dansal' ) : 'ak_...'; ?>" />
							<p class="description"><?php esc_html_e( 'Begins with ak_. Shown only once by dansal at creation time; stored here and exchanged for short-lived session tokens.', 'wp-dansal' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wpd_nominatim_email"><?php esc_html_e( 'Nominatim Contact Email', 'wp-dansal' ); ?></label></th>
						<td>
							<input type="email" id="wpd_nominatim_email" name="<?php echo esc_attr( self::OPTION ); ?>[nominatim_email]" value="<?php echo esc_attr( $o['nominatim_email'] ); ?>" class="regular-text" />
							<p class="description"><?php esc_html_e( 'Sent as part of the User-Agent when looking up locations via OpenStreetMap Nominatim, per their usage policy.', 'wp-dansal' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wpd_dedup_radius_km"><?php esc_html_e( 'Location Duplicate Radius (km)', 'wp-dansal' ); ?></label></th>
						<td>
							<input type="number" step="0.05" min="0.01" id="wpd_dedup_radius_km" name="<?php echo esc_attr( self::OPTION ); ?>[dedup_radius_km]" value="<?php echo esc_attr( $o['dedup_radius_km'] ); ?>" class="small-text" />
							<p class="description"><?php esc_html_e( 'When creating a location, dansal locations within this radius are offered as possible duplicates before a new one is created.', 'wp-dansal' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
			<hr />
			<h2><?php esc_html_e( 'Test Connection', 'wp-dansal' ); ?></h2>
			<p>
				<button type="button" class="button" id="wpd-test-connection"><?php esc_html_e( 'Test Connection', 'wp-dansal' ); ?></button>
				<span id="wpd-test-connection-result" style="margin-left:10px;"></span>
			</p>
		</div>
		<script>
		document.getElementById('wpd-test-connection').addEventListener('click', function () {
			var resultEl = document.getElementById('wpd-test-connection-result');
			resultEl.textContent = <?php echo wp_json_encode( __( 'Testing…', 'wp-dansal' ) ); ?>;
			fetch(ajaxurl + '?action=wpd_test_connection&_wpnonce=' + encodeURIComponent(<?php echo wp_json_encode( wp_create_nonce( 'wpd_test_connection' ) ); ?>))
				.then(function (r) { return r.json(); })
				.then(function (data) {
					resultEl.textContent = data.data && data.data.message ? data.data.message : (data.success ? 'OK' : 'Error');
					resultEl.style.color = data.success ? 'green' : 'crimson';
				})
				.catch(function (e) {
					resultEl.textContent = String(e);
					resultEl.style.color = 'crimson';
				});
		});
		</script>
		<?php
	}

	public function ajax_test_connection() {
		check_ajax_referer( 'wpd_test_connection' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'wp-dansal' ) ), 403 );
		}

		if ( ! $this->is_configured() ) {
			wp_send_json_error( array( 'message' => __( 'Base URL, org ID and API key must all be set first.', 'wp-dansal' ) ) );
		}

		$api  = wpd_plugin()->api;
		$info = $api->get_public( '/api/v1/info' );
		if ( is_wp_error( $info ) ) {
			/* translators: %s: underlying HTTP/connection error message. */
			wp_send_json_error( array( 'message' => sprintf( __( 'Could not reach dansal server: %s', 'wp-dansal' ), $info->get_error_message() ) ) );
		}

		$token = $api->get_session_token( true );
		if ( is_wp_error( $token ) ) {
			/* translators: %s: underlying authentication error message. */
			wp_send_json_error( array( 'message' => sprintf( __( 'Reached server, but authentication failed: %s', 'wp-dansal' ), $token->get_error_message() ) ) );
		}

		wp_send_json_success(
            array(
				'message' => sprintf(
				/* translators: %s dansal server version */
                    __( 'Connected to dansal %s and authenticated successfully.', 'wp-dansal' ),
                    isset( $info['version'] ) ? $info['version'] : '?'
                ),
            )
        );
	}
}
