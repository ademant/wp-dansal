/* global document, wpdRooms */
/**
 * Room picker follows the location: on change, ask the server for the new
 * location's rooms and rebuild the room <select>. The server-side render
 * seeded whatever room was selected at page load; anything after that is
 * JS-driven.
 *
 * Powers the shared WPD_Event_Fields::render_location_room_fields() markup,
 * rendered on both the event edit screen and the series edit screen — plain
 * vanilla JS (no jQuery dependency) so it doesn't need admin-event.js's
 * jQuery dependency loaded on a screen that otherwise has no use for it.
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		var locSel = document.querySelector( '.wpd-location-select' );
		var roomSel = document.querySelector( '.wpd-room-select' );
		if ( ! locSel || ! roomSel || typeof wpdRooms === 'undefined' ) {
			return;
		}

		locSel.addEventListener( 'change', function () {
			var postId = parseInt( locSel.value, 10 );

			// Rebuild starts with the "none" option so a location change
			// doesn't carry a stale room-id from the previous venue.
			roomSel.innerHTML = '';
			var noneOption = document.createElement( 'option' );
			noneOption.value = '0';
			noneOption.textContent = wpdRooms.i18n.noRoom;
			roomSel.appendChild( noneOption );

			if ( ! postId ) {
				return;
			}

			var url = wpdRooms.ajaxUrl + '?action=wpd_list_rooms&_wpnonce=' + encodeURIComponent( wpdRooms.nonce ) + '&post_id=' + encodeURIComponent( postId );
			fetch( url, { credentials: 'same-origin' } )
				.then( function ( response ) {
					return response.json();
				} )
				.then( function ( resp ) {
					if ( ! resp.success || ! resp.data || ! resp.data.rooms ) {
						return;
					}
					resp.data.rooms.forEach( function ( room ) {
						var option = document.createElement( 'option' );
						option.value = room.id;
						option.textContent = room.name;
						roomSel.appendChild( option );
					} );
				} );
		} );
	} );
} )();
