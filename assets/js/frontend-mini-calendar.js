/* Mini-calendar month arrows: swap the widget's inner HTML via AJAX so a
   sidebar month flip doesn't reload the page. If the fetch fails for any
   reason we fall through to the anchor's default navigation, which still
   resolves the same month/year via ?wpd_view=calendar. */
( function () {
	function handleClick( e ) {
		var a = e.target.closest( '.wpd-mini-calendar .wpd-mini-nav a' );
		if ( ! a ) {
			return;
		}
		var month = a.getAttribute( 'data-wpd-month' );
		var year  = a.getAttribute( 'data-wpd-year' );
		if ( ! month || ! year || typeof wpdMiniCal === 'undefined' ) {
			return;
		}
		var wrap = a.closest( '.wpd-mini-calendar' );
		if ( ! wrap ) {
			return;
		}
		e.preventDefault();
		var url;
		if ( wpdMiniCal.restUrl ) {
			// Public REST route: no nonce, so a full-page-cached page keeps
			// working long after a nonce in its HTML would have expired.
			url = wpdMiniCal.restUrl +
				( wpdMiniCal.restUrl.indexOf( '?' ) === -1 ? '?' : '&' ) +
				'month=' + encodeURIComponent( month ) +
				'&year=' + encodeURIComponent( year );
		} else {
			// Cached page from before the REST route existed.
			url = wpdMiniCal.ajaxurl +
				'?action=wpd_mini_calendar' +
				'&_wpnonce=' + encodeURIComponent( wpdMiniCal.nonce ) +
				'&month=' + encodeURIComponent( month ) +
				'&year=' + encodeURIComponent( year );
		}
		fetch( url, { credentials: 'same-origin' } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( data ) {
				// REST answers {html}; the legacy admin-ajax twin {success, data: {html}}.
				var html = data && ( data.html || ( data.success && data.data && data.data.html ) );
				if ( html ) {
					var tmp = document.createElement( 'div' );
					tmp.innerHTML = html;
					var fresh = tmp.querySelector( '.wpd-mini-calendar' );
					if ( fresh ) {
						wrap.replaceWith( fresh );
					} else {
						window.location.href = a.href;
					}
				} else {
					window.location.href = a.href;
				}
			} )
			.catch( function () { window.location.href = a.href; } );
	}
	document.addEventListener( 'click', handleClick );
} )();
