/* global wp */
/**
 * Block-editor UI for the wp-dansal blocks (#123).
 *
 * The blocks themselves are registered on the server (blocks/<name>/block.json)
 * and rendered server-side by the same code as the shortcodes; all this script
 * supplies is the editor side: a live preview and inspector controls. It uses
 * only the `wp.*` globals WordPress ships, so no build step is needed.
 */
( function ( wp ) {
	'use strict';

	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var __ = wp.i18n.__;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var PanelBody = wp.components.PanelBody;
	var TextControl = wp.components.TextControl;
	var SelectControl = wp.components.SelectControl;
	var ToggleControl = wp.components.ToggleControl;
	var ServerSideRender = wp.serverSideRender;

	var VIEWS_EVENTS = [
		{ value: 'list', label: __( 'List', 'wp-dansal' ) },
		{ value: 'calendar', label: __( 'Monthly calendar', 'wp-dansal' ) },
		{ value: 'mini', label: __( 'Mini calendar', 'wp-dansal' ) },
		{ value: 'simple', label: __( 'Compact list', 'wp-dansal' ) },
		{ value: 'map', label: __( 'Map', 'wp-dansal' ) },
		{ value: 'map+list', label: __( 'Map and list', 'wp-dansal' ) },
		{ value: 'map+simple', label: __( 'Map and compact list', 'wp-dansal' ) },
	];
	var VIEWS_MAP_LIST = [
		{ value: 'map+list', label: __( 'Map and list', 'wp-dansal' ) },
		{ value: 'map', label: __( 'Map', 'wp-dansal' ) },
		{ value: 'list', label: __( 'List', 'wp-dansal' ) },
	];

	/**
	 * One control per declarative field: { name, label, type, options?, help? }.
	 * type: text (default) | number | select | toggle.
	 */
	function control( field, props ) {
		var value = props.attributes[ field.name ];
		var onChange = function ( next ) {
			var update = {};
			update[ field.name ] = next;
			props.setAttributes( update );
		};
		var common = { key: field.name, label: field.label, help: field.help };

		if ( field.type === 'select' ) {
			return el( SelectControl, Object.assign( {}, common, { value: value, options: field.options, onChange: onChange } ) );
		}
		if ( field.type === 'toggle' ) {
			return el( ToggleControl, Object.assign( {}, common, { checked: !! value, onChange: onChange } ) );
		}
		if ( field.type === 'number' ) {
			return el( TextControl, Object.assign( {}, common, {
				type: 'number',
				min: 1,
				value: value,
				onChange: function ( next ) {
					var n = parseInt( next, 10 );
					onChange( isNaN( n ) ? undefined : n );
				},
			} ) );
		}
		return el( TextControl, Object.assign( {}, common, { value: value || '', onChange: onChange } ) );
	}

	function panelsFor( panels, props ) {
		return panels.map( function ( panel ) {
			return el(
				PanelBody,
				{ key: panel.title, title: panel.title, initialOpen: !! panel.open },
				panel.fields.map( function ( field ) {
					return control( field, props );
				} )
			);
		} );
	}

	function register( name, panels ) {
		wp.blocks.registerBlockType( name, {
			edit: function ( props ) {
				return el(
					Fragment,
					null,
					el( InspectorControls, null, panelsFor( panels, props ) ),
					el( 'div', useBlockProps(), el( ServerSideRender, { block: name, attributes: props.attributes } ) )
				);
			},
			// Rendered on the server, so nothing is saved into the post content.
			save: function () {
				return null;
			},
		} );
	}

	var FILTERS = function () {
		return [
			{ name: 'tag', label: __( 'Tag', 'wp-dansal' ), help: __( 'Only events with this tag, e.g. bal-folk.', 'wp-dansal' ) },
			{ name: 'country', label: __( 'Countries', 'wp-dansal' ), help: __( 'Comma-separated 2-letter codes, e.g. DE,FR.', 'wp-dansal' ) },
		];
	};

	register( 'wp-dansal/events', [
		{
			title: __( 'Display', 'wp-dansal' ),
			open: true,
			fields: [
				{ name: 'view', label: __( 'View', 'wp-dansal' ), type: 'select', options: VIEWS_EVENTS },
				{ name: 'limit', label: __( 'Number of events', 'wp-dansal' ), type: 'number' },
				{ name: 'showPast', label: __( 'Include past events', 'wp-dansal' ), type: 'toggle' },
				{ name: 'showTypes', label: __( 'Show event-type icons', 'wp-dansal' ), type: 'toggle' },
			],
		},
		{
			title: __( 'Filter', 'wp-dansal' ),
			fields: [
				{ name: 'tag', label: __( 'Tag', 'wp-dansal' ) },
				{
					name: 'type',
					label: __( 'Event types', 'wp-dansal' ),
					help: __( 'Comma-separated: ball, workshop, festival, other.', 'wp-dansal' ),
				},
				{ name: 'location', label: __( 'Location (WordPress post ID)', 'wp-dansal' ) },
			],
		},
		{
			title: __( 'Other organizations', 'wp-dansal' ),
			fields: [
				{ name: 'org', label: __( 'Organizations', 'wp-dansal' ), help: __( 'Comma-separated dansal organization IDs.', 'wp-dansal' ) },
				{ name: 'country', label: __( 'Countries', 'wp-dansal' ), help: __( 'Comma-separated 2-letter codes, e.g. DE,FR.', 'wp-dansal' ) },
				{ name: 'excludeOwnOrg', label: __( 'Exclude our own organization', 'wp-dansal' ), type: 'toggle' },
				{ name: 'lat', label: __( 'Latitude', 'wp-dansal' ) },
				{ name: 'lon', label: __( 'Longitude', 'wp-dansal' ) },
				{ name: 'radiusKm', label: __( 'Radius (km)', 'wp-dansal' ) },
				{ name: 'bbox', label: __( 'Bounding box', 'wp-dansal' ), help: __( 'minLng,minLat,maxLng,maxLat', 'wp-dansal' ) },
			],
		},
	] );

	register( 'wp-dansal/locations', [
		{
			title: __( 'Filter', 'wp-dansal' ),
			open: true,
			fields: FILTERS().concat( [ { name: 'location', label: __( 'Single location (WordPress post ID)', 'wp-dansal' ) } ] ),
		},
	] );

	register( 'wp-dansal/nearby', [
		{
			title: __( 'Display', 'wp-dansal' ),
			open: true,
			fields: [
				{ name: 'view', label: __( 'View', 'wp-dansal' ), type: 'select', options: VIEWS_MAP_LIST },
				{ name: 'radiusKm', label: __( 'Radius (km)', 'wp-dansal' ), type: 'number' },
				{ name: 'limit', label: __( 'Number of events', 'wp-dansal' ), type: 'number' },
				{ name: 'excludeOwnOrg', label: __( 'Exclude our own organization', 'wp-dansal' ), type: 'toggle' },
				{ name: 'showCancelled', label: __( 'Include cancelled events', 'wp-dansal' ), type: 'toggle' },
			],
		},
		{
			title: __( 'Filter', 'wp-dansal' ),
			fields: [
				{ name: 'tag', label: __( 'Tag', 'wp-dansal' ) },
				{ name: 'type', label: __( 'Event types', 'wp-dansal' ), help: __( 'Comma-separated: ball, workshop, festival, other.', 'wp-dansal' ) },
			],
		},
		{
			title: __( 'Fallback position', 'wp-dansal' ),
			fields: [
				{ name: 'lat', label: __( 'Latitude', 'wp-dansal' ), help: __( 'Used until the visitor shares their location. Empty: your organization’s home.', 'wp-dansal' ) },
				{ name: 'lon', label: __( 'Longitude', 'wp-dansal' ) },
			],
		},
	] );

	register( 'wp-dansal/festivals', [
		{
			title: __( 'Display', 'wp-dansal' ),
			open: true,
			fields: [
				{ name: 'view', label: __( 'View', 'wp-dansal' ), type: 'select', options: VIEWS_MAP_LIST },
				{ name: 'limit', label: __( 'Number of festivals', 'wp-dansal' ), type: 'number' },
				{ name: 'showPast', label: __( 'Include past festivals', 'wp-dansal' ), type: 'toggle' },
			],
		},
		{
			title: __( 'Filter', 'wp-dansal' ),
			fields: [
				{ name: 'tag', label: __( 'Tag', 'wp-dansal' ) },
				{ name: 'org', label: __( 'Organizations', 'wp-dansal' ), help: __( 'Comma-separated dansal organization IDs.', 'wp-dansal' ) },
				{ name: 'country', label: __( 'Countries', 'wp-dansal' ), help: __( 'Comma-separated 2-letter codes, e.g. DE,FR.', 'wp-dansal' ) },
				{ name: 'lat', label: __( 'Latitude', 'wp-dansal' ) },
				{ name: 'lon', label: __( 'Longitude', 'wp-dansal' ) },
				{ name: 'radiusKm', label: __( 'Radius (km)', 'wp-dansal' ) },
				{ name: 'bbox', label: __( 'Bounding box', 'wp-dansal' ), help: __( 'minLng,minLat,maxLng,maxLat', 'wp-dansal' ) },
			],
		},
	] );

	register( 'wp-dansal/calendar-embed', [
		{
			title: __( 'Filter', 'wp-dansal' ),
			open: true,
			fields: [
				{ name: 'org', label: __( 'Organizations', 'wp-dansal' ), help: __( 'Comma-separated dansal organization IDs.', 'wp-dansal' ) },
				{ name: 'location', label: __( 'Locations', 'wp-dansal' ), help: __( 'Comma-separated dansal location IDs.', 'wp-dansal' ) },
				{ name: 'from', label: __( 'From (YYYY-MM-DD)', 'wp-dansal' ) },
				{ name: 'to', label: __( 'To (YYYY-MM-DD)', 'wp-dansal' ) },
				{ name: 'tag', label: __( 'Tag', 'wp-dansal' ) },
				{ name: 'lang', label: __( 'Language', 'wp-dansal' ) },
			],
		},
		{
			title: __( 'Size', 'wp-dansal' ),
			fields: [
				{ name: 'width', label: __( 'Width', 'wp-dansal' ), help: __( 'e.g. 100% or 800px.', 'wp-dansal' ) },
				{ name: 'height', label: __( 'Height', 'wp-dansal' ), help: __( 'Pixels, or a CSS length.', 'wp-dansal' ) },
			],
		},
	] );

	/**
	 * Block equivalents of the legacy widgets (#123): the Upcoming Events widget
	 * renders [dansal_events view="simple"], the Mini Calendar widget
	 * [dansal_events view="mini"] — so they are ready-made variations of the
	 * events block rather than separate blocks.
	 */
	wp.blocks.registerBlockVariation( 'wp-dansal/events', [
		{
			name: 'upcoming',
			title: __( 'Upcoming dance events', 'wp-dansal' ),
			description: __( 'A compact list of the next events — the block version of the Upcoming Events widget.', 'wp-dansal' ),
			icon: 'list-view',
			attributes: { view: 'simple', limit: 5, showTypes: true },
			scope: [ 'inserter' ],
			isActive: [ 'view' ],
		},
		{
			name: 'mini-calendar',
			title: __( 'Dance mini calendar', 'wp-dansal' ),
			description: __( 'A small month calendar with event markers — the block version of the Mini Calendar widget.', 'wp-dansal' ),
			icon: 'calendar',
			attributes: { view: 'mini' },
			scope: [ 'inserter' ],
			isActive: [ 'view' ],
		},
	] );
}( window.wp ) );
