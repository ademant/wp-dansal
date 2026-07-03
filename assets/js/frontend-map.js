/* global L */
document.addEventListener( 'DOMContentLoaded', function () {
	var mapEl = document.getElementById( 'wpd-locations-map' );
	var dataEl = document.getElementById( 'wpd-locations-data' );
	if ( ! mapEl || ! dataEl || typeof L === 'undefined' ) {
		return;
	}

	var points = [];
	try {
		points = JSON.parse( dataEl.textContent );
	} catch ( e ) {
		return;
	}

	if ( ! points.length ) {
		mapEl.style.display = 'none';
		return;
	}

	// Map tiles necessarily load from OSM's tile server at view time (unlike
	// the Leaflet library itself, which this plugin self-hosts) — there's no
	// practical way to self-host the whole planet's raster tiles. Swap this
	// URL for a self-hosted/paid tile provider if that third-party request
	// to a visitor's browser is a concern.
	var map = L.map( mapEl );
	L.tileLayer( 'https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
		maxZoom: 19,
		attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
	} ).addTo( map );

	var markers = [];
	points.forEach( function ( point ) {
		var marker = L.marker( [ point.lat, point.lng ] ).addTo( map );
		marker.bindPopup( '<a href="' + point.url + '">' + point.title + '</a>' );
		markers.push( marker );
	} );

	if ( markers.length === 1 ) {
		map.setView( [ points[ 0 ].lat, points[ 0 ].lng ], 15 );
	} else {
		var group = L.featureGroup( markers );
		map.fitBounds( group.getBounds().pad( 0.2 ) );
	}
} );
