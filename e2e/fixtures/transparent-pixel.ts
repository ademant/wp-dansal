// A 1x1 transparent PNG, used to stub Leaflet tile requests in tests so the
// map's tile layer doesn't depend on real network access to a tile server —
// the smoke tests care about marker/field state, not rendered tile pixels.
export const TRANSPARENT_PNG = Buffer.from(
	'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
	'base64'
);
