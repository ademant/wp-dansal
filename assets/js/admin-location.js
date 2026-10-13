/* global wpdLocation, document, L */
( function () {
	'use strict';

	var map, marker;

	// Default view (roughly the center of Germany) for a location with no
	// coordinates yet — matches the country/country_code field defaults.
	var DEFAULT_CENTER = [ 51.1657, 10.4515 ];

	function byId( id ) {
		return document.getElementById( id );
	}

	function ensureMap() {
		var mapEl = byId( 'wpd-location-map' );
		if ( ! mapEl || typeof L === 'undefined' || map ) {
			return;
		}
		var lat = parseFloat( byId( 'wpd_latitude' ).value );
		var lng = parseFloat( byId( 'wpd_longitude' ).value );
		var hasCoords = ! isNaN( lat ) && ! isNaN( lng );

		map = L.map( mapEl ).setView( hasCoords ? [ lat, lng ] : DEFAULT_CENTER, hasCoords ? 15 : 6 );
		L.tileLayer( wpdLocation.tiles.urlTemplate, {
			maxZoom: wpdLocation.tiles.maxZoom,
			attribution: wpdLocation.tiles.attribution,
			referrerPolicy: wpdLocation.tiles.referrerPolicy,
		} ).addTo( map );
		marker = L.marker( hasCoords ? [ lat, lng ] : DEFAULT_CENTER, { draggable: true } ).addTo( map );

		marker.on( 'dragend', function () {
			var pos = marker.getLatLng();
			byId( 'wpd_latitude' ).value = pos.lat.toFixed( 6 );
			byId( 'wpd_longitude' ).value = pos.lng.toFixed( 6 );
		} );
		map.on( 'click', function ( e ) {
			marker.setLatLng( e.latlng );
			byId( 'wpd_latitude' ).value = e.latlng.lat.toFixed( 6 );
			byId( 'wpd_longitude' ).value = e.latlng.lng.toFixed( 6 );
		} );
	}

	function setMapPosition( lat, lng ) {
		if ( ! map || lat === null || lng === null || isNaN( lat ) || isNaN( lng ) ) {
			return;
		}
		marker.setLatLng( [ lat, lng ] );
		map.setView( [ lat, lng ], 15 );
	}

	function fillManualFields( place ) {
		byId( 'wpd_short_name' ).value = place.name || '';
		byId( 'wpd_address' ).value = place.address || '';
		byId( 'wpd_zipcode' ).value = place.zipcode || '';
		byId( 'wpd_town' ).value = place.town || '';
		byId( 'wpd_country' ).value = place.country || '';
		byId( 'wpd_country_code' ).value = place.country_code || '';
		byId( 'wpd_latitude' ).value = place.lat !== null ? place.lat : '';
		byId( 'wpd_longitude' ).value = place.lng !== null ? place.lng : '';
		byId( 'wpd_osm_id' ).value = place.osm_id || '';
		byId( 'wpd_osm_type' ).value = place.osm_type || '';

		var titleField = byId( 'title' );
		if ( titleField && ! titleField.value ) {
			titleField.value = place.name || place.display_name;
		}
	}

	function renderDuplicates( matches ) {
		var box = byId( 'wpd-duplicate-results' );
		box.innerHTML = '';
		if ( ! matches || ! matches.length ) {
			return;
		}

		var wrap = document.createElement( 'div' );
		wrap.className = 'wpd-duplicate-box notice notice-warning';
		var intro = document.createElement( 'p' );
		var strong = document.createElement( 'strong' );
		strong.textContent = wpdLocation.i18n.possibleDup;
		intro.appendChild( strong );
		wrap.appendChild( intro );

		matches.forEach( function ( loc ) {
			var row = document.createElement( 'div' );
			row.className = 'wpd-duplicate-row';
			var label = document.createElement( 'span' );
			label.textContent = ( loc.location || loc.name || ( '#' + loc.id ) ) + ' — ' + ( loc.town || '' ) + ( loc.distance_km ? ' (' + loc.distance_km.toFixed( 2 ) + ' km)' : '' );
			row.appendChild( label );

			var btn = document.createElement( 'button' );
			btn.type = 'button';
			btn.className = 'button';
			btn.textContent = wpdLocation.i18n.useExisting;
			btn.addEventListener( 'click', function () {
				byId( 'wpd_use_existing_dansal_id' ).value = loc.id;
				wrap.querySelectorAll( '.wpd-duplicate-row' ).forEach( function ( r ) {
					r.classList.remove( 'wpd-selected' );
				} );
				row.classList.add( 'wpd-selected' );
				var existingNote = wrap.querySelector( '.wpd-duplicate-note' );
				if ( existingNote ) {
					existingNote.remove();
				}
				var note = document.createElement( 'p' );
				note.className = 'wpd-duplicate-note';
				note.textContent = wpdLocation.i18n.willAssign.replace( '%d', loc.id );
				wrap.appendChild( note );
			} );
			row.appendChild( btn );
			wrap.appendChild( row );
		} );

		var dismissWrap = document.createElement( 'div' );
		var dismiss = document.createElement( 'button' );
		dismiss.type = 'button';
		dismiss.className = 'button-link';
		dismiss.style.marginTop = '6px';
		dismiss.textContent = wpdLocation.i18n.createNew;
		dismiss.addEventListener( 'click', function () {
			byId( 'wpd_use_existing_dansal_id' ).value = '';
			wrap.querySelectorAll( '.wpd-duplicate-row' ).forEach( function ( r ) {
				r.classList.remove( 'wpd-selected' );
			} );
			var existingNote = wrap.querySelector( '.wpd-duplicate-note' );
			if ( existingNote ) {
				existingNote.remove();
			}
		} );
		dismissWrap.appendChild( dismiss );
		wrap.appendChild( dismissWrap );

		box.appendChild( wrap );
	}

	function checkDuplicates( place ) {
		byId( 'wpd-duplicate-results' ).textContent = wpdLocation.i18n.checking;
		var qs = 'osm_id=' + encodeURIComponent( place.osm_id || 0 )
			+ '&osm_type=' + encodeURIComponent( place.osm_type || '' )
			+ '&lat=' + encodeURIComponent( place.lat )
			+ '&lng=' + encodeURIComponent( place.lng );
		wp.apiFetch( { path: '/wpd/v1/locations/duplicates?' + qs } )
			.then( function ( data ) {
				renderDuplicates( data.matches );
			} )
			.catch( function () {
				// Silent — the duplicate-check is a nice-to-have; a failure just
				// means we don't offer the "use existing" affordance for this pick.
			} );
	}

	function renderSearchResults( results ) {
		var box = byId( 'wpd-nominatim-results' );
		box.innerHTML = '';
		if ( ! results.length ) {
			var empty = document.createElement( 'p' );
			empty.textContent = wpdLocation.i18n.noResults;
			box.appendChild( empty );
			return;
		}
		var list = document.createElement( 'ul' );
		list.className = 'wpd-nominatim-list';
		results.forEach( function ( place ) {
			var li = document.createElement( 'li' );
			var btn = document.createElement( 'button' );
			btn.type = 'button';
			btn.className = 'button-link';
			btn.textContent = place.display_name;
			btn.addEventListener( 'click', function () {
				fillManualFields( place );
				setMapPosition( place.lat, place.lng );
				checkDuplicates( place );
				list.querySelectorAll( 'li' ).forEach( function ( l ) {
					l.classList.remove( 'wpd-selected' );
				} );
				li.classList.add( 'wpd-selected' );
			} );
			li.appendChild( btn );
			list.appendChild( li );
		} );
		box.appendChild( list );
	}

	function runNominatimSearch( q ) {
		if ( ! q || q.length < 3 ) {
			return;
		}
		byId( 'wpd-nominatim-results' ).textContent = '…';
		wp.apiFetch( {
			path: '/wpd/v1/nominatim/search?q=' + encodeURIComponent( q ),
		} ).then( function ( data ) {
			renderSearchResults( data );
		} ).catch( function ( err ) {
			byId( 'wpd-nominatim-results' ).textContent = err && err.message ? err.message : 'Error';
		} );
	}

	function renderRooms( rooms, container ) {
		container.innerHTML = '';
		if ( ! rooms || ! rooms.length ) {
			var empty = document.createElement( 'p' );
			empty.className = 'description';
			empty.textContent = wpdLocation.i18n.noRooms;
			container.appendChild( empty );
			return;
		}
		var ul = document.createElement( 'ul' );
		ul.className = 'wpd-rooms-list';
		rooms.forEach( function ( room ) {
			var li = document.createElement( 'li' );

			// A room is a location of its own (#121), so its name links to
			// that location's edit screen (floor, capacity, notes, ...).
			var nameEl;
			if ( room.edit_url ) {
				nameEl = document.createElement( 'a' );
				nameEl.href = room.edit_url;
			} else {
				nameEl = document.createElement( 'span' );
			}
			nameEl.textContent = room.name;
			li.appendChild( nameEl );
			li.appendChild( document.createTextNode( ' — ' ) );

			var btn = document.createElement( 'button' );
			btn.type = 'button';
			btn.className = 'button-link wpd-room-remove';
			btn.textContent = wpdLocation.i18n.removeRoom;
			btn.dataset.roomId = room.id;
			li.appendChild( btn );

			ul.appendChild( li );
		} );
		container.appendChild( ul );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		ensureMap();

		[ 'wpd_latitude', 'wpd_longitude' ].forEach( function ( id ) {
			byId( id ).addEventListener( 'change', function () {
				setMapPosition( parseFloat( byId( 'wpd_latitude' ).value ), parseFloat( byId( 'wpd_longitude' ).value ) );
			} );
		} );

		byId( 'wpd-nominatim-fill' ).addEventListener( 'click', function () {
			var parts = [ byId( 'wpd_short_name' ).value, byId( 'wpd_address' ).value, byId( 'wpd_zipcode' ).value, byId( 'wpd_town' ).value, byId( 'wpd_country' ).value ];
			var q = parts.filter( function ( p ) {
				return p;
			} ).join( ', ' );
			if ( ! q ) {
				return;
			}
			byId( 'wpd-nominatim-q' ).value = q;
			runNominatimSearch( q );
		} );

		byId( 'wpd-nominatim-reverse' ).addEventListener( 'click', function () {
			var lat = parseFloat( byId( 'wpd_latitude' ).value );
			var lng = parseFloat( byId( 'wpd_longitude' ).value );
			if ( isNaN( lat ) || isNaN( lng ) ) {
				window.alert( wpdLocation.i18n.reverseNeedsCoords );
				return;
			}
			byId( 'wpd-nominatim-results' ).textContent = wpdLocation.i18n.reversing;
			wp.apiFetch( {
				path: '/wpd/v1/nominatim/reverse?lat=' + encodeURIComponent( lat ) + '&lng=' + encodeURIComponent( lng ),
			} ).then( function ( data ) {
				fillManualFields( data );
				setMapPosition( data.lat, data.lng );
				checkDuplicates( data );
				byId( 'wpd-nominatim-results' ).innerHTML = '';
			} ).catch( function ( err ) {
				byId( 'wpd-nominatim-results' ).textContent = err && err.message ? err.message : 'Error';
			} );
		} );

		byId( 'wpd-nominatim-search' ).addEventListener( 'click', function () {
			runNominatimSearch( byId( 'wpd-nominatim-q' ).value );
		} );

		byId( 'wpd-nominatim-q' ).addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Enter' ) {
				e.preventDefault();
				runNominatimSearch( byId( 'wpd-nominatim-q' ).value );
			}
		} );

		// Rooms panel (only rendered when the location is already synced;
		// see WPD_CPT_Location::render_meta_box).
		var rooms = byId( 'wpd-rooms' );
		if ( rooms ) {
			var postId = rooms.dataset.postId;

			wp.apiFetch( { path: '/wpd/v1/locations/' + postId + '/rooms' } )
				.then( function ( data ) { renderRooms( data.rooms, rooms ); } )
				.catch( function () { /* leave the room panel empty on transient error */ } );

			byId( 'wpd-room-add' ).addEventListener( 'click', function () {
				var name = byId( 'wpd-room-new-name' ).value.trim();
				if ( ! name ) {
					return;
				}
				wp.apiFetch( {
					path: '/wpd/v1/locations/' + postId + '/rooms',
					method: 'POST',
					data: { name: name },
				} ).then( function ( data ) {
					renderRooms( data.rooms, rooms );
					byId( 'wpd-room-new-name' ).value = '';
				} ).catch( function ( err ) {
					window.alert( ( err && err.message ) || wpdLocation.i18n.roomsError );
				} );
			} );

			// Room rows are (re)rendered at runtime by renderRooms() above, so
			// the remove button is bound via delegation on the container
			// rather than per-button at creation time.
			rooms.addEventListener( 'click', function ( e ) {
				var btn = e.target.closest( '.wpd-room-remove' );
				if ( ! btn ) {
					return;
				}
				var roomId = btn.dataset.roomId;
				if ( ! window.confirm( wpdLocation.i18n.confirmRemove ) ) {
					return;
				}
				wp.apiFetch( {
					path: '/wpd/v1/locations/' + postId + '/rooms/' + encodeURIComponent( roomId ),
					method: 'DELETE',
				} ).then( function ( data ) {
					renderRooms( data.rooms, rooms );
				} ).catch( function ( err ) {
					window.alert( ( err && err.message ) || wpdLocation.i18n.roomsError );
				} );
			} );
		}
	} );
} )();
