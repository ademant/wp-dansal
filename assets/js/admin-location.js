/* global wpdLocation, jQuery */
( function ( $ ) {
	'use strict';

	function fillManualFields( place ) {
		$( '#wpd_short_name' ).val( place.name || '' );
		$( '#wpd_address' ).val( place.address || '' );
		$( '#wpd_zipcode' ).val( place.zipcode || '' );
		$( '#wpd_town' ).val( place.town || '' );
		$( '#wpd_country' ).val( place.country || '' );
		$( '#wpd_country_code' ).val( place.country_code || '' );
		$( '#wpd_latitude' ).val( place.lat !== null ? place.lat : '' );
		$( '#wpd_longitude' ).val( place.lng !== null ? place.lng : '' );
		$( '#wpd_osm_id' ).val( place.osm_id || '' );
		$( '#wpd_osm_type' ).val( place.osm_type || '' );

		var titleField = $( '#title' );
		if ( titleField.length && ! titleField.val() ) {
			titleField.val( place.name || place.display_name );
		}
	}

	function renderDuplicates( matches, osmId ) {
		var $box = $( '#wpd-duplicate-results' ).empty();
		if ( ! matches || ! matches.length ) {
			return;
		}

		var $wrap = $( '<div class="wpd-duplicate-box notice notice-warning" />' );
		$wrap.append( $( '<p><strong/></p>' ).find( 'strong' ).text( wpdLocation.i18n.possibleDup ).end() );

		matches.forEach( function ( loc ) {
			var $row = $( '<div class="wpd-duplicate-row" />' );
			$row.append( $( '<span/>' ).text( ( loc.location || loc.name || ( '#' + loc.id ) ) + ' — ' + ( loc.town || '' ) + ( loc.distance_km ? ' (' + loc.distance_km.toFixed( 2 ) + ' km)' : '' ) ) );
			var $btn = $( '<button type="button" class="button" />' ).text( wpdLocation.i18n.useExisting );
			$btn.on( 'click', function () {
				$( '#wpd_use_existing_dansal_id' ).val( loc.id );
				$( '.wpd-duplicate-row' ).removeClass( 'wpd-selected' );
				$row.addClass( 'wpd-selected' );
				$wrap.find( '.wpd-duplicate-note' ).remove();
				$wrap.append( $( '<p class="wpd-duplicate-note" />' ).text( wpdLocation.i18n.willAssign.replace( '%d', loc.id ) ) );
			} );
			$row.append( $btn );
			$wrap.append( $row );
		} );

		var $dismiss = $( '<button type="button" class="button-link" style="margin-top:6px;" />' ).text( wpdLocation.i18n.createNew );
		$dismiss.on( 'click', function () {
			$( '#wpd_use_existing_dansal_id' ).val( '' );
			$wrap.find( '.wpd-duplicate-row' ).removeClass( 'wpd-selected' );
			$wrap.find( '.wpd-duplicate-note' ).remove();
		} );
		$wrap.append( $( '<div/>' ).append( $dismiss ) );

		$box.append( $wrap );
	}

	function checkDuplicates( place ) {
		$( '#wpd-duplicate-results' ).text( wpdLocation.i18n.checking );
		$.getJSON( wpdLocation.ajaxUrl, {
			action: 'wpd_check_location_duplicate',
			_wpnonce: wpdLocation.nonceDuplicate,
			osm_id: place.osm_id,
			osm_type: place.osm_type,
			lat: place.lat,
			lng: place.lng,
		} ).done( function ( resp ) {
			if ( resp.success ) {
				renderDuplicates( resp.data.matches, place.osm_id );
			}
		} );
	}

	function renderSearchResults( results ) {
		var $box = $( '#wpd-nominatim-results' ).empty();
		if ( ! results.length ) {
			$box.append( $( '<p/>' ).text( wpdLocation.i18n.noResults ) );
			return;
		}
		var $list = $( '<ul class="wpd-nominatim-list" />' );
		results.forEach( function ( place ) {
			var $li = $( '<li/>' );
			var $btn = $( '<button type="button" class="button-link" />' ).text( place.display_name );
			$btn.on( 'click', function () {
				fillManualFields( place );
				checkDuplicates( place );
				$( '.wpd-nominatim-list li' ).removeClass( 'wpd-selected' );
				$li.addClass( 'wpd-selected' );
			} );
			$li.append( $btn );
			$list.append( $li );
		} );
		$box.append( $list );
	}

	$( function () {
		$( '#wpd-nominatim-search' ).on( 'click', function () {
			var q = $( '#wpd-nominatim-q' ).val();
			if ( ! q || q.length < 3 ) {
				return;
			}
			$( '#wpd-nominatim-results' ).text( '…' );
			$.getJSON( wpdLocation.ajaxUrl, {
				action: 'wpd_nominatim_search',
				_wpnonce: wpdLocation.nonceSearch,
				q: q,
			} ).done( function ( resp ) {
				if ( resp.success ) {
					renderSearchResults( resp.data );
				} else {
					$( '#wpd-nominatim-results' ).text( resp.data && resp.data.message ? resp.data.message : 'Error' );
				}
			} );
		} );

		$( '#wpd-nominatim-q' ).on( 'keydown', function ( e ) {
			if ( e.key === 'Enter' ) {
				e.preventDefault();
				$( '#wpd-nominatim-search' ).trigger( 'click' );
			}
		} );
	} );
} )( jQuery );
