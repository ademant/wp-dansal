import { test, expect } from '@playwright/test';
import { TRANSPARENT_PNG } from '../fixtures/transparent-pixel';
import { dismissWelcomeGuide, ensureMetaBoxesExpanded } from '../helpers/post-editor';

// Smoke test for assets/js/admin-location.js — the jQuery-driven Nominatim
// search/map wiring that #126 part 2 wants to rewrite in vanilla JS. This
// mocks the WP REST routes the JS calls (nominatim/search, locations/
// duplicates) rather than hitting the real Nominatim service or a live
// dansal backend: the point is to pin the *client-side* behavior (selecting
// a result fills the fields and moves the marker) so a future rewrite can be
// checked against it, not to test Nominatim itself.
test.describe( 'Location editor — Nominatim search', () => {
	test.beforeEach( async ( { page } ) => {
		// Leaflet tile requests: stub them out so the map layer never depends
		// on real network access in CI.
		await page.route( '**/*.png', ( route ) =>
			route.fulfill( { status: 200, contentType: 'image/png', body: TRANSPARENT_PNG } )
		);
	} );

	test( 'selecting a search result fills the manual fields and moves the map marker', async ( { page } ) => {
		await page.route( '**/nominatim/search*', ( route ) =>
			route.fulfill( {
				json: [
					{
						display_name: 'Bürgerhaus Stollwerck, Dreikönigenstraße 23, Köln, Germany',
						name: 'Bürgerhaus Stollwerck',
						lat: 50.9275,
						lng: 6.9603,
						osm_id: 12345,
						osm_type: 'way',
						address: 'Dreikönigenstraße 23',
						town: 'Köln',
						zipcode: '50678',
						country: 'Germany',
						country_code: 'DE',
					},
				],
			} )
		);
		await page.route( '**/locations/duplicates*', ( route ) =>
			route.fulfill( { json: { matches: [] } } )
		);

		await page.goto( '/wp-admin/post-new.php?post_type=dansal_location' );
		await dismissWelcomeGuide( page );
		await ensureMetaBoxesExpanded( page );

		await page.fill( '#wpd-nominatim-q', 'Bürgerhaus Stollwerck' );
		await page.click( '#wpd-nominatim-search' );
		await page
			.getByRole( 'button', { name: 'Bürgerhaus Stollwerck, Dreikönigenstraße 23, Köln, Germany' } )
			.click();

		await expect( page.locator( '#wpd_short_name' ) ).toHaveValue( 'Bürgerhaus Stollwerck' );
		await expect( page.locator( '#wpd_address' ) ).toHaveValue( 'Dreikönigenstraße 23' );
		await expect( page.locator( '#wpd_town' ) ).toHaveValue( 'Köln' );
		await expect( page.locator( '#wpd_zipcode' ) ).toHaveValue( '50678' );
		await expect( page.locator( '#wpd_country_code' ) ).toHaveValue( 'DE' );
		await expect( page.locator( '#wpd_latitude' ) ).toHaveValue( '50.9275' );
		await expect( page.locator( '#wpd_longitude' ) ).toHaveValue( '6.9603' );

		// setMapPosition() re-centers the map and moves the draggable marker —
		// confirm the marker actually rendered (Leaflet needs a real browser,
		// which is why this is a Playwright test rather than a jsdom one).
		await expect( page.locator( '#wpd-location-map .leaflet-marker-icon' ) ).toBeVisible();
	} );
} );
