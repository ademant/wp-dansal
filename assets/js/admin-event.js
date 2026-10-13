/* global wpdEvent, document */
( function () {
	'use strict';

	// Per-input debounce timers for the entity-search listener below, keyed
	// by element rather than a property on the element itself.
	var searchTimeouts = new WeakMap();

	function syncHidden( picker ) {
		var ids = [];
		var names = [];
		picker.querySelectorAll( '.wpd-chip' ).forEach( function ( chip ) {
			ids.push( chip.dataset.id );
			var clone = chip.cloneNode( true );
			clone.querySelectorAll( 'a' ).forEach( function ( a ) {
				a.remove();
			} );
			names.push( clone.textContent.trim() );
		} );
		picker.querySelector( '.wpd-entity-ids' ).value = ids.join( ',' );
		picker.querySelector( '.wpd-entity-names' ).value = names.join( '|' );
	}

	// Musician/instructor names are dansal-supplied strings; they must never
	// hit innerHTML. textContent (used below and in the results list) is
	// safe — it never parses its argument as markup.
	function addChip( picker, id, name ) {
		if ( picker.querySelector( '.wpd-chip[data-id="' + id + '"]' ) ) {
			return;
		}
		var chip = document.createElement( 'span' );
		chip.className = 'wpd-chip';
		chip.dataset.id = id;
		chip.appendChild( document.createTextNode( name + ' ' ) );

		var promote = document.createElement( 'a' );
		promote.href = '#';
		promote.className = 'wpd-chip-promote';
		promote.title = wpdEvent.i18n.promoteTitle;
		promote.textContent = '✎';
		chip.appendChild( promote );

		chip.appendChild( document.createTextNode( ' ' ) );

		var remove = document.createElement( 'a' );
		remove.href = '#';
		remove.className = 'wpd-chip-remove';
		remove.textContent = '×';
		chip.appendChild( remove );

		picker.querySelector( '.wpd-entity-chips' ).appendChild( chip );
		syncHidden( picker );
	}

	document.addEventListener( 'click', function ( e ) {
		var link = e.target.closest( '.wpd-chip-promote' );
		if ( ! link ) {
			return;
		}
		e.preventDefault();
		var chip = link.closest( '.wpd-chip' );
		var picker = link.closest( '.wpd-entity-picker' );
		link.setAttribute( 'aria-busy', 'true' );
		wp.apiFetch( {
			path: '/wpd/v1/entities/' + encodeURIComponent( chip.dataset.id ) + '/promote',
			method: 'POST',
			data: { type: picker.dataset.type },
		} ).then( function ( data ) {
			if ( data && data.edit_url ) {
				var edit = document.createElement( 'a' );
				edit.className = 'wpd-chip-edit';
				edit.target = '_blank';
				edit.rel = 'noopener';
				edit.href = data.edit_url;
				edit.title = wpdEvent.i18n.editLocal;
				edit.textContent = '↗';
				link.replaceWith( edit );
			} else {
				window.alert( wpdEvent.i18n.promoteFailed );
				link.setAttribute( 'aria-busy', 'false' );
			}
		} ).catch( function ( err ) {
			window.alert( ( err && err.message ) || wpdEvent.i18n.promoteFailed );
			link.setAttribute( 'aria-busy', 'false' );
		} );
	} );

	document.addEventListener( 'click', function ( e ) {
		var link = e.target.closest( '.wpd-chip-remove' );
		if ( ! link ) {
			return;
		}
		e.preventDefault();
		var picker = link.closest( '.wpd-entity-picker' );
		link.closest( '.wpd-chip' ).remove();
		syncHidden( picker );
	} );

	function renderCreateRow( picker, input, results, type, name ) {
		// Two-step: first click asks for confirmation, second click POSTs.
		// Prevents accidental creation from a typo the user might have
		// missed. Label text is always dansal-supplied or user-typed, set
		// via textContent so it can't inject markup.
		var li = document.createElement( 'li' );
		li.className = 'wpd-entity-create';
		var label = ( type === 'musician' ? wpdEvent.i18n.createMusician : wpdEvent.i18n.createInstructor ).replace( '%s', name );
		var btn = document.createElement( 'button' );
		btn.type = 'button';
		btn.className = 'button-link';
		btn.textContent = label;
		btn.addEventListener( 'click', function () {
			if ( ! btn.classList.contains( 'is-confirming' ) ) {
				btn.classList.add( 'is-confirming' );
				btn.textContent = wpdEvent.i18n.confirmCreate;
				return;
			}
			btn.disabled = true;
			wp.apiFetch( {
				path: '/wpd/v1/entities',
				method: 'POST',
				data: { type: type, name: name },
			} ).then( function ( data ) {
				addChip( picker, data.id, data.name );
				input.value = '';
				results.innerHTML = '';
			} ).catch( function ( err ) {
				var msg = ( err && err.message ) || wpdEvent.i18n.createFailed;
				btn.disabled = false;
				btn.classList.remove( 'is-confirming' );
				btn.textContent = msg;
			} );
		} );
		li.appendChild( btn );
		return li;
	}

	document.addEventListener( 'input', function ( e ) {
		var input = e.target;
		if ( ! input.classList || ! input.classList.contains( 'wpd-entity-search' ) ) {
			return;
		}
		var picker = input.closest( '.wpd-entity-picker' );
		var type = picker.dataset.type;
		var q = input.value;
		var results = picker.querySelector( '.wpd-entity-results' );

		clearTimeout( searchTimeouts.get( input ) );
		if ( q.length < 2 ) {
			results.innerHTML = '';
			return;
		}
		searchTimeouts.set( input, setTimeout( function () {
			wp.apiFetch( {
				path: '/wpd/v1/entities/search?type=' + encodeURIComponent( type ) + '&q=' + encodeURIComponent( q ),
			} ).then( function ( data ) {
				results.innerHTML = '';
				var list = document.createElement( 'ul' );
				list.className = 'wpd-nominatim-list';
				if ( Array.isArray( data ) && data.length ) {
					data.forEach( function ( item ) {
						var li = document.createElement( 'li' );
						var btn = document.createElement( 'button' );
						btn.type = 'button';
						btn.className = 'button-link';
						btn.textContent = item.name;
						btn.addEventListener( 'click', function () {
							addChip( picker, item.id, item.name );
							input.value = '';
							results.innerHTML = '';
						} );
						li.appendChild( btn );
						list.appendChild( li );
					} );
				}
				list.appendChild( renderCreateRow( picker, input, results, type, q ) );
				results.appendChild( list );
			} ).catch( function () {
				// Swallow — search errors just leave the current results in place.
				// The create-row keeps the user unblocked either way.
			} );
		}, 300 ) );
	} );

	// #58: When the user picks a start datetime and end is still empty, seed
	// end with start + defaultDurationSeconds. Only ever fires while end is
	// empty, so an already-typed end is never clobbered.
	document.addEventListener( 'DOMContentLoaded', function () {
		var startEl = document.getElementById( 'wpd_start_time' );
		var endEl = document.getElementById( 'wpd_end_time' );
		if ( ! startEl || ! endEl ) {
			return;
		}
		var pad = function ( n ) { return ( '0' + n ).slice( -2 ); };
		var fill = function () {
			if ( endEl.value !== '' || startEl.value === '' ) {
				return;
			}
			var d = new Date( startEl.value );
			if ( isNaN( d.getTime() ) ) {
				return;
			}
			var seconds = parseInt( wpdEvent.defaultDurationSeconds, 10 );
			if ( ! seconds || seconds < 0 ) {
				seconds = 7200;
			}
			d = new Date( d.getTime() + seconds * 1000 );
			endEl.value = d.getFullYear() + '-' + pad( d.getMonth() + 1 ) + '-' + pad( d.getDate() )
				+ 'T' + pad( d.getHours() ) + ':' + pad( d.getMinutes() );
		};
		startEl.addEventListener( 'change', fill );
		startEl.addEventListener( 'blur', fill );

		// Room picker follows the location — see assets/js/admin-rooms.js.
		// That's a shared vanilla-JS file (not this one) because the
		// Location+Room fields are also rendered on the series edit screen
		// via the same WPD_Event_Fields::render_location_room_fields(),
		// which doesn't load admin-event.js at all.
	} );
} )();
