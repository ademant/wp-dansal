/* [dansal_nearby] visitor-side geolocation. Server rendered the widget
   around the org home (or a "not configured" hint) already; if the browser
   grants geolocation quickly we swap the widget for a fresh version
   centered on the visitor. Silent fallback on denial / timeout / no https. */
( function () {
	function refresh( wrap, coords ) {
		if ( typeof wpdNearby === 'undefined' ) {
			return;
		}
		var body = new URLSearchParams();
		body.set( 'action', 'wpd_nearby' );
		body.set( '_wpnonce', wpdNearby.nonce );
		body.set( 'lat', coords.latitude );
		body.set( 'lon', coords.longitude );
		body.set( 'radius_km', wrap.getAttribute( 'data-wpd-radius' ) || '50' );
		body.set( 'view', wrap.getAttribute( 'data-wpd-view' ) || 'map+list' );
		body.set( 'limit', wrap.getAttribute( 'data-wpd-limit' ) || '50' );
		body.set( 'tag', wrap.getAttribute( 'data-wpd-tag' ) || '' );
		body.set( 'exclude_own_org', wrap.getAttribute( 'data-wpd-exclude-own' ) || '0' );

		fetch( wpdNearby.ajaxurl, { method: 'POST', body: body, credentials: 'same-origin' } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( data ) {
				if ( data && data.success && data.data && typeof data.data.html === 'string' ) {
					// Server fragment is emitted by render_nearby_from_coords and
					// contains only esc_*-escaped output; safe to swap.
					wrap.innerHTML = data.data.html;
					if ( typeof window.wpdInitMaps === 'function' ) {
						window.wpdInitMaps();
					}
				}
			} )
			.catch( function () {} );
	}

	function init() {
		var wraps = document.querySelectorAll( '.wpd-nearby' );
		if ( ! wraps.length || ! navigator.geolocation ) {
			return;
		}
		wraps.forEach( function ( wrap ) {
			navigator.geolocation.getCurrentPosition(
				function ( pos ) {
					if ( pos && pos.coords ) {
						refresh( wrap, pos.coords );
					}
				},
				function () { /* silent fallback */ },
				{ timeout: 5000, maximumAge: 300000, enableHighAccuracy: false }
			);
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
