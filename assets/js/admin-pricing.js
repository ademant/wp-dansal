/* global document */
/**
 * Powers the pricing widget rendered by WPD_Event_Fields::render_pricing_fields()
 * — shared markup across the event edit screen, the series edit screen, and
 * the settings page's "Event defaults" section, so this is plain vanilla JS
 * (no jQuery dependency) enqueued on all three rather than tied to one.
 */
( function () {
	'use strict';

	function syncVisibility( select ) {
		var td = select.closest( 'td' );
		if ( ! td ) {
			return;
		}
		var single = td.querySelector( '.wpd-pricing-single-fields' );
		var tiers = td.querySelector( '.wpd-pricing-tiers' );
		if ( single ) {
			single.style.display = 'single' === select.value ? '' : 'none';
		}
		if ( tiers ) {
			tiers.style.display = 'multiple' === select.value ? '' : 'none';
		}
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		document.querySelectorAll( '.wpd-pricing-type' ).forEach( function ( select ) {
			syncVisibility( select );
			select.addEventListener( 'change', function () {
				syncVisibility( select );
			} );
		} );
	} );

	document.addEventListener( 'click', function ( e ) {
		if ( e.target.classList.contains( 'wpd-pricing-tier-add' ) ) {
			var tbody = e.target.closest( '.wpd-pricing-tiers' ).querySelector( 'tbody' );
			var template = tbody.querySelector( 'tr' );
			if ( ! template ) {
				return;
			}
			var newRow = template.cloneNode( true );
			newRow.querySelectorAll( 'input' ).forEach( function ( input ) {
				input.value = '';
			} );
			tbody.appendChild( newRow );
			return;
		}

		if ( e.target.classList.contains( 'wpd-pricing-tier-remove' ) ) {
			var row = e.target.closest( 'tr' );
			var body = row.closest( 'tbody' );
			// Keep at least one row so "Add tier" always has something to
			// clone from; removing the last remaining row just blanks it.
			if ( body.querySelectorAll( 'tr' ).length > 1 ) {
				row.remove();
			} else {
				row.querySelectorAll( 'input' ).forEach( function ( input ) {
					input.value = '';
				} );
			}
		}
	} );
} )();
