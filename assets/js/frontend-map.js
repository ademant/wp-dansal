/* global L */
document.addEventListener( 'DOMContentLoaded', function () {
	var mapEl = document.getElementById( 'wpd-locations-map' );
	if ( ! mapEl || typeof L === 'undefined' ) {
		return;
	}

	// Points are shipped in a data- attribute (CSP-friendly — no inline
	// <script> to allow) and JSON-parsed here.
	var points = [];
	try {
		points = JSON.parse( mapEl.getAttribute( 'data-wpd-points' ) || '[]' );
	} catch ( e ) {
		return;
	}

	if ( ! points.length ) {
		mapEl.style.display = 'none';
		return;
	}

	// Tile provider config comes from data-wpd-tiles (see the
	// wpd_tile_url_template PHP filter) so site owners can point at a
	// self-hosted or paid tile proxy without touching this file. Default
	// referrer policy is "origin" — OSM's tile servers require a valid
	// referer and block requests that send none; "origin" still keeps
	// the page's path/query from leaking to the tile host.
	var tiles = { urlTemplate: 'https://tile.openstreetmap.org/{z}/{x}/{y}.png', maxZoom: 19, attribution: '', referrerPolicy: 'origin' };
	try {
		var tileAttr = mapEl.getAttribute( 'data-wpd-tiles' );
		if ( tileAttr ) {
			var parsed = JSON.parse( tileAttr );
			for ( var k in parsed ) {
				if ( Object.prototype.hasOwnProperty.call( parsed, k ) ) {
					tiles[ k ] = parsed[ k ];
				}
			}
		}
	} catch ( e ) {}

	var map = L.map( mapEl );
	L.tileLayer( tiles.urlTemplate, {
		maxZoom: tiles.maxZoom,
		attribution: tiles.attribution,
		referrerPolicy: tiles.referrerPolicy,
	} ).addTo( map );

	var markers = [];
	points.forEach( function ( point ) {
		var marker = L.marker( [ point.lat, point.lng ] ).addTo( map );
		// Build the popup via DOM (textContent + href) so a stored title or
		// URL containing HTML/JS cannot inject into the map popup.
		var link = document.createElement( 'a' );
		// Sanitize URL: only allow http(s) schemes to avoid javascript: or data: URIs.
		var safeUrl = '#';
		try {
			if ( point && typeof point.url === 'string' && /^https?:\/\//i.test( point.url ) ) {
				safeUrl = point.url;
			}
		} catch ( e ) { /* fall back to '#' */ }
		link.href = safeUrl;
		link.target = '_blank';
		link.rel = 'noopener noreferrer';
		// Use textContent to ensure meta-characters are not interpreted as HTML.
		link.textContent = point && point.title ? point.title : safeUrl;
		marker.bindPopup( link );
		markers.push( marker );
	} );

	if ( markers.length === 1 ) {
		map.setView( [ points[ 0 ].lat, points[ 0 ].lng ], 15 );
	} else {
		var group = L.featureGroup( markers );
		map.fitBounds( group.getBounds().pad( 0.2 ) );
	}
} );
