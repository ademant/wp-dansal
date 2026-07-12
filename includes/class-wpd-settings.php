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
		add_action( 'wp_ajax_wpd_connect_link', array( $this, 'ajax_connect_link' ) );
	}

	private function defaults() {
		return array(
			'base_url'        => '',
			'org_id'          => '',
			// Deprecated: kept for UI placeholder only. Real key may be stored
			// encrypted in 'api_key_encrypted'. Do NOT store plaintext here.
			'api_key'         => '',
			'api_key_encrypted' => '',
			'nominatim_email' => get_option( 'admin_email' ),
			'dedup_radius_km' => 0.2,
			// Overlay field defaults applied when a fresh dansal_event is
			// opened for editing (auto-draft) and the corresponding meta is
			// still empty. See WPD_Event_Fields for the field set.
			'event_defaults'  => array(),
			// API key lifecycle bookkeeping — set by WPD_Api_Client after
			// a successful renew, cleared on reconnect.
			// 0 = unknown / never checked. Positive Unix ts = known expiry.
			'api_key_expires_at' => 0,
			// True once dansal has told us the key has no expiry (renew → 400);
			// stops us from calling renew every cron tick for a permanent key.
			'api_key_no_expiry'  => false,
			// True after renew returned 401 (key already expired). Persistent
			// admin notice until the admin re-runs the connect-link flow.
			'api_key_dead'       => false,
			// Optional security settings
			'pinned_cert_sha256' => '',
			'hmac_secret' => '',
		);
	}

	/**
	 * Overlay defaults applied to fresh events. Keys are dansal_event meta
	 * keys (see WPD_Event_Fields::overlay_keys()). Blank values simply mean
	 * "no default for this field."
	 */
	public function get_event_defaults() {
		$all = $this->get_all();
		return is_array( $all['event_defaults'] ) ? $all['event_defaults'] : array();
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

	/**
	 * Return the publisher API key plaintext. Prefer the encrypted storage
	 * when available for new installs.
	 */
	public function get_api_key() {
		$all = $this->get_all();
		if ( ! empty( $all['api_key_encrypted'] ) ) {
			$decrypted = $this->decrypt_api_key( $all['api_key_encrypted'] );
			if ( false !== $decrypted && '' !== $decrypted ) {
				return (string) $decrypted;
			}
		}
		// Fallback to legacy plaintext option for backward compatibility.
		return (string) $this->get( 'api_key' );
	}

	public function get_api_key_expires_at() {
		return (int) $this->get( 'api_key_expires_at' );
	}

	public function get_api_key_no_expiry() {
		return (bool) $this->get( 'api_key_no_expiry' );
	}

	public function is_api_key_dead() {
		return (bool) $this->get( 'api_key_dead' );
	}

	/**
	 * Persist a fresh publisher API key + expiry after a successful
	 * POST /api/v1/apikeys/renew. Clears the "dead" flag, invalidates
	 * any cached session token so the next call picks up the new key.
	 *
	 * @param string   $key           New api_key returned by dansal.
	 * @param int|null $expires_at    Unix ts, or null if dansal omitted it.
	 */
	public function record_apikey_renewed( $key, $expires_at ) {
		$opts                       = $this->get_all();
		$plaintext_key = sanitize_text_field( (string) $key );
		// Prefer encrypted storage when possible.
		$encrypted = $this->encrypt_api_key( $plaintext_key );
		if ( false !== $encrypted ) {
			$opts['api_key_encrypted'] = $encrypted;
			// Keep legacy api_key truthy so the options UI still shows a key is set.
			$opts['api_key'] = '***';
		} else {
			$opts['api_key'] = $plaintext_key;
			$opts['api_key_encrypted'] = '';
		}
		$opts['api_key_expires_at'] = $expires_at ? (int) $expires_at : 0;
		$opts['api_key_no_expiry']  = false;
		$opts['api_key_dead']       = false;
		update_option( self::OPTION, $opts );
		delete_transient( WPD_Api_Client::TOKEN_TRANSIENT );
	}

	public function mark_apikey_no_expiry() {
		$opts                      = $this->get_all();
		$opts['api_key_no_expiry'] = true;
		update_option( self::OPTION, $opts );
	}

	/**
	 * Encrypt API key using openssl with a key derived from site salts.
	 * Returns base64(iv . ciphertext) on success, false on failure.
	 */
	private function encrypt_api_key( $plaintext ) {
		if ( empty( $plaintext ) || ! function_exists( 'openssl_encrypt' ) ) {
			return false;
		}
		$key_material = defined( 'AUTH_KEY' ) && defined( 'AUTH_SALT' ) ? AUTH_KEY . AUTH_SALT : '';
		if ( '' === $key_material ) {
			return false;
		}
		$key = substr( hash( 'sha256', $key_material, true ), 0, 32 );
		$iv  = openssl_random_pseudo_bytes( 16 );
		$cipher = openssl_encrypt( $plaintext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
		if ( false === $cipher ) {
			return false;
		}
		return base64_encode( $iv . $cipher );
	}

	/**
	 * Decrypt an API key stored as base64(iv . ciphertext).
	 * Returns plaintext on success or false on failure.
	 */
	private function decrypt_api_key( $blob ) {
		if ( empty( $blob ) || ! function_exists( 'openssl_decrypt' ) ) {
			return false;
		}
		$data = base64_decode( $blob, true );
		if ( false === $data || strlen( $data ) <= 16 ) {
			return false;
		}
		$iv = substr( $data, 0, 16 );
		$cipher = substr( $data, 16 );
		$key_material = defined( 'AUTH_KEY' ) && defined( 'AUTH_SALT' ) ? AUTH_KEY . AUTH_SALT : '';
		if ( '' === $key_material ) {
			return false;
		}
		$key = substr( hash( 'sha256', $key_material, true ), 0, 32 );
		$plaintext = openssl_decrypt( $cipher, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
		if ( false === $plaintext ) {
			return false;
		}
		return $plaintext;
	}

	public function mark_apikey_dead() {
		$opts                 = $this->get_all();
		$opts['api_key_dead'] = true;
		update_option( self::OPTION, $opts );
	}

	/**
	 * Retrieve the peer certificate SHA256 (hex) for a given host:port.
	 * Returns lowercase hex string on success, false on failure.
	 */
	private function get_peer_cert_sha256( $host, $port = 443 ) {
		$errno = 0;
		$errstr = '';
		$context = stream_context_create( array( 'ssl' => array( 'capture_peer_cert' => true, 'verify_peer' => true, 'verify_peer_name' => true ) ) );
		$remote = sprintf('%s:%d', $host, (int) $port);
		$client = stream_socket_client( 'ssl://' . $remote, $errno, $errstr, 5, STREAM_CLIENT_CONNECT, $context );
		if ( ! $client ) {
			return false;
		}
		$params = stream_context_get_params( $client );
		if ( empty( $params['options']['ssl']['peer_certificate'] ) ) {
			fclose( $client ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing a stream_socket_client resource; WP_Filesystem operates on files, not sockets.
			return false;
		}
		$cert = $params['options']['ssl']['peer_certificate'];
		fclose( $client );
		$export = '';
		if ( ! openssl_x509_export( $cert, $export ) ) {
			return false;
		}
		// PEM -> DER: strip headers and base64-decode.
		$lines = preg_split('/\r?\n/', $export);
		$body = '';
		foreach ( $lines as $line ) {
			if ( strpos( $line, '-----' ) === 0 ) {
				continue;
			}
			$body .= trim( $line );
		}
		$der = base64_decode( $body );
		if ( false === $der ) {
			return false;
		}
		$hash = hash( 'sha256', $der );
		return strtolower( $hash );
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

		// Base URL — require HTTPS unless filter allows insecure.
		$raw_base = isset( $input['base_url'] ) ? trim( $input['base_url'] ) : $existing['base_url'];
		$allow_http = (bool) apply_filters( 'wpd_allow_insecure_connect_url', false );
		$scheme_re = $allow_http ? '#^https?://#i' : '#^https://#i';
		if ( '' !== $raw_base && preg_match( $scheme_re, $raw_base ) ) {
			$out['base_url'] = esc_url_raw( untrailingslashit( $raw_base ) );
		} else {
			$out['base_url'] = $existing['base_url'];
		}

		$out['org_id']          = isset( $input['org_id'] ) ? absint( $input['org_id'] ) : $existing['org_id'];
		$out['nominatim_email'] = isset( $input['nominatim_email'] ) ? sanitize_email( $input['nominatim_email'] ) : $existing['nominatim_email'];
		$out['dedup_radius_km'] = isset( $input['dedup_radius_km'] ) ? (float) str_replace( ',', '.', (string) $input['dedup_radius_km'] ) : $existing['dedup_radius_km'];

		// Only overwrite the API key if a new value was actually typed in;
		// the settings form re-renders a masked placeholder, never the real key.
		if ( ! empty( $input['api_key'] ) ) {
			$plaintext = sanitize_text_field( $input['api_key'] );
			$encrypted = $this->encrypt_api_key( $plaintext );
			if ( false !== $encrypted ) {
				$out['api_key_encrypted'] = $encrypted;
				$out['api_key'] = '***';
			} else {
				// Fallback to legacy plaintext storage if encryption unavailable.
				$out['api_key'] = $plaintext;
				$out['api_key_encrypted'] = '';
			}
		} else {
			$out['api_key'] = $existing['api_key'];
			$out['api_key_encrypted'] = isset( $existing['api_key_encrypted'] ) ? $existing['api_key_encrypted'] : '';
		}

		// Credentials or org changed: any cached publisher session token is invalid now.
		$existing_plain = '';
		if ( ! empty( $existing['api_key_encrypted'] ) ) {
			$existing_plain = $this->decrypt_api_key( $existing['api_key_encrypted'] );
		} elseif ( ! empty( $existing['api_key'] ) && '***' !== $existing['api_key'] ) {
			$existing_plain = $existing['api_key'];
		}
		$incoming_plain = '';
		if ( ! empty( $input['api_key'] ) ) {
			$incoming_plain = sanitize_text_field( $input['api_key'] );
		}
		if ( $incoming_plain !== $existing_plain || $out['base_url'] !== $existing['base_url'] ) {
			delete_transient( WPD_Api_Client::TOKEN_TRANSIENT );
		}

		// A new API key resets everything the renew flow tracks; unrelated
		// updates preserve it.
		if ( '' !== $incoming_plain && $incoming_plain !== $existing_plain ) {
			$out['api_key_expires_at'] = 0;
			$out['api_key_no_expiry']  = false;
			$out['api_key_dead']       = false;
		} else {
			$out['api_key_expires_at'] = (int) $existing['api_key_expires_at'];
			$out['api_key_no_expiry']  = (bool) $existing['api_key_no_expiry'];
			$out['api_key_dead']       = (bool) $existing['api_key_dead'];
		}

		// Optional security settings
		$out['pinned_cert_sha256'] = isset( $input['pinned_cert_sha256'] ) ? sanitize_text_field( $input['pinned_cert_sha256'] ) : ( isset( $existing['pinned_cert_sha256'] ) ? $existing['pinned_cert_sha256'] : '' );
		$out['hmac_secret'] = isset( $input['hmac_secret'] ) ? sanitize_text_field( $input['hmac_secret'] ) : ( isset( $existing['hmac_secret'] ) ? $existing['hmac_secret'] : '' );

		// A different dansal server may ship different vocabularies.
		if ( $out['base_url'] !== $existing['base_url'] ) {
			WPD_Vocab::flush();
		}

		$out['event_defaults'] = isset( $input['event_defaults'] ) && is_array( $input['event_defaults'] )
			? WPD_Event_Fields::sanitize_field_group( $input['event_defaults'] )
			: array();

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
				<?php esc_html_e( 'Connect this site to a dansal server (https://github.com/ademant/dansal). Events and locations created from this site are attributed to one organization there.', 'wp-dansal' ); ?>
			</p>

			<h2><?php esc_html_e( 'Connect via Link (recommended)', 'wp-dansal' ); ?></h2>
			<p>
				<?php esc_html_e( 'In dansal, open /admin/users, click "Connect link" next to your organization\'s publisher row, and paste the one-time URL it shows you here.', 'wp-dansal' ); ?>
			</p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="wpd-connect-url"><?php esc_html_e( 'Connect Link', 'wp-dansal' ); ?></label></th>
					<td>
						<input type="url" id="wpd-connect-url" class="regular-text" placeholder="https://api.example.com/api/v1/invites/abc123/publisher" />
						<button type="button" class="button button-primary" id="wpd-connect-link"><?php esc_html_e( 'Connect', 'wp-dansal' ); ?></button>
						<p class="description"><?php esc_html_e( 'Single-use — it fills in the base URL, organization, and API key below automatically and is consumed immediately.', 'wp-dansal' ); ?></p>
						<p id="wpd-connect-link-result"></p>
					</td>
				</tr>
			</table>

			<form method="post" action="options.php">
				<?php settings_fields( 'wpd_settings_group' ); ?>
				<table class="form-table" role="presentation">
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
							<input type="text" inputmode="decimal" pattern="[0-9]+([.,][0-9]+)?" id="wpd_dedup_radius_km" name="<?php echo esc_attr( self::OPTION ); ?>[dedup_radius_km]" value="<?php echo esc_attr( $o['dedup_radius_km'] ); ?>" class="small-text" />
							<p class="description"><?php esc_html_e( 'When creating a location, dansal locations within this radius are offered as possible duplicates before a new one is created.', 'wp-dansal' ); ?></p>
						</td>
					</tr>
				</table>

				<details<?php echo $o['api_key'] ? '' : ' open'; ?> style="margin: 1em 0;">
					<summary><?php esc_html_e( 'Manual connection (advanced)', 'wp-dansal' ); ?></summary>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="wpd_base_url"><?php esc_html_e( 'Dansal Base URL', 'wp-dansal' ); ?></label></th>
							<td>
								<input type="url" id="wpd_base_url" name="<?php echo esc_attr( self::OPTION ); ?>[base_url]" value="<?php echo esc_attr( $o['base_url'] ); ?>" class="regular-text" placeholder="https://api.dansal.example.com" />
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="wpd_org_id"><?php esc_html_e( 'Organization ID', 'wp-dansal' ); ?></label></th>
							<td>
								<input type="number" min="1" id="wpd_org_id" name="<?php echo esc_attr( self::OPTION ); ?>[org_id]" value="<?php echo esc_attr( $o['org_id'] ); ?>" class="small-text" />
								<p class="description"><?php esc_html_e( 'The org_id returned when the publisher API key was created. All events and locations created from this site are attributed to this organization.', 'wp-dansal' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="wpd_api_key"><?php esc_html_e( 'Publisher API Key', 'wp-dansal' ); ?></label></th>
							<td>
								<input type="password" id="wpd_api_key" name="<?php echo esc_attr( self::OPTION ); ?>[api_key]" value="" class="regular-text" autocomplete="new-password" placeholder="<?php echo $o['api_key'] ? esc_attr__( '(unchanged — leave blank to keep current key)', 'wp-dansal' ) : 'ak_...'; ?>" />
								<p class="description"><?php esc_html_e( 'Begins with ak_. Shown only once by dansal at creation time; stored here and exchanged for short-lived session tokens. Use this if you already have a key from dansal_admin CLI instead of a connect link.', 'wp-dansal' ); ?></p>
							</td>
						</tr>
					</table>
				</details>

				<details style="margin: 1em 0;">
					<summary style="font-weight: 600; cursor: pointer; font-size: 1.3em;"><?php esc_html_e( 'Event defaults', 'wp-dansal' ); ?></summary>
					<p class="description">
						<?php esc_html_e( 'Values you set here are pre-filled on brand-new events (before the first save). Editing an existing event never overwrites its stored values.', 'wp-dansal' ); ?>
					</p>
					<table class="form-table" role="presentation">
						<?php
						$event_defaults = is_array( $o['event_defaults'] ) ? $o['event_defaults'] : array();
						wpd_plugin()->event_fields->render_field_group( $event_defaults, self::OPTION . '[event_defaults]' );
						?>
					</table>
				</details>

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

		document.getElementById('wpd-connect-link').addEventListener('click', function () {
			var urlEl = document.getElementById('wpd-connect-url');
			var resultEl = document.getElementById('wpd-connect-link-result');
			var url = urlEl.value.trim();
			if (!url) {
				return;
			}
			resultEl.textContent = <?php echo wp_json_encode( __( 'Connecting…', 'wp-dansal' ) ); ?>;
			resultEl.style.color = '';
			var body = new URLSearchParams();
			body.set('action', 'wpd_connect_link');
			body.set('_wpnonce', <?php echo wp_json_encode( wp_create_nonce( 'wpd_connect_link' ) ); ?>);
			body.set('connect_url', url);
			fetch(ajaxurl, { method: 'POST', body: body })
				.then(function (r) { return r.json(); })
				.then(function (data) {
					if (data.success) {
						urlEl.value = '';
						document.getElementById('wpd_base_url').value = data.data.base_url;
						document.getElementById('wpd_org_id').value = data.data.org_id;
					}
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

		// Optional certificate pin check configured in settings.
		$pinned = $this->get( 'pinned_cert_sha256' );
		if ( ! empty( $pinned ) ) {
			$base = $this->get_base_url();
			$host = wp_parse_url( $base, PHP_URL_HOST );
			$port = wp_parse_url( $base, PHP_URL_PORT );
			$port = $port ? (int) $port : 443;
			$fingerprint = $this->get_peer_cert_sha256( $host, $port );
			if ( false === $fingerprint || strtolower( trim( $pinned ) ) !== strtolower( trim( $fingerprint ) ) ) {
				wp_send_json_error( array( 'message' => __( 'TLS certificate pin mismatch for configured dansal base URL.', 'wp-dansal' ) ) );
			}
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

	/**
	 * Redeem a dansal connect-link (POST /api/v1/invites/{token}/publisher)
	 * to bootstrap base_url/org_id/api_key in one step, instead of an admin
	 * copying a numeric org ID and API key by hand.
	 *
	 * @see https://github.com/ademant/dansal API.md, "Connect-link bootstrap"
	 */
	public function ajax_connect_link() {
		check_ajax_referer( 'wpd_connect_link' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'wp-dansal' ) ), 403 );
		}

		$connect_url = isset( $_POST['connect_url'] ) ? esc_url_raw( wp_unslash( $_POST['connect_url'] ) ) : '';
		// #54: require HTTPS so the api_key exchange can't be sniffed on-wire.
		// Escape hatch for local dev via `wpd_allow_insecure_connect_url` filter.
		$allow_http = (bool) apply_filters( 'wpd_allow_insecure_connect_url', false );
		$scheme_re  = $allow_http ? 'https?' : 'https';
		if ( '' === $connect_url || ! preg_match( '#^' . $scheme_re . '://\S+/api/v1/invites/[^/\s]+/publisher/?$#', $connect_url ) ) {
			wp_send_json_error( array( 'message' => __( 'Connect link must be HTTPS and look like .../api/v1/invites/{token}/publisher.', 'wp-dansal' ) ) );
		}

		$client_name = sprintf( 'wp-dansal @ %s', wp_parse_url( home_url(), PHP_URL_HOST ) );

		// #55: single-use random challenge dansal must echo back verbatim.
		// 32 hex chars fits dansal's 16..256 accepted length (see dansal #771).
		$challenge = bin2hex( random_bytes( 16 ) );

		// #56: ephemeral RSA-2048 keypair. dansal encrypts the api_key with
		// the public key so it's never on the wire in plaintext, even if the
		// TLS layer is compromised (dansal #770). Falls back gracefully to
		// plaintext transport when openssl is unavailable at build time.
		$private_key = null;
		$public_pem  = '';
		if ( function_exists( 'openssl_pkey_new' ) ) {
			$pair = @openssl_pkey_new(
				array(
					'private_key_bits' => 2048,
					'private_key_type' => OPENSSL_KEYTYPE_RSA,
				)
			);
			if ( $pair ) {
				$details = openssl_pkey_get_details( $pair );
				if ( is_array( $details ) && ! empty( $details['key'] ) ) {
					$public_pem  = $details['key'];
					$private_key = $pair;
				}
			}
		}

		$payload = array(
			'name'          => $client_name,
			'user_metadata' => array(
				'client_name' => $client_name,
				'client_url'  => home_url(),
				'challenge'   => $challenge,
			),
		);
		if ( '' !== $public_pem ) {
			$payload['client_pubkey'] = $public_pem;
		}

		$response = wp_remote_post(
			$connect_url,
			array(
				'timeout'           => WPD_Api_Client::timeout( '/api/v1/invites/{token}/publisher' ),
				// Refuses to follow the request if it resolves to a private/
				// internal IP — this URL is admin-supplied, so treat it like
				// any other user-supplied fetch target (SSRF hardening).
				'reject_unsafe_urls' => true,
				'headers'           => array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				'body'              => wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $response ) ) {
			/* translators: %s: underlying HTTP/connection error message. */
			wp_send_json_error( array( 'message' => sprintf( __( 'Could not reach that link: %s', 'wp-dansal' ), $response->get_error_message() ) ) );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 || ! is_array( $body ) || empty( $body['org_id'] ) || empty( $body['base_url'] ) ) {
			$message = is_array( $body ) && ! empty( $body['error'] ) ? $body['error'] : sprintf( 'HTTP %d', $code );
			/* translators: %s: underlying error message from dansal. */
			wp_send_json_error( array( 'message' => sprintf( __( 'Connect link redemption failed: %s', 'wp-dansal' ), $message ) ) );
		}

		// #55: verify challenge echo before touching credentials.
		if ( empty( $body['challenge'] ) || ! hash_equals( $challenge, (string) $body['challenge'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Connect link challenge mismatch — refusing to store credentials.', 'wp-dansal' ) ) );
		}

		// #56: prefer encrypted api_key when we sent a pubkey. Plaintext
		// api_key remains valid when we couldn't generate a keypair.
		if ( ! empty( $body['api_key_encrypted'] ) && $private_key ) {
			$cipher    = base64_decode( (string) $body['api_key_encrypted'], true );
			$decrypted = '';
			if ( false === $cipher || ! openssl_private_decrypt( $cipher, $decrypted, $private_key, OPENSSL_PKCS1_OAEP_PADDING ) || '' === $decrypted ) {
				wp_send_json_error( array( 'message' => __( 'Could not decrypt the API key returned by dansal.', 'wp-dansal' ) ) );
			}
			$api_key = $decrypted;
		} elseif ( ! empty( $body['api_key'] ) ) {
			$api_key = (string) $body['api_key'];
		} else {
			wp_send_json_error( array( 'message' => __( 'dansal response did not include an API key.', 'wp-dansal' ) ) );
		}

		$previous                       = $this->get_all();
		$existing                       = $previous;
		$existing['base_url']           = esc_url_raw( untrailingslashit( trim( $body['base_url'] ) ) );
		$existing['org_id']             = absint( $body['org_id'] );
		// Store api key encrypted when possible; keep a masked placeholder
		$plaintext_key = sanitize_text_field( $api_key );
		$encrypted = $this->encrypt_api_key( $plaintext_key );
		if ( false !== $encrypted ) {
			$existing['api_key_encrypted'] = $encrypted;
			$existing['api_key'] = '***';
		} else {
			$existing['api_key'] = $plaintext_key;
			$existing['api_key_encrypted'] = '';
		}
		$existing['api_key_expires_at'] = 0;
		$existing['api_key_no_expiry']  = false;
		$existing['api_key_dead']       = false;
		update_option( self::OPTION, $existing );
		delete_transient( WPD_Api_Client::TOKEN_TRANSIENT );
		WPD_Vocab::flush();

		// #57: prove the credentials work before we commit to them. If the
		// session-token exchange fails, roll back so a broken key doesn't
		// silently poison every later request.
		$token = wpd_plugin()->api->get_session_token( true );
		if ( is_wp_error( $token ) ) {
			update_option( self::OPTION, $previous );
			delete_transient( WPD_Api_Client::TOKEN_TRANSIENT );
			/* translators: %s: underlying error from dansal token exchange. */
			wp_send_json_error( array( 'message' => sprintf( __( 'Connect link redeemed but the returned API key did not authenticate: %s', 'wp-dansal' ), $token->get_error_message() ) ) );
		}

		wp_send_json_success(
			array(
				'base_url' => $existing['base_url'],
				'org_id'   => $existing['org_id'],
				/* translators: %s: organization name returned by dansal. */
				'message'  => sprintf( __( 'Connected to organization "%s". Settings saved.', 'wp-dansal' ), isset( $body['org_name'] ) ? $body['org_name'] : $existing['org_id'] ),
			)
		);
	}
}
