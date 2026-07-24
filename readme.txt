=== WP Dansal ===
Contributors: ademant
Tags: events, calendar, dance, locations, dansal
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.11.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Manage dance events and locations in WordPress, backed by a dansal server as the storage and publishing backend.

== Description ==

WP Dansal turns WordPress into an editing frontend for the [dansal](https://github.com/ademant/dansal) events backend. It ships two custom post types — **Dance Locations** and **Dance Events** — plus an optional **Dance Series** for recurring programs, and syncs each save to dansal so the same event data is available to any dansal consumer.

**Features**

* Dance Locations and Dance Events CPTs edited from the normal WordPress admin.
* Creating a location searches OpenStreetMap (Nominatim) for the address, then checks dansal for an existing location (by OSM id, then by proximity) before creating a duplicate. The same search widget, plus a small draggable-marker map and a reverse-geocode action, is also available when editing an already-synced location.
* Event and location edit screens group their fields into collapsible sections so editors aren't scrolling past fields they rarely touch.
* Saving an event or location syncs it to dansal (create on first save, update thereafter), using a publisher API key scoped to one organization.
* Event pricing supports dansal's full model — free, donation, a single price, or a growable table of named tiers (e.g. "Normal"/"Reduced"/"Presale"), all sharing one currency.
* Events can carry a multi-slot timetable (e.g. a workshop followed by a ball), edited as a growable Start/End/Title/Type table and synced via dansal's dedicated timetable endpoint.
* Events can carry an image via WordPress's native Featured Image, synced to dansal's image upload endpoint and shown on the single-event page.
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

= Can I translate the plugin to my language? =

Yes! The plugin is fully translation-ready with the `wp-dansal` text domain. Translation files are available for German (de_DE), French (fr_FR), Spanish (es_ES), Czech (cs_CZ), and Polish (pl_PL). To contribute translations: edit the `.po` file for your locale and compile it to `.mo`, or use a tool like Poedit. Files can be placed in the plugin's `languages/` directory or in `wp-content/languages/plugins/` to persist across updates.

== Changelog ==

= 0.11.0 =
* Events can carry an image, sourced from WordPress's native Featured Image on the event edit screen. Synced to dansal via its dedicated image upload endpoint (create/replace on save, removed on dansal when the featured image is cleared); single-event pages show it, preferring the local Featured Image and falling back to dansal's own copy for events pulled in without ever getting a local attachment (closes #101).

= 0.10.0 =
* New shortcode `[dansal_festivals]` — cross-org festival browser (map + list). Groups editions by `location_id + style_bucket` so yearly editions (e.g. "Danserla 2026"/"2027") collapse to one row/pin showing the latest edition, while a balfolk festival and a tango festival at the same venue stay distinct. Attributes: `view` (`list`|`map`|`map+list`, default `map+list`), `limit` (1..200, default 50), `show_past` (default 1), plus `tag`/`org`/`country`/`bbox`/`lat`/`lon`/`radius_km`. Style buckets are derived from event tags via a built-in synonym map (balfolk ← bal-folk/fest-noz, tango ← tango-argentino/tango-nuevo, swing ← lindy-hop/balboa, salsa ← bachata/kizomba); untagged events get a per-event bucket so they never wrongly merge. Filter hook `wpd_festival_style_bucket` for custom mappings (closes #100).

= 0.9.0 =
* Mini calendar widget: prev/next month arrows now swap the grid in place via AJAX instead of reloading the whole archive page. Hrefs are preserved as a JS-off fallback (closes #98).
* New shortcode `[dansal_nearby]` — a proximity-scoped list/map of dansal events. Attributes: `radius_km` (default 50, bounded 1..500), `lat`/`lon` (optional override), `view` (`list`|`map`|`map+list`, default `map+list`), plus `limit`/`tag`/`type`/`exclude_own_org`. If the visitor's browser grants geolocation, the widget re-fetches around them; otherwise it falls back to the site-configured Home location.
* Settings → Dansal gains a **Home location** section: address + latitude/longitude, with a Nominatim "Search" button that populates coordinates from the picked result. Auto-seeded on activation from the most recently modified `dansal_location` post carrying coordinates (guarded by a one-shot `home_seeded` flag). Values are **not** synced to dansal — they live only in WordPress.
* Remote-event map: `[dansal_events]` map / map+list views now work for remote (org/country/bbox/proximity) queries too, feeding the same Leaflet markup as local queries (closes #99).

= 0.8.2 =
* Fix: 0.8.1's location backfill only ran on newly-imported locations, so upgrades from an older version left previously-synced events without a location link. Backfill now runs for existing location posts too — visiting Dance → Locations once after the upgrade heals affected installs.

= 0.8.1 =
* `[dansal_events]` gains a `type` attribute filtering the boolean event-type flags (`ball`, `workshop`, `festival`, `other`) — the existing `tag` attribute only ever matched the free-tags meta.
* `[dansal_events view="simple"]` gains `show_types="1"`, prepending a colored dot per active event type (reusing the mini calendar palette).
* New classic widget **Dansal Upcoming Events**: compact one-line-per-event sidebar list, with title/limit/tag/event-types/show-icons fields.
* Settings → Dansal gains a **Disconnect** button that first probes the dansal server (OPTIONS /api/v1/apikeys/current) and, if supported, revokes the publisher key server-side before wiping local credentials; otherwise clears locally and hints that the key must be deleted by hand. `uninstall.php` does the same best-effort DELETE before dropping wpd_settings.
* Fix: after a fresh connect-link, visiting Dance → Events before Dance → Locations left events with an empty location — `pull_one_location()` now backfills `_wpd_location_post_id` for events that were pulled before their location existed.

= 0.8.0 =
* `[dansal_locations]` gains `tag`, `country`, and `location` attributes for filtering the directory (tag filter subqueries events → locations, since only events carry tags).
* `[dansal_events]` gains `view="map"` (Leaflet map of upcoming events grouped by location), `view="map+list"` (map above the list, reusing one query), plus `view="simple"` and `view="map+simple"` for a compact one-line-per-event layout — date · title · venue in aligned columns (CSS grid + tabular-nums).
* Map popups now list every event at that location (title + start time) instead of just the venue link, and open in the same tab.
* i18n: weekday headers in the calendar and mini calendar now use `$wp_locale`, so they follow the WordPress site language. Frontend single-event / single-location templates now render `food` / `drink` / `parking` / `floor_condition` via `WPD_Vocab::label()` — users see the translated label ("Parkett") instead of the raw slug ("parquet"). All existing translations for de/fr/es/cs/pl remain valid.

= 0.7.0 =
* Events can now have a timetable — a multi-slot schedule (e.g. a workshop followed by a ball), edited as a growable Start/End/Title/Type table on the event edit screen (and shared with the series edit screen and the settings page's "Event defaults" section, same as pricing tiers). Synced via dansal's dedicated `PUT /api/v1/events/{id}/timetable` endpoint rather than the plain event save, since dansal doesn't expose it there. Description/room/location/musician per entry aren't editable from WordPress yet, but are preserved rather than wiped if set via dansal-web.

= 0.6.2 =
* Fix: the 0.6.1 attempt at fixing the bulk "Assign to series…" redirect wasn't enough — `wp-admin/edit.php` re-processes whatever URL the bulk-action handler returns with its own `add_query_arg()` call (appending its own `ids` param), which re-parses and re-serializes the whole query string, silently dropping the array-valued `post` param again regardless of how it was originally encoded. The redirect now carries a single-use transient token instead of the post IDs directly, so nothing array-valued ever travels through a redirect URL.

= 0.6.1 =
* Fix: the new bulk "Assign to series…" action (0.6.0) landed on an "Not a dansal event" error instead of the picker — the redirect it builds carried the selected event IDs as an array-valued query arg via `add_query_arg()`, which wasn't reliably round-tripping through the redirect. Rebuilt as an explicit query string for the redirect and hidden form fields for the actual submission, sidestepping the ambiguity entirely.

= 0.6.0 =
* Dance Events list screen: "Assign to series…" is now also available as a bulk action, not just a per-row link — select several events, choose it from the bulk actions dropdown, and pick one series to apply to all of them (or "— no change —" to leave some of them alone).

= 0.5.1 =
* Fix: picking a location on a new/existing Dance Series never populated its Room dropdown, even when the location had rooms — the location→room refresh logic only ever ran on the event edit screen (`admin-event.js`), never on the series edit screen even though it renders the same Location/Room fields. Extracted into a shared `admin-rooms.js`, now loaded on both.

= 0.5.0 =
* Event pricing type vocabulary now matches dansal's own (`free`/`donation`/`single`/`multiple` instead of the old `free`/`fixed`/`donation`), and `type="multiple"` gets a growable Label/Amount tiers table — same shape as dansal-web's own pricing table (`prices: [{label, amount}]`), sharing one currency.
* Fixes a data-loss bug: because the plugin always pushed a flat `{type, amount, currency}` pricing object and dansal replaces the whole `pricing` field on save rather than deep-merging it, saving *any* event from WordPress previously wiped out `multiple`-tier pricing set via dansal-web or the API directly. Pricing tiers now round-trip through WordPress correctly.

= 0.4.0 =
* Location edit screen: the Nominatim search widget is now available even after a location is synced (previously it disappeared once matched), defaulting to collapsed behind a "Re-match location" toggle once an OSM match already exists. Added a small draggable-marker map (drag or click to fine-tune coordinates) and "search from fields below" / "reverse geocode from lat/long" actions, both proxied server-side like the existing search.
* Event and location edit screens now group their fields into collapsible `<details>` sections (When & where, Classification, Pricing & booking, Amenities & floor, People / Address & geocoding, Details, Rooms) instead of one long flat table.

= 0.3.3 =
* Removed the free-text "Workshop difficulty" event field — dansal only accepts a fixed enum for it, so free text could be rejected on sync. The dansal tags vocabulary's "Level" category (Beginners/Intermediate/Advanced) already covered the same purpose and is now labelled "Workshop difficulty" on the event edit screen instead.

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
