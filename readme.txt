=== WP Dansal ===
Contributors: ademant
Tags: events, calendar, dance, locations, dansal
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.3.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Manage dance events and locations in WordPress, backed by a dansal server as the storage and publishing backend.

== Description ==

WP Dansal turns WordPress into an editing frontend for the [dansal](https://github.com/ademant/dansal) events backend. It ships two custom post types — **Dance Locations** and **Dance Events** — plus an optional **Dance Series** for recurring programs, and syncs each save to dansal so the same event data is available to any dansal consumer.

**Features**

* Dance Locations and Dance Events CPTs edited from the normal WordPress admin.
* Creating a location searches OpenStreetMap (Nominatim) for the address, then checks dansal for an existing location (by OSM id, then by proximity) before creating a duplicate.
* Saving an event or location syncs it to dansal (create on first save, update thereafter), using a publisher API key scoped to one organization.
* `[dansal_events]` shortcode for upcoming events as a list or a monthly calendar, with `location`, `tag`, `limit`, `view`, `show_past` attributes.
* `[dansal_events]` can also surface events from *other* organizations/cities on the same dansal instance — `org`, `country`, `bbox`, `lat`/`lon`/`radius_km`, `exclude_own_org` — fetched live from dansal's public REST API and rendered in the same list/calendar templates as local events.
* `[dansal_locations]` shortcode for a directory of locations with a self-hosted Leaflet map.
* Single and archive templates for events and locations, with theme override support at `{theme}/dansal/`.
* Filter hooks for OSM tile source (`wpd_tile_url_template`), HTTP timeouts (`wpd_http_timeout`), and content wipe on uninstall (`wpd_uninstall_delete_content`).

== Installation ==

1. Download the latest release zip: https://github.com/ademant/wp-dansal/releases/latest/download/wp-dansal.zip
2. In WordPress: **Plugins → Add New → Upload Plugin**, choose the zip, and install (or extract into `/wp-content/plugins/`).
3. Activate **WP Dansal** in the Plugins menu.
4. In dansal, open `/admin/users`, click **Connect link** next to your organization's publisher row (or create one).
5. In WordPress, go to **Settings → Dansal**, paste the one-time link under "Connect via Link", and click **Connect**.
6. Create locations under **Dance → Locations**, then events under **Dance → Events**.

== Frequently Asked Questions ==

= Do I need a dansal server? =

Yes. This plugin is an editing frontend for [dansal](https://github.com/ademant/dansal); it does not store event data on its own.

= Can I use my own map tiles instead of OpenStreetMap? =

Yes — filter `wpd_tile_url_template` to point at a self-hosted or paid tile proxy. The default `Referrer-Policy` for tile requests is `origin`, sending only the page's scheme+host to the tile host, not its full path/query.

= Can I override the plugin templates from my theme? =

Yes. Place any of `single-dansal_event.php`, `single-dansal_location.php`, `archive-dansal_event.php`, `archive-dansal_location.php` under `{theme}/dansal/` and the plugin will pick it up via `locate_template()`.

= Does uninstalling the plugin delete my events? =

No. Uninstalling removes plugin settings and caches only. To also wipe event/location/series posts on uninstall, add `add_filter( 'wpd_uninstall_delete_content', '__return_true' );` in a mu-plugin before deleting the plugin.

== Changelog ==

= 0.3.2 =
* Single event page now lays out its details as a table, with a small linked-location map to its right (falls below the table on narrow screens) instead of a full-width map under the "Where" line.

= 0.3.1 =
* Fix: frontend map tiles failed to load ("blocked due to missing referer") because tile requests used `Referrer-Policy: no-referrer`. OSM's tile servers require a referer and reject requests with none; switched the default to `origin`, which still only exposes the page's scheme+host, not its full path/query.

= 0.3.0 =
* `[dansal_events]` shortcode can now show events from other organizations/cities on the same dansal instance, fetched live via `GET /api/v1/events` instead of the locally synced `dansal_event` posts: `org="slug1,slug2"` (resolved against `GET /api/v1/organizations`, cached), `country="de,fr"`, `bbox="minLng,minLat,maxLng,maxLat"`, `lat`/`lon`/`radius_km` proximity search, and `exclude_own_org="1"`. Remote results reuse the existing list/calendar templates and link out to dansal-web for events/orgs/locations that have no page on this WordPress site.
* New optional **Dansal Web URL** setting (falls back to the API base URL) used to build those outbound dansal-web links.

= 0.2.0 =
* Rooms: named sub-locations (e.g. "Grand Hall", "Studio 2") within a venue. Managed from the location edit screen (add/remove); events can be assigned to a specific room from the event edit screen, and the room name shows on the single-event page. Backed by dansal's `/api/v1/locations/{id}/rooms` endpoints and `Event.room_id`.
* Clearing an event's location now issues `DELETE /api/v1/events/{id}/location` instead of a PATCH — merge-patch can't clear a nullable `*int` reference, so the previous behavior silently left the old location on dansal.

= 0.1.11 =
* Release job now publishes an unversioned `wp-dansal.zip` alias next to the versioned zip, so the readme can link to `.../releases/latest/download/wp-dansal.zip` as a stable direct-download URL.

= 0.1.10 =
* Event edit screen auto-fills the end datetime with start + 2h when end is still empty; existing end values are never touched. Duration filterable via `wpd_default_event_duration` (seconds).
* Single event page now shows an OpenStreetMap inlay from the linked location's coordinates (same Leaflet component the single-location page uses). Assets only enqueue when the map can actually render, so events without coordinates stay lean.

= 0.1.8 =
* Connect-link redemption now requires HTTPS (filter `wpd_allow_insecure_connect_url` to opt out for local dev), sends a random challenge dansal must echo back (verified with `hash_equals`), and generates an ephemeral RSA-2048 keypair so dansal returns the API key encrypted rather than in plaintext.
* After redemption the plugin immediately exchanges the returned key for a session token; if that fails the previous settings are restored so a broken key never silently poisons later requests.
* Event description sync strips inline `color` / `background` / `background-color` styles from dansal HTML so descriptions written against dansal's dark theme stay readable in WordPress's white editor. Layout styles are preserved.

= 0.1.7 =
* New `dansal_musician` and `dansal_instructor` post types for editing musician / instructor details in WordPress; only entities the admin promotes get a local WP post, others stay dansal-only. On save, WP → dansal via `PATCH` merge-patch so fields the plugin doesn't surface (biography, wikidata_id, discogs_id, website, email, images) survive.
* Musicians and locations follow dansal-priority sync (dansal overwrites silently).
* Events instead surface a "dansal has newer changes" banner on the edit screen with Accept / Ignore actions — WP is the primary authoring surface, drift must be explicit.
* New pencil affordance on each musician / instructor chip on the event edit screen creates a local WP copy and returns an edit link.

= 0.1.6 =
* Musician / instructor picker on the event edit screen can now create a new dansal entity inline when a typed name has no match; two-step click-to-confirm prevents accidental duplicates from typos.

= 0.1.5 =
* Publisher API keys with a bounded expiry now auto-renew via `POST /api/v1/apikeys/renew` on a daily WP-Cron tick when their stored expiry is within the lead-time window (default 7 days, filter `wpd_apikey_renew_leadtime`).
* Persistent admin notice on the settings page when the key has already expired and needs a manual reconnect.

= 0.1.4 =
* Event food / drink / floor-condition and location parking / floor-condition are now `<select>` dropdowns matching dansal's closed vocabularies, sanitized against the vocab on save so an off-vocab value can no longer silently persist.
* Vocabulary slugs are fetched from dansal's public `/api/v1/vocabulary` endpoint (cached for 1 hour) so a slug dansal adds later doesn't require a plugin release; the hardcoded fallback shipped with each version keeps the edit screen working when dansal is unreachable.
* `wpd_{food,drink,floor_condition,parking}_options` filters let a site extend or reorder the option labels without editing the plugin.

= 0.1.3 =
* Events update via `PATCH` (RFC 7396 merge-patch) instead of `PUT`, so fields the plugin doesn't manage (timetable, images, pricing tiers added via the dansal admin) survive a WP save.
* Retry once on `429 Too Many Requests` / `503 Service Unavailable`, honoring the `Retry-After` header (clamped to 30s); previously any non-2xx surfaced as an immediate failure.
* Validation errors from dansal now show the field name in the admin notice (`food: invalid value` instead of just `invalid value`).

= 0.1.2 =
* Public-side robustness: frontend refresh only runs on published posts and is capped by a 5-second global fan-out lock; shortcode attributes bounded before hitting WP_Query.
* Themes can override plugin templates via `{theme}/dansal/…` (WooCommerce-style `locate_template` resolution).
* Selectable page templates "Dansal: Locations Map" and "Dansal: Events Calendar" for placing the map/calendar on any Page.
* CSP-friendly frontend: no inline `<script>` blocks; map data on `data-` attributes; Leaflet popups built via DOM (`textContent`).
* OSM tile privacy: `Referrer-Policy: no-referrer` default and `wpd_tile_url_template` filter for self-hosted tile proxies.
* `uninstall.php` cleans up plugin options and transients; opt-in content wipe via `wpd_uninstall_delete_content`.
* `filemtime()` cache-busting on plugin assets under `WP_DEBUG`.
* `readme.txt` in wordpress.org header format; `languages/wp-dansal.pot` header + `make pot` target.
* CI: `wp plugin-check` job and PHPUnit + wp-phpunit test suite.
* Dansal descriptions filtered through `wp_kses_post` on pull-sync; classic-editor design choice documented on all three CPT registrations.

= 0.1.1 =
* Hardening and cleanup pass across settings, admin notices, HTTP timeouts, transient keys, and shortcode attribute validation.
* Frontend refresh now only runs on published posts and is capped by a global fan-out lock.
* Theme template overrides via `{theme}/dansal/…`.
* CSP-friendly map: point/tile data moved from inline `<script>` blocks to data-attributes; Leaflet popups built via DOM.
* OSM tile privacy: `Referrer-Policy: no-referrer` by default, `wpd_tile_url_template` filter for self-hosted proxies.
* `wpd_http_timeout` filter routes every outbound HTTP call through one knob.
* `uninstall.php` cleans up options and transients; opt-in content wipe.
* `filemtime()` cache-busting under `WP_DEBUG`.
* Shipped `languages/wp-dansal.pot` and `make pot` target.

= 0.1.0 =
* Initial release: Dance Locations and Dance Events CPTs, dansal sync, Nominatim-backed location dedup, event templates and series, list/calendar shortcodes, single/archive templates, self-hosted Leaflet map.
