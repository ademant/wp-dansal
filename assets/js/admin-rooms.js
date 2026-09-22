/* global document, wpdRooms */
/**
 * Room picker follows the building: on change, ask the server for the new
 * building's rooms and rebuild the room <select>. The server-side render
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

			// Uses wp.apiFetch so X-WP-Nonce is set automatically. The room
			// picker lives on both the event and series edit screens, whose
			// enqueue callers include wp-api-fetch as a dep for wpd-admin-rooms.
			wp.apiFetch( { path: '/wpd/v1/locations/' + encodeURIComponent( postId ) + '/rooms' } )
				.then( function ( data ) {
					if ( ! data || ! data.rooms ) {
						return;
					}
					data.rooms.forEach( function ( room ) {
						var option = document.createElement( 'option' );
						// The value is the room's *local post* ID: a room is a
						// location of its own (#121) and the server folds this
						// select into the event's single venue on save.
						option.value = room.post_id;
						option.textContent = room.name;
						roomSel.appendChild( option );
					} );
				} )
				.catch( function () { /* transient error leaves the room list empty */ } );
		} );
	} );
} )();
