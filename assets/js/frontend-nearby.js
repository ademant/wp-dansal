/**
 * [dansal_nearby] visitor-side geolocation (#126 part 1). Server rendered
 * the widget around the org home (or a "not configured" hint) already; if
 * the browser grants geolocation quickly we swap the widget for a fresh
 * version centered on the visitor. Silent fallback on denial / timeout / no
 * https.
 *
 * Wired through the Interactivity API's data-wp-init (runs once per widget
 * as soon as it mounts — see WPD_Frontend::shortcode_nearby()) instead of a
 * manual DOMContentLoaded scan; the fetch-and-swap mechanism and REST
 * contract are unchanged.
 */
import { store, getElement } from '@wordpress/interactivity';

store( 'wp-dansal', {
	callbacks: {
		*requestNearby() {
			const { ref: wrap } = getElement();
			if ( ! navigator.geolocation ) {
				return;
			}

			const coords = yield new Promise( function ( resolve ) {
				navigator.geolocation.getCurrentPosition(
					function ( pos ) {
						resolve( pos && pos.coords ? pos.coords : null );
					},
					function () {
						resolve( null ); // silent fallback on denial/timeout
					},
					{ timeout: 5000, maximumAge: 300000, enableHighAccuracy: false }
				);
			} );
			if ( ! coords ) {
				return;
			}

			const restUrl = wrap.getAttribute( 'data-wpd-rest-url' );
			if ( ! restUrl ) {
				return;
			}

			// POST (not GET) so the visitor's coordinates stay out of access logs.
			const body = new URLSearchParams();
			body.set( 'lat', coords.latitude );
			body.set( 'lon', coords.longitude );
			body.set( 'radius_km', wrap.getAttribute( 'data-wpd-radius' ) || '50' );
			body.set( 'view', wrap.getAttribute( 'data-wpd-view' ) || 'map+list' );
			body.set( 'limit', wrap.getAttribute( 'data-wpd-limit' ) || '50' );
			body.set( 'tag', wrap.getAttribute( 'data-wpd-tag' ) || '' );
			body.set( 'exclude_own_org', wrap.getAttribute( 'data-wpd-exclude-own' ) || '0' );
			body.set( 'show_cancelled', wrap.getAttribute( 'data-wpd-show-cancelled' ) || '0' );

			try {
				const data = yield fetch( restUrl, { method: 'POST', body: body, credentials: 'same-origin' } )
					.then( function ( r ) { return r.json(); } );
				if ( data && typeof data.html === 'string' ) {
					// Server fragment is emitted by render_nearby_from_coords and
					// contains only esc_*-escaped output; safe to swap.
					wrap.innerHTML = data.html;
					if ( typeof window.wpdInitMaps === 'function' ) {
						window.wpdInitMaps();
					}
				}
			} catch ( e ) {
				// Silent — same fallback as a denied/timed-out geolocation prompt.
			}
		},
	},
} );
