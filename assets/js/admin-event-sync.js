/* global wp, wpdEventSync */
/**
 * Force/Cancel confirmation on save while dansal has changes made by someone
 * else (#167). The server sends a pending-update hint on the edit screen; when
 * it is present, saving asks first. Force sends X-WPD-Force-Overwrite so the
 * server PATCHes dansal without the If-Match precondition. Cancel saves nothing.
 */
( function ( wp, cfg ) {
	if ( ! wp || ! wp.data || ! wp.apiFetch || ! cfg || ! cfg.pending ) {
		return;
	}

	var forceNext = false;
	var pending = true;

	wp.apiFetch.use( function ( options, next ) {
		if ( forceNext && options.path && options.path.indexOf( '/wp/v2/events/' ) !== -1 ) {
			forceNext = false;
			options.headers = Object.assign( {}, options.headers, { 'X-WPD-Force-Overwrite': '1' } );
		}
		return next( options );
	} );

	function askOverwrite() {
		return new Promise( function ( resolve ) {
			var dialog = document.createElement( 'dialog' );
			dialog.className = 'wpd-sync-dialog';

			var message = document.createElement( 'p' );
			message.textContent = cfg.i18n.message;
			dialog.appendChild( message );

			var buttons = document.createElement( 'p' );

			var cancel = document.createElement( 'button' );
			cancel.type = 'button';
			cancel.className = 'button';
			cancel.textContent = cfg.i18n.cancel;
			cancel.addEventListener( 'click', function () {
				dialog.close();
			} );

			var force = document.createElement( 'button' );
			force.type = 'button';
			force.className = 'button button-primary';
			force.textContent = cfg.i18n.force;
			force.addEventListener( 'click', function () {
				resolve( 'force' );
				dialog.close();
			} );

			buttons.appendChild( cancel );
			buttons.appendChild( document.createTextNode( ' ' ) );
			buttons.appendChild( force );
			dialog.appendChild( buttons );

			dialog.addEventListener( 'close', function () {
				resolve( 'cancel' );
				dialog.remove();
			} );

			document.body.appendChild( dialog );
			dialog.showModal();
		} );
	}

	var editor = wp.data.dispatch( 'core/editor' );
	var originalSavePost = editor.savePost;

	editor.savePost = function ( options ) {
		if ( ! pending ) {
			return originalSavePost( options );
		}
		return askOverwrite().then( function ( choice ) {
			if ( 'force' !== choice ) {
				return undefined;
			}
			forceNext = true;
			return Promise.resolve( originalSavePost( options ) ).then( function ( result ) {
				pending = false;
				return result;
			} );
		} );
	};
} )( window.wp, window.wpdEventSync );
