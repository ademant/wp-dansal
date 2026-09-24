import { test, expect } from '@playwright/test';
import { dismissWelcomeGuide, ensureMetaBoxesExpanded } from '../helpers/post-editor';

// Smoke test for assets/js/admin-event.js — the jQuery-driven musician/
// instructor entity picker that #126 part 2 wants to rewrite in vanilla JS.
// The entity search hits dansal via a WP REST route, so it's mocked here
// rather than depending on a live backend — this pins the client-side
// wiring (search -> pick -> chip renders -> hidden field syncs), which is
// exactly what a rewrite needs to keep working.
test.describe( 'Event editor — entity picker', () => {
	test( 'adding a musician renders a chip and syncs the hidden field', async ( { page } ) => {
		await page.route( '**/entities/search*', ( route ) =>
			route.fulfill( { json: [ { id: 42, name: 'Test Musician' } ] } )
		);

		await page.goto( '/wp-admin/post-new.php?post_type=dansal_event' );
		await dismissWelcomeGuide( page );
		await ensureMetaBoxesExpanded( page );

		// The "People" fieldset (containing the entity pickers) is collapsed
		// by default — only "Basics" is open on load.
		await page.getByText( 'People', { exact: true } ).click();

		const picker = page.locator( '.wpd-entity-picker[data-type="musician"]' );
		await picker.locator( '.wpd-entity-search' ).fill( 'Test' );
		await picker.getByRole( 'button', { name: 'Test Musician' } ).click();

		const chip = picker.locator( '.wpd-chip[data-id="42"]' );
		await expect( chip ).toContainText( 'Test Musician' );
		await expect( picker.locator( '.wpd-entity-ids' ) ).toHaveValue( '42' );
		await expect( picker.locator( '.wpd-entity-names' ) ).toHaveValue( 'Test Musician' );

		// The search input and results list clear after picking (addChip()).
		await expect( picker.locator( '.wpd-entity-search' ) ).toHaveValue( '' );
		await expect( picker.locator( '.wpd-entity-results' ) ).toBeEmpty();
	} );

	test( 'removing a chip clears it from the hidden field', async ( { page } ) => {
		await page.route( '**/entities/search*', ( route ) =>
			route.fulfill( { json: [ { id: 42, name: 'Test Musician' } ] } )
		);

		await page.goto( '/wp-admin/post-new.php?post_type=dansal_event' );
		await dismissWelcomeGuide( page );
		await ensureMetaBoxesExpanded( page );
		await page.getByText( 'People', { exact: true } ).click();

		const picker = page.locator( '.wpd-entity-picker[data-type="musician"]' );
		await picker.locator( '.wpd-entity-search' ).fill( 'Test' );
		await picker.getByRole( 'button', { name: 'Test Musician' } ).click();
		await expect( picker.locator( '.wpd-chip[data-id="42"]' ) ).toBeVisible();

		// .wpd-chip-remove is bound via document-level event delegation
		// ($(document).on('click', '.wpd-chip-remove', ...)) since chips are
		// added at runtime — the part of this file most likely to silently
		// break in a vanilla-JS rewrite.
		await picker.locator( '.wpd-chip-remove' ).click();

		await expect( picker.locator( '.wpd-chip[data-id="42"]' ) ).toHaveCount( 0 );
		await expect( picker.locator( '.wpd-entity-ids' ) ).toHaveValue( '' );
	} );
} );
