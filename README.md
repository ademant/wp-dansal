# wp-dansal

WordPress plugin for managing dance events and locations, backed by [dansal](https://github.com/ademant/dansal) as the storage/publishing backend.

## What it does

- **Dance Locations** and **Dance Events** custom post types for editing in the normal WordPress admin.
- Creating a location searches OpenStreetMap (Nominatim) for the address, then checks dansal for an existing location (by OSM id, then by proximity) before creating a duplicate — offering to assign your organization to the existing one instead.
- Saving an event/location syncs it to dansal (create on first save, update on every save after), using a publisher API key scoped to one organization.
- `[dansal_events]` shortcode: upcoming events as a list or a monthly calendar (`view="list"` or `view="calendar"`, plus `location`, `tag`, `limit`, `show_past` attributes).
- `[dansal_locations]` shortcode: a directory of locations with a self-hosted Leaflet map.
- Single templates for individual events and locations.

## Setup

1. In dansal, open `/admin/users`, click **Connect link** next to your organization's publisher row (or create one).
2. In WordPress, go to **Settings → Dansal**, paste the one-time link under "Connect via Link", and click **Connect** — this fills in the base URL, organization, and API key automatically. Use "Test Connection" to confirm.
   - Alternatively, expand "Manual connection (advanced)" and enter a base URL/org ID/API key you already have from `dansal_admin` or `POST /api/v1/publishers`.
3. Create locations under **Dance Locations**, then events under **Dance Events**.

## Notes

- Map tiles are loaded live from OpenStreetMap's tile server (the Leaflet library itself is bundled, but tiles can't practically be self-hosted).
- dansal's `API.md` documents `PATCH /api/v1/events/{id}` for updates, but the server currently only registers `PUT /api/v1/events/{id}` — this plugin calls `PUT`. Worth reconciling in dansal's docs/routes at some point.
