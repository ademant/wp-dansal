=== WP Dansal ===
Contributors: ademant
Tags: events, calendar, dance, locations, dansal
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 0.1.1
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
* `[dansal_locations]` shortcode for a directory of locations with a self-hosted Leaflet map.
* Single and archive templates for events and locations, with theme override support at `{theme}/dansal/`.
* Filter hooks for OSM tile source (`wpd_tile_url_template`), HTTP timeouts (`wpd_http_timeout`), and content wipe on uninstall (`wpd_uninstall_delete_content`).

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/` (or install via the WordPress admin).
2. Activate **WP Dansal** in the Plugins menu.
3. In dansal, open `/admin/users`, click **Connect link** next to your organization's publisher row (or create one).
4. In WordPress, go to **Settings → Dansal**, paste the one-time link under "Connect via Link", and click **Connect**.
5. Create locations under **Dance → Locations**, then events under **Dance → Events**.

== Frequently Asked Questions ==

= Do I need a dansal server? =

Yes. This plugin is an editing frontend for [dansal](https://github.com/ademant/dansal); it does not store event data on its own.

= Can I use my own map tiles instead of OpenStreetMap? =

Yes — filter `wpd_tile_url_template` to point at a self-hosted or paid tile proxy. The default `Referrer-Policy` for tile requests is `no-referrer` so the page URL doesn't leak to the tile host.

= Can I override the plugin templates from my theme? =

Yes. Place any of `single-dansal_event.php`, `single-dansal_location.php`, `archive-dansal_event.php`, `archive-dansal_location.php` under `{theme}/dansal/` and the plugin will pick it up via `locate_template()`.

= Does uninstalling the plugin delete my events? =

No. Uninstalling removes plugin settings and caches only. To also wipe event/location/series posts on uninstall, add `add_filter( 'wpd_uninstall_delete_content', '__return_true' );` in a mu-plugin before deleting the plugin.

== Changelog ==

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
