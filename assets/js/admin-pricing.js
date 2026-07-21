/* global document */
/**
 * Powers two widgets rendered by WPD_Event_Fields — the pricing tiers table
 * (render_pricing_fields()) and the timetable table (render_timetable_fields())
 * — shared markup across the event edit screen, the series edit screen, and
 * the settings page's "Event defaults" section, so this is plain vanilla JS
 * (no jQuery dependency) enqueued on all three rather than tied to one.
 */
( function () {
	'use strict';

	// Pricing-specific: toggle the single-amount field vs the tiers table
	// based on the selected pricing type. The tiers table itself uses the
	// generic growable-table behavior below, same as the timetable table.
	function syncPricingVisibility( select ) {
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
			syncPricingVisibility( select );
			select.addEventListener( 'change', function () {
				syncPricingVisibility( select );
			} );
		} );
	} );

	// Generic growable-table behavior: any table wrapped in .wpd-grow-table
	// gets an "add" button that clones its first row (blanked out) and a
	// per-row "remove" button, used by both the pricing tiers table and the
	// timetable table.
	document.addEventListener( 'click', function ( e ) {
		if ( e.target.classList.contains( 'wpd-grow-table-add' ) ) {
			var tbody = e.target.closest( '.wpd-grow-table' ).querySelector( 'tbody' );
			var template = tbody.querySelector( 'tr' );
			if ( ! template ) {
				return;
			}
			var newRow = template.cloneNode( true );
			newRow.querySelectorAll( 'input' ).forEach( function ( input ) {
				input.value = '';
			} );
			newRow.querySelectorAll( 'select' ).forEach( function ( select ) {
				select.selectedIndex = 0;
			} );
			tbody.appendChild( newRow );
			return;
		}

		if ( e.target.classList.contains( 'wpd-grow-table-remove' ) ) {
			var row = e.target.closest( 'tr' );
			var body = row.closest( 'tbody' );
			// Keep at least one row so "Add" always has something to clone
			// from; removing the last remaining row just blanks it.
			if ( body.querySelectorAll( 'tr' ).length > 1 ) {
				row.remove();
			} else {
				row.querySelectorAll( 'input' ).forEach( function ( input ) {
					input.value = '';
				} );
				row.querySelectorAll( 'select' ).forEach( function ( select ) {
					select.selectedIndex = 0;
				} );
			}
		}
	} );
} )();
