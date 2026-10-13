/**
 * Mini-calendar month arrows (#126 part 1): swap the widget's markup via a
 * fetch instead of reloading the page. If the fetch fails for any reason we
 * fall through to the anchor's default navigation, which still resolves the
 * same month/year via ?wpd_view=calendar — event.preventDefault() only runs
 * once we know the fetch was even attempted.
 *
 * Wired through the Interactivity API (data-wp-on--click in
 * WPD_Frontend::render_mini_calendar()) rather than a manual
 * addEventListener so the click handling is declarative, matching the
 * "native block way" this issue asked for; the fetch-and-swap mechanism
 * itself, and the REST contract it talks to, are unchanged.
 */
import * as interactivity from '@wordpress/interactivity';

const { store, getElement } = interactivity;
// withSyncEvent() — needed to keep synchronous access to a click event
// (preventDefault()) once an action is dispatched asynchronously by
// default — was only added in WordPress 6.8 (this plugin's floor is 6.5).
// A plain generator action gets synchronous event dispatch on its own on
// 6.5-6.7, so falling back to the identity function there is correct, not
// a degradation; a namespace import (rather than a named one) is required
// for this feature-detection, since destructuring a named export that
// doesn't exist from the module throws at import time, not at use time.
const withSyncEvent = interactivity.withSyncEvent || ( ( fn ) => fn );

store( 'wp-dansal', {
	actions: {
		flipMonth: withSyncEvent( function* ( event ) {
			const { ref: a } = getElement();
			const month = a.getAttribute( 'data-wpd-month' );
			const year = a.getAttribute( 'data-wpd-year' );
			const wrap = a.closest( '.wpd-mini-calendar' );
			const restUrl = wrap && wrap.getAttribute( 'data-wpd-rest-url' );
			if ( ! month || ! year || ! wrap || ! restUrl ) {
				return;
			}
			event.preventDefault();

			const url = restUrl
				+ ( restUrl.indexOf( '?' ) === -1 ? '?' : '&' )
				+ 'month=' + encodeURIComponent( month )
				+ '&year=' + encodeURIComponent( year );

			let html = null;
			try {
				const data = yield fetch( url, { credentials: 'same-origin' } ).then( ( r ) => r.json() );
				html = data && data.html;
			} catch ( e ) {
				html = null;
			}

			if ( ! html ) {
				window.location.href = a.href;
				return;
			}
			const tmp = document.createElement( 'div' );
			tmp.innerHTML = html;
			const fresh = tmp.querySelector( '.wpd-mini-calendar' );
			if ( fresh ) {
				wrap.replaceWith( fresh );
			} else {
				window.location.href = a.href;
			}
		} ),
	},
} );
