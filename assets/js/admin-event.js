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
		var $remove = $( '<a href="#" class="wpd-chip-remove">&times;</a>' );
		$chip.append( $remove );
		$picker.find( '.wpd-entity-chips' ).append( $chip );
		syncHidden( $picker );
	}

	$( document ).on( 'click', '.wpd-chip-remove', function ( e ) {
		e.preventDefault();
		var $picker = $( this ).closest( '.wpd-entity-picker' );
		$( this ).closest( '.wpd-chip' ).remove();
		syncHidden( $picker );
	} );

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
				if ( ! resp.success || ! resp.data.length ) {
					return;
				}
				var $list = $( '<ul class="wpd-nominatim-list" />' );
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
				$results.append( $list );
			} );
		}, 300 ) );
	} );
} )( jQuery );
