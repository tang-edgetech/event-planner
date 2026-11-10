/**
 * Tamago Roles admin helpers: search filters, group "select all", raw capability toggle,
 * confirmations, menu tree parent/child ticking and showing role lists only when needed.
 * Used by: Roles & Permissions screen, user profile, post editor box, term and menu screens.
 */
( function () {
	'use strict';

	function each( sel, root, fn ) {
		Array.prototype.forEach.call( ( root || document ).querySelectorAll( sel ), fn );
	}

	function refreshGroups( table ) {
		each( 'tr.tmg-roles-group', table, function ( g ) {
			var name = g.getAttribute( 'data-group' );
			var rows = name ? table.querySelectorAll( 'tr.tmg-roles-row[data-group="' + name + '"]' ) : [];
			var any = false;
			Array.prototype.forEach.call( rows, function ( r ) {
				if ( r.style.display !== 'none' ) {
					any = true;
				}
			} );
			if ( name ) {
				g.style.display = any ? '' : 'none';
			}
		} );
	}

	function applyFilters( target ) {
		var box = document.querySelector( target );
		if ( ! box ) {
			return;
		}
		var search = document.querySelector( '.tmg-roles-search[data-target="' + target + '"]' );
		var only = document.querySelector( '.tmg-roles-only-granted[data-target="' + target + '"]' );
		var q = search ? search.value.toLowerCase().trim() : '';
		each( '.tmg-roles-row', box, function ( row ) {
			var hit = ! q || ( row.getAttribute( 'data-search' ) || '' ).indexOf( q ) !== -1;
			if ( hit && only && only.checked ) {
				var cb = row.querySelector( 'input[type=checkbox]' );
				hit = cb && cb.checked;
			}
			row.style.display = hit ? '' : 'none';
		} );
		refreshGroups( box );
	}

	each( '.tmg-roles-search', null, function ( input ) {
		input.addEventListener( 'input', function () {
			applyFilters( input.getAttribute( 'data-target' ) );
		} );
		input.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Enter' ) {
				e.preventDefault();
			}
		} );
	} );

	each( '.tmg-roles-only-granted', null, function ( cb ) {
		cb.addEventListener( 'change', function () {
			applyFilters( cb.getAttribute( 'data-target' ) );
		} );
	} );

	each( '.tmg-roles-show-caps', null, function ( cb ) {
		cb.addEventListener( 'change', function () {
			var t = document.querySelector( cb.getAttribute( 'data-target' ) );
			if ( t ) {
				t.classList.toggle( 'show-caps', cb.checked );
			}
		} );
	} );

	each( '.tmg-group-all', null, function ( cb ) {
		cb.addEventListener( 'change', function () {
			var table = cb.closest( 'table' );
			var sel = 'tr.tmg-roles-row[data-group="' + cb.getAttribute( 'data-group' ) + '"] input[data-role="' + cb.getAttribute( 'data-role' ) + '"]:not(:disabled)';
			each( sel, table, function ( box ) {
				if ( box.closest( 'tr' ).style.display !== 'none' ) {
					box.checked = cb.checked;
				}
			} );
		} );
	} );

	each( 'form[data-confirm]', null, function ( form ) {
		form.addEventListener( 'submit', function ( e ) {
			if ( ! window.confirm( form.getAttribute( 'data-confirm' ) ) ) {
				e.preventDefault();
			}
		} );
	} );

	each( '.tmg-menu-parent', null, function ( cb ) {
		cb.addEventListener( 'change', function () {
			var li = cb.closest( 'li' );
			each( '.tmg-menu-child', li, function ( child ) {
				child.checked = cb.checked;
			} );
		} );
	} );

	function syncMode( select ) {
		var wrap = select.closest( '.tmg-roles-rule, .tmg-roles-menu-item' );
		var roles = wrap ? wrap.querySelector( '.tmg-roles-roles' ) : null;
		if ( roles ) {
			roles.style.display = select.value === 'roles' ? '' : 'none';
		}
	}
	each( 'select.tmg-roles-mode', null, function ( s ) {
		syncMode( s );
		s.addEventListener( 'change', function () {
			syncMode( s );
		} );
	} );
}() );
