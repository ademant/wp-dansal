<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Live REST lookups against dansal for events that are NOT synced as local
 * dansal_event CPTs — i.e. events belonging to other organizations/cities on
 * the same dansal instance. Unlike the CPT sync (WPD_CPT_Event), nothing here
 * is persisted as WordPress content; results are only ever rendered inline by
 * WPD_Frontend's [dansal_events] remote-query branch and short-lived
 * transient-cached to keep repeat page loads cheap.
 */
class WPD_Remote_Events {

	const ORG_MAP_TRANSIENT = 'wpd_dansal_org_map';
	const EVENTS_CACHE_PREFIX = 'wpd_revt_';

	// The dansal API's organization_id filter accepts one value, so an
	// org="a,b,c" shortcode attribute is resolved into one request per org
	// and merged client-side. Capped so a copy-pasted long slug list can't
	// turn one page load into dozens of outbound requests.
	const MAX_ORG_FILTER = 10;

	/** @var WPD_Settings */
	private $settings;

	/** @var WPD_Api_Client */
	private $api;

	public function __construct( WPD_Settings $settings, WPD_Api_Client $api ) {
		$this->settings = $settings;
		$this->api      = $api;
	}

	/**
	 * Org id <-> slug lookup table, built from GET /api/v1/organizations
	 * (public, unauthenticated) and cached. The slug matches dansal-web's
	 * effectiveSlug(): an org's actor_name when set, else a name-derived
	 * slug — so shortcode authors can use the same slug they see in dansal
	 * URLs even for orgs that never set a custom actor_name.
	 *
	 * @return array{by_id: array<int,array{name:string,slug:string}>, by_slug: array<string,int>}
	 */
	public function org_map() {
		$cached = get_transient( self::ORG_MAP_TRANSIENT );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$map = array(
			'by_id'   => array(),
			'by_slug' => array(),
		);

		// One page, capped at the API's own max — good enough for the
		// directory sizes this plugin is built for; a busier instance can
		// still be targeted by numeric-id-less org= slugs that happen to
		// fall outside the first 1000, they just won't resolve.
		$orgs = $this->api->get_public( '/api/v1/organizations', array( 'limit' => 1000 ) );
		if ( ! is_wp_error( $orgs ) && is_array( $orgs ) ) {
			foreach ( $orgs as $org ) {
				if ( empty( $org['id'] ) ) {
					continue;
				}
				$id    = (int) $org['id'];
				$name  = isset( $org['name'] ) ? (string) $org['name'] : '';
				$actor = ! empty( $org['actor_name'] ) ? (string) $org['actor_name'] : sanitize_title( $name );

				$map['by_id'][ $id ]              = array(
					'name' => $name,
					'slug' => $actor,
				);
				$map['by_slug'][ strtolower( $actor ) ] = $id;
			}
		}

		$ttl = (int) apply_filters( 'wpd_org_map_ttl', HOUR_IN_SECONDS );
		set_transient( self::ORG_MAP_TRANSIENT, $map, max( 60, $ttl ) );
		return $map;
	}

	/**
	 * @param string[] $slugs Already-split, not-yet-sanitized slug strings.
	 * @return int[] Resolved organization ids; unknown slugs are dropped
	 *               silently rather than erroring, matching shortcode_atts'
	 *               "bad input degrades, doesn't fatal" convention.
	 */
	public function resolve_org_ids( array $slugs ) {
		if ( empty( $slugs ) ) {
			return array();
		}
		$map = $this->org_map();
		$ids = array();
		foreach ( array_slice( $slugs, 0, self::MAX_ORG_FILTER ) as $slug ) {
			$slug = strtolower( sanitize_title( $slug ) );
			if ( '' !== $slug && isset( $map['by_slug'][ $slug ] ) ) {
				$ids[] = $map['by_slug'][ $slug ];
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * @return array{name:string,slug:string}|null
	 */
	public function org_info( $org_id ) {
		$map = $this->org_map();
		$org_id = (int) $org_id;
		return isset( $map['by_id'][ $org_id ] ) ? $map['by_id'][ $org_id ] : null;
	}

	/**
	 * Single GET /api/v1/events call, short-cached so several visitors
	 * loading the same page within the TTL only cost dansal one request.
	 *
	 * @return array|WP_Error List of event arrays (dansal's bare JSON array
	 *                        response), or WP_Error on transport/HTTP failure.
	 */
	public function fetch_events( array $query, $cache_ttl = 300 ) {
		ksort( $query );
		$cache_key = self::EVENTS_CACHE_PREFIX . md5( wp_json_encode( $query ) );

		$cached = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$result = $this->api->get_public( '/api/v1/events', $query );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$events = is_array( $result ) ? $result : array();
		$ttl    = (int) apply_filters( 'wpd_remote_events_ttl', $cache_ttl );
		set_transient( $cache_key, $events, max( 30, $ttl ) );
		return $events;
	}

	/**
	 * Fetch events across several organizations and merge into one
	 * start_time-ordered, deduped, limit-capped list. One org's request
	 * failing doesn't blank the others — it's just left out.
	 *
	 * @param int[]  $org_ids
	 * @param array  $base_query Query params shared by every per-org request
	 *                           (country/bbox/tag/include_past/etc — no
	 *                           organization_id or limit, those are set here).
	 * @param int    $limit      Max merged results returned.
	 * @return array
	 */
	public function fetch_events_for_orgs( array $org_ids, array $base_query, $limit ) {
		$limit  = max( 1, (int) $limit );
		$merged = array();
		$seen   = array();

		foreach ( array_slice( $org_ids, 0, self::MAX_ORG_FILTER ) as $org_id ) {
			$query                     = $base_query;
			$query['organization_id'] = (int) $org_id;
			$query['limit']           = $limit;

			$events = $this->fetch_events( $query );
			if ( is_wp_error( $events ) ) {
				continue;
			}
			foreach ( $events as $event ) {
				$id = isset( $event['id'] ) ? (int) $event['id'] : 0;
				if ( $id && isset( $seen[ $id ] ) ) {
					continue;
				}
				if ( $id ) {
					$seen[ $id ] = true;
				}
				$merged[] = $event;
			}
		}

		usort(
            $merged,
            function ( $a, $b ) {
				return strcmp( (string) ( $a['start_time'] ?? '' ), (string) ( $b['start_time'] ?? '' ) );
            }
        );

		return array_slice( $merged, 0, $limit );
	}
}
