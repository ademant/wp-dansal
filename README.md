# wp-dansal

WordPress plugin for managing dance events and locations, backed by [dansal](https://github.com/ademant/dansal) as the storage/publishing backend.

## Connecting to dansal

1. In dansal, open `/admin/users`, click **Connect link** next to your organization's publisher row (or create one).
2. In WordPress, go to **Settings → Dansal**, paste the one-time link under "Connect via Link", and click **Connect** — this fills in the base URL, organization, and API key automatically. Use "Test Connection" to confirm.
   - Alternatively, expand "Manual connection (advanced)" and enter a base URL/org ID/API key you already have from `dansal_admin` or `POST /api/v1/publishers`.

## Event and Location Management

The plugin adds **Dance Locations** and **Dance Events** as custom post types, editable in the normal WordPress admin.

When creating a location, the plugin searches OpenStreetMap (Nominatim) for the address, then checks dansal for an existing location (by OSM id, then by proximity) before creating a duplicate — offering to assign your organization to the existing one instead. The same search widget stays available after the location is synced too (collapsed by default behind a "Re-match location" toggle), alongside a small draggable-marker map and "search from fields"/"reverse geocode" actions.

Saving an event or location automatically syncs it to dansal (create on first save, update on every save after), using a publisher API key scoped to one organization.

You can display events using the `[dansal_events]` shortcode: upcoming events as a list or a monthly calendar (`view="list"` or `view="calendar"`, plus `location`, `tag`, `limit`, `show_past` attributes). The `[dansal_locations]` shortcode renders a directory of locations with a self-hosted Leaflet map. Single templates are available for individual events and locations.

`[dansal_events]` can also blend in events from *other* organizations/cities on the same dansal instance instead of just this site's own synced events, via `org="slug1,slug2"`, `country="de,fr"`, `bbox="minLng,minLat,maxLng,maxLat"`, `lat`/`lon`/`radius_km`, and `exclude_own_org="1"`. These are fetched live from dansal's public `GET /api/v1/events` (not synced as local posts) and rendered with the same list/calendar markup; events/locations/orgs that have no page on this WordPress site link out to dansal-web instead, using the optional **Dansal Web URL** setting (falls back to the API base URL).

## Theme template overrides

Any of the plugin's templates can be overridden by copying it into your
(child) theme under a `dansal/` subdirectory. `locate_template()` picks
the child theme's copy first, then the parent theme's, then falls back
to the plugin's default:

- `dansal/single-dansal_event.php`
- `dansal/single-dansal_location.php`
- `dansal/archive-dansal_event.php`
- `dansal/archive-dansal_location.php`
- `dansal/page-dansal-locations.php`
- `dansal/page-dansal-calendar.php`

## Placing the map/calendar on a Page

Instead of the automatic archive URLs, you can put the locations map or the events calendar on any Page: **Pages → Add New → Page Attributes → Template**, then pick **Dansal: Locations Map** or **Dansal: Events Calendar**. The Page's own title and content render normally; the map/calendar is appended below.

## Translations

Translatable strings live under the `wp-dansal` text domain. Translators can start from `languages/wp-dansal.pot` and drop `.po`/`.mo` files next to it (`languages/wp-dansal-{locale}.po`), or place them under `wp-content/languages/plugins/`. Regenerate the POT with `make pot` (requires [wp-cli](https://wp-cli.org/)).

## Local development

`make zip` builds a release zip in `dist/`. To iterate against a local WordPress install instead, copy `.env.example` to `.env`, set `WP_PLUGIN_DIR` (that install's `wp-content/plugins`) and `WP_OWNER` (the `user:group` the webserver runs as, e.g. `www-data:www-data`), then run `make deploy` — it rsyncs the built plugin into `$WP_PLUGIN_DIR/wp-dansal/` and chowns it to `WP_OWNER`. `.env` is git-ignored. `make help` lists all targets.

## Notes

- Map tiles are loaded live from OpenStreetMap's tile server (the Leaflet library itself is bundled, but tiles can't practically be self-hosted). Every tile request goes with `Referrer-Policy: origin` (only the scheme+host of the page, not its full path/query) — OSM's tile servers require *some* referer and block requests that send none. To route tiles through a self-hosted or paid proxy instead, filter `wpd_tile_url_template` (and, if needed, `wpd_tile_attribution` / `wpd_tile_max_zoom` / `wpd_tile_referrer_policy`):

  ```php
  add_filter( 'wpd_tile_url_template', function () {
      return 'https://tiles.example.com/{z}/{x}/{y}.png';
  } );
  ```
- dansal's `API.md` documents `PATCH /api/v1/events/{id}` for updates, but the server currently only registers `PUT /api/v1/events/{id}` — this plugin calls `PUT`. Worth reconciling in dansal's docs/routes at some point.
