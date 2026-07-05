# wp-dansal

WordPress plugin for managing dance events and locations, backed by [dansal](https://github.com/ademant/dansal) as the storage/publishing backend.

## What it does

- **Dance Locations** and **Dance Events** custom post types for editing in the normal WordPress admin.
- Creating a location searches OpenStreetMap (Nominatim) for the address, then checks dansal for an existing location (by OSM id, then by proximity) before creating a duplicate — offering to assign your organization to the existing one instead.
- Saving an event/location syncs it to dansal (create on first save, update on every save after), using a publisher API key scoped to one organization.
- `[dansal_events]` shortcode: upcoming events as a list or a monthly calendar (`view="list"` or `view="calendar"`, plus `location`, `tag`, `limit`, `show_past` attributes).
- `[dansal_locations]` shortcode: a directory of locations with a self-hosted Leaflet map.
- Single templates for individual events and locations.

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

## Setup

1. In dansal, open `/admin/users`, click **Connect link** next to your organization's publisher row (or create one).
2. In WordPress, go to **Settings → Dansal**, paste the one-time link under "Connect via Link", and click **Connect** — this fills in the base URL, organization, and API key automatically. Use "Test Connection" to confirm.
   - Alternatively, expand "Manual connection (advanced)" and enter a base URL/org ID/API key you already have from `dansal_admin` or `POST /api/v1/publishers`.
3. Create locations under **Dance Locations**, then events under **Dance Events**.

## Notes

- Map tiles are loaded live from OpenStreetMap's tile server (the Leaflet library itself is bundled, but tiles can't practically be self-hosted). Every tile request goes with `Referrer-Policy: no-referrer` so the page URL isn't shared with the tile host. To route tiles through a self-hosted or paid proxy instead, filter `wpd_tile_url_template` (and, if needed, `wpd_tile_attribution` / `wpd_tile_max_zoom` / `wpd_tile_referrer_policy`):

  ```php
  add_filter( 'wpd_tile_url_template', function () {
      return 'https://tiles.example.com/{z}/{x}/{y}.png';
  } );
  ```
- dansal's `API.md` documents `PATCH /api/v1/events/{id}` for updates, but the server currently only registers `PUT /api/v1/events/{id}` — this plugin calls `PUT`. Worth reconciling in dansal's docs/routes at some point.
