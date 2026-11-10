/**
 * Tamago Import & Export admin screen: runs import steps over AJAX with a progress bar
 * (resumable: reloading the page continues the run), and shows the custom field box in the
 * mapping step.
 */
( function () {
	'use strict';

	function $( sel, root ) {
		return ( root || document ).querySelector( sel );
	}

	/* Mapping: "Other custom field…" shows a text box for the key. */
	document.querySelectorAll( '.tmg-io-map select.tmg-io-target' ).forEach( function ( select ) {
		var input = select.parentNode.querySelector( '.tmg-io-custom' );
		if ( ! input ) {
			return;
		}
		var sync = function () {
			input.classList.toggle( 'is-visible', '__meta' === select.value );
		};
		select.addEventListener( 'change', sync );
		sync();
	} );

	/* Progress. */
	var box = $( '.tmg-io-progress' );
	if ( ! box || '1' !== box.getAttribute( 'data-running' ) || ! window.tmgIo ) {
		return;
	}
	var bar = $( 'progress', box );
	var status = $( '.tmg-io-status', box );
	var run = box.getAttribute( 'data-run' );
	var failures = 0;

	function step() {
		var body = new FormData();
		body.append( 'action', 'tmg_io_step' );
		body.append( '_ajax_nonce', window.tmgIo.nonce );
		body.append( 'run', run );
		fetch( window.tmgIo.ajax, { method: 'POST', body: body, credentials: 'same-origin' } )
			.then( function ( r ) {
				return r.json();
			} )
			.then( function ( res ) {
				if ( ! res || ! res.success ) {
					status.textContent = window.tmgIo.i18n.error + ' ' + ( res && res.data && res.data.message ? res.data.message : '' );
					return;
				}
				failures = 0;
				var d = res.data;
				bar.value = d.percent;
				status.textContent = d.label + ' — ' + d.done + ' / ' + d.total;
				if ( d.finished ) {
					status.textContent = window.tmgIo.i18n.done;
					window.location.reload();
					return;
				}
				window.setTimeout( step, d.busy ? 2000 : 50 );
			} )
			.catch( function () {
				failures++;
				if ( failures > 5 ) {
					status.textContent = window.tmgIo.i18n.resume;
					return;
				}
				status.textContent = window.tmgIo.i18n.retry;
				window.setTimeout( step, 3000 * failures );
			} );
	}
	status.textContent = window.tmgIo.i18n.working;
	step();
} )();
