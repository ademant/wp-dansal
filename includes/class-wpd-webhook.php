<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Inbound receiver for dansal's push-based reverse sync (#141, dansal #1370).
 *
 * A single public route — no capability check, since dansal is not a logged-in
 * WP user. Authentication is entirely the HMAC signature dansal sends, reusing
 * the exact scheme WPD_Api_Client::apply_signing_headers() already implements
 * for this plugin's own outbound writes (dansal #1366): same three headers,
 * same canonical-payload construction, just verified here instead of
 * generated. See WPD_Settings for the outbound subscription management
 * (register/test/unregister/status) — this class only receives.
 */
class WPD_Webhook {

	/** Seen-nonce replay guard, keyed per-nonce; TTL matches the accepted skew window. */
	const NONCE_TRANSIENT_PREFIX = 'wpd_webhook_nonce_';

	/** @var WPD_Settings */
	private $settings;

	/** @var WPD_CPT_Event */
	private $cpt_event;

	public function __construct( WPD_Settings $settings, WPD_CPT_Event $cpt_event ) {
		$this->settings  = $settings;
		$this->cpt_event = $cpt_event;
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
	}

	public function register_rest_routes() {
		register_rest_route(
			'wpd/v1',
			'/webhook',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'receive' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * dansal API.md, "Publisher Webhooks": a pointer payload
	 * `{event, resource, resource_id, action, changed_at, organization_id,
	 * changed_by_user_id, delivery_id, emitted_at}`, or `{event: "ping", ...}`
	 * on subscription create/test. Never carries the resource body — we
	 * re-fetch, which reuses the existing pull-sync code.
	 */
	public function receive( WP_REST_Request $request ) {
		$verified = $this->verify_signature( $request );
		if ( is_wp_error( $verified ) ) {
			return $verified;
		}

		$payload = json_decode( (string) $request->get_body(), true );
		if ( ! is_array( $payload ) || empty( $payload['event'] ) ) {
			return new WP_Error( 'wpd_webhook_bad_payload', __( 'Malformed webhook payload.', 'wp-dansal' ), array( 'status' => 400 ) );
		}

		if ( 'ping' === $payload['event'] ) {
			return new WP_REST_Response( array( 'ok' => true ), 202 );
		}

		$resource    = isset( $payload['resource'] ) ? (string) $payload['resource'] : '';
		$resource_id = isset( $payload['resource_id'] ) ? absint( $payload['resource_id'] ) : 0;
		$action      = isset( $payload['action'] ) ? (string) $payload['action'] : '';

		// Only events are wired up for #141 v1 (per dansal #1370's own scope,
		// the producer only fires on event.* today anyway) — acknowledge
		// anything else so a future resource type never 4xxs an otherwise
		// validly-signed delivery.
		if ( 'event' !== $resource || ! $resource_id ) {
			return new WP_REST_Response( array( 'ok' => true ), 202 );
		}

		// A delete has nothing left to re-fetch. No code path anywhere in
		// this plugin deletes a local post based on dansal-side state (the
		// #140 cron pull doesn't either) — #141 doesn't introduce one, just
		// acknowledges.
		if ( 'delete' === $action ) {
			return new WP_REST_Response( array( 'ok' => true ), 202 );
		}

		$result = $this->cpt_event->pull_event_by_dansal_id( $resource_id );
		// Whatever happened locally, dansal only needs "delivery received" —
		// a fetch failure here (e.g. the event isn't visible to us right now)
		// isn't something a dansal-side retry would fix, and the #140 cron
		// pull is the gap-recovery fallback regardless of this response.
		return new WP_REST_Response(
			array(
				'ok'     => ! is_wp_error( $result ),
				'result' => is_wp_error( $result ) ? $result->get_error_code() : $result,
			),
			202
		);
	}

	/**
	 * Mirrors WPD_Api_Client::apply_signing_headers() exactly, verifying
	 * instead of generating: same canonical payload (`METHOD` / `path?query`
	 * / timestamp / `sha256(body)` hex / nonce), same HMAC-SHA256 keyed with
	 * the stored signing secret. Checks in dansal's own documented order:
	 * headers present -> timestamp within skew -> nonce unseen -> signature
	 * matches (constant-time compare).
	 *
	 * @return true|WP_Error
	 */
	private function verify_signature( WP_REST_Request $request ) {
		$ts        = $request->get_header( 'x-wpd-timestamp' );
		$nonce     = $request->get_header( 'x-wpd-nonce' );
		$signature = $request->get_header( 'x-wpd-signature' );
		if ( ! $ts || ! $nonce || ! $signature ) {
			return new WP_Error( 'wpd_webhook_missing_headers', __( 'Missing signature headers.', 'wp-dansal' ), array( 'status' => 401 ) );
		}
		if ( ! ctype_digit( (string) $ts ) ) {
			return new WP_Error( 'wpd_webhook_bad_timestamp', __( 'Malformed timestamp.', 'wp-dansal' ), array( 'status' => 400 ) );
		}
		// Mirrors dansal's own server.signing.max_skew_seconds default.
		$max_skew = (int) apply_filters( 'wpd_webhook_max_skew_seconds', 300 );
		if ( abs( time() - (int) $ts ) > $max_skew ) {
			return new WP_Error( 'wpd_webhook_stale', __( 'Timestamp outside the allowed skew window.', 'wp-dansal' ), array( 'status' => 401 ) );
		}
		if ( ! preg_match( '/^[0-9a-f]{32}$/', (string) $nonce ) ) {
			return new WP_Error( 'wpd_webhook_bad_nonce', __( 'Malformed nonce.', 'wp-dansal' ), array( 'status' => 400 ) );
		}
		$nonce_key = self::NONCE_TRANSIENT_PREFIX . $nonce;
		if ( false !== get_transient( $nonce_key ) ) {
			return new WP_Error( 'wpd_webhook_replay', __( 'This delivery has already been processed.', 'wp-dansal' ), array( 'status' => 401 ) );
		}

		$secret = $this->settings->get_signing_secret();
		if ( '' === $secret ) {
			return new WP_Error( 'wpd_webhook_not_configured', __( 'No signing secret configured.', 'wp-dansal' ), array( 'status' => 401 ) );
		}

		$body_sha = hash( 'sha256', (string) $request->get_body() );
		$payload  = 'POST' . "\n" . $this->canonical_request_path() . "\n" . $ts . "\n" . $body_sha . "\n" . $nonce;
		$expected = hash_hmac( 'sha256', $payload, $secret );

		if ( ! hash_equals( $expected, (string) $signature ) ) {
			return new WP_Error( 'wpd_webhook_bad_signature', __( 'Signature mismatch.', 'wp-dansal' ), array( 'status' => 401 ) );
		}

		// Recorded only now that the signature is confirmed genuine, so a
		// forged request with a guessed/reused nonce can't burn a real one.
		set_transient( $nonce_key, 1, $max_skew );
		return true;
	}

	/**
	 * The literal request-target path+query as dansal actually sent it — not
	 * WP_REST_Request::get_route(), which is the matched *route pattern*, not
	 * the request URI. dansal signs over whatever URL we gave it at
	 * registration (e.g. `/?rest_route=/wpd/v1/webhook` on a site without
	 * pretty permalinks), so verification must reconstruct from the same raw
	 * request line, canonicalized the same way (ksort + RFC3986
	 * http_build_query) WPD_Api_Client::apply_signing_headers() builds it for
	 * outbound requests. The two must stay in sync with dansal's algorithm.
	 */
	private function canonical_request_path() {
		$uri   = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
		$parts = wp_parse_url( $uri );
		$path  = isset( $parts['path'] ) ? $parts['path'] : '';
		if ( empty( $parts['query'] ) ) {
			return $path;
		}
		parse_str( $parts['query'], $query );
		ksort( $query );
		return $path . '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
	}
}
