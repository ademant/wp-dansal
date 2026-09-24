import { expect, type Page } from '@playwright/test';

// The block editor shows a one-time "Welcome to the editor" tour modal on
// first visit per post type; it blocks all pointer interaction underneath
// until dismissed. WP persists the dismissal server-side per user, but a
// fresh test user (or a first run) sees it, so every spec closes it
// defensively rather than relying on that persistence.
export async function dismissWelcomeGuide( page: Page ): Promise<void> {
	// For a genuinely first-run user the modal mounts a moment after
	// navigation completes (it's not there yet on the very first paint), so
	// a one-shot isVisible() check races it and misses — it then pops up
	// later and blocks every subsequent interaction. Waiting briefly for the
	// button, and swallowing the timeout when the guide was already
	// dismissed in an earlier test, handles both cases.
	await page
		.getByRole( 'button', { name: 'Close', exact: true } )
		.click( { timeout: 3000 } )
		.catch( () => {} );
}

// Classic meta boxes (registered via add_meta_box(), like WPD_CPT_Location's
// and WPD_CPT_Event's) render in the block editor inside a collapsible
// "Meta Boxes" panel that starts collapsed by default — the fields these
// tests need (Nominatim search, entity picker) are inside it. This toggles
// it open, but only if it's currently closed: the open/closed state is a
// per-user preference the block editor persists server-side, so a second
// test reusing the same session could otherwise find it already open and
// re-collapse it by clicking unconditionally.
//
// A native `.click()` on the button element (rather than Playwright's
// coordinate-based click) is used because the panel's resize-handle
// ("Drag to resize") visually overlaps the toggle button and intercepts
// pointer events at the coordinates Playwright would otherwise click.
export async function ensureMetaBoxesExpanded( page: Page ): Promise<void> {
	const toggle = page.getByRole( 'button', { name: 'Meta Boxes' } );
	// The block editor mounts the classic-meta-box compat layer asynchronously
	// (after the editor's own initial render), so an immediate count() can
	// see zero even though the panel is about to appear — wait for it rather
	// than treating "not there yet" as "this screen has no meta boxes".
	try {
		await toggle.waitFor( { state: 'attached', timeout: 5000 } );
	} catch {
		return;
	}
	if ( ( await toggle.getAttribute( 'aria-expanded' ) ) !== 'true' ) {
		await toggle.evaluate( ( el ) => ( el as HTMLElement ).click() );
		// The click updates React state; the panel's content doesn't render
		// synchronously with it, so wait for the transition to actually land
		// rather than racing it.
		await expect( toggle ).toHaveAttribute( 'aria-expanded', 'true' );
		await expect( page.locator( '.edit-post-meta-boxes-main__liner' ) ).toBeVisible();
	}
}
