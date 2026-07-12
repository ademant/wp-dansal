/* global wpdEvent, jQuery */
( function ( $ ) {
	'use strict';

	function syncHidden( $picker ) {
		var ids = [];
		var names = [];
		$picker.find( '.wpd-chip' ).each( function () {
			ids.push( $( this ).data( 'id' ) );
			names.push( $( this ).clone().find( 'a' ).remove().end().text().trim() );
		} );
		$picker.find( '.wpd-entity-ids' ).val( ids.join( ',' ) );
		$picker.find( '.wpd-entity-names' ).val( names.join( '|' ) );
	}

	// Musician/instructor names are dansal-supplied strings; they must never
	// hit .html() or innerHTML. jQuery's .text() (used below and in the
	// results list) sets textContent, which is safe.
	function addChip( $picker, id, name ) {
		if ( $picker.find( '.wpd-chip[data-id="' + id + '"]' ).length ) {
			return;
		}
		var $chip = $( '<span class="wpd-chip" />' ).attr( 'data-id', id ).text( name + ' ' );
		var $promote = $( '<a href="#" class="wpd-chip-promote" />' ).attr( 'title', wpdEvent.i18n.promoteTitle ).html( '&#9998;' );
		var $remove = $( '<a href="#" class="wpd-chip-remove">&times;</a>' );
		$chip.append( $promote ).append( document.createTextNode( ' ' ) ).append( $remove );
		$picker.find( '.wpd-entity-chips' ).append( $chip );
		syncHidden( $picker );
	}

	$( document ).on( 'click', '.wpd-chip-promote', function ( e ) {
		e.preventDefault();
		var $link = $( this );
		var $chip = $link.closest( '.wpd-chip' );
		var $picker = $link.closest( '.wpd-entity-picker' );
		$link.prop( 'aria-busy', true );
		$.post( wpdEvent.ajaxUrl, {
			action: 'wpd_promote_entity',
			_wpnonce: wpdEvent.nonce,
			type: $picker.data( 'type' ),
			id: $chip.data( 'id' ),
		} ).done( function ( resp ) {
			if ( resp && resp.success && resp.data.edit_url ) {
				var $edit = $( '<a class="wpd-chip-edit" target="_blank" rel="noopener" />' )
					.attr( 'href', resp.data.edit_url )
					.attr( 'title', wpdEvent.i18n.editLocal )
					.html( '&#8599;' );
				$link.replaceWith( $edit );
			} else {
				window.alert( ( resp && resp.data && resp.data.message ) || wpdEvent.i18n.promoteFailed );
				$link.prop( 'aria-busy', false );
			}
		} ).fail( function () {
			window.alert( wpdEvent.i18n.promoteFailed );
			$link.prop( 'aria-busy', false );
		} );
	} );

	$( document ).on( 'click', '.wpd-chip-remove', function ( e ) {
		e.preventDefault();
		var $picker = $( this ).closest( '.wpd-entity-picker' );
		$( this ).closest( '.wpd-chip' ).remove();
		syncHidden( $picker );
	} );

	function renderCreateRow( $picker, $input, $results, type, name ) {
		// Two-step: first click asks for confirmation, second click POSTs.
		// Prevents accidental creation from a typo the user might have
		// missed. Label text is always dansal-supplied or user-typed, set
		// via .text() so it can't inject markup.
		var $li = $( '<li class="wpd-entity-create" />' );
		var label = ( type === 'musician' ? wpdEvent.i18n.createMusician : wpdEvent.i18n.createInstructor ).replace( '%s', name );
		var $btn = $( '<button type="button" class="button-link" />' ).text( label );
		$btn.on( 'click', function () {
			if ( ! $btn.hasClass( 'is-confirming' ) ) {
				$btn.addClass( 'is-confirming' ).text( wpdEvent.i18n.confirmCreate );
				return;
			}
			$btn.prop( 'disabled', true );
			$.post( wpdEvent.ajaxUrl, {
				action: 'wpd_create_entity',
				_wpnonce: wpdEvent.nonce,
				type: type,
				name: name,
			} ).done( function ( resp ) {
				if ( ! resp || ! resp.success ) {
					var msg = resp && resp.data && resp.data.message ? resp.data.message : wpdEvent.i18n.createFailed;
					$btn.prop( 'disabled', false ).removeClass( 'is-confirming' ).text( msg );
					return;
				}
				addChip( $picker, resp.data.id, resp.data.name );
				$input.val( '' );
				$results.empty();
			} ).fail( function () {
				$btn.prop( 'disabled', false ).removeClass( 'is-confirming' ).text( wpdEvent.i18n.createFailed );
			} );
		} );
		$li.append( $btn );
		return $li;
	}

	$( document ).on( 'input', '.wpd-entity-search', function () {
		var $input = $( this );
		var $picker = $input.closest( '.wpd-entity-picker' );
		var type = $picker.data( 'type' );
		var q = $input.val();
		var $results = $picker.find( '.wpd-entity-results' );

		clearTimeout( $input.data( 'wpdTimeout' ) );
		if ( q.length < 2 ) {
			$results.empty();
			return;
		}
		$input.data( 'wpdTimeout', setTimeout( function () {
			$.getJSON( wpdEvent.ajaxUrl, {
				action: 'wpd_search_entity',
				_wpnonce: wpdEvent.nonce,
				type: type,
				q: q,
			} ).done( function ( resp ) {
				$results.empty();
				var $list = $( '<ul class="wpd-nominatim-list" />' );
				if ( resp.success && resp.data.length ) {
					resp.data.forEach( function ( item ) {
						var $li = $( '<li/>' );
						var $btn = $( '<button type="button" class="button-link" />' ).text( item.name );
						$btn.on( 'click', function () {
							addChip( $picker, item.id, item.name );
							$input.val( '' );
							$results.empty();
						} );
						$li.append( $btn );
						$list.append( $li );
					} );
				}
				$list.append( renderCreateRow( $picker, $input, $results, type, q ) );
				$results.append( $list );
			} );
		}, 300 ) );
	} );

	// #58: When the user picks a start datetime and end is still empty, seed
	// end with start + defaultDurationSeconds. Only ever fires while end is
	// empty, so an already-typed end is never clobbered.
	$( function () {
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
	} );
} )( jQuery );
