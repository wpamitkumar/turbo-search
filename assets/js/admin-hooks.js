/**
 * Turbo Search: Developer Hooks Reference JS
 */
( function () {
	'use strict';

	document.addEventListener('DOMContentLoaded', function () {
		var searchInput = document.getElementById('wpts-hooks-search');
		var cards       = document.querySelectorAll('.wpts-hook-card');
		var categories  = document.querySelectorAll('.wpts-hooks-category');
		var emptyMsg    = document.getElementById('wpts-hooks-empty');

		if ( searchInput ) {
			searchInput.addEventListener('input', function () {
				var term = this.value.trim().toLowerCase();
				var anyVisible = false;

				cards.forEach( function ( card ) {
					var match = card.dataset.search.indexOf( term ) !== -1;
					card.style.display = match ? '' : 'none';
					if ( match ) anyVisible = true;
				} );

				categories.forEach( function ( heading ) {
					var next = heading.nextElementSibling;
					var hasVisible = false;
					while ( next && ! next.classList.contains('wpts-hooks-category') ) {
						if ( next.classList.contains('wpts-hook-card') && next.style.display !== 'none' ) {
							hasVisible = true;
						}
						next = next.nextElementSibling;
					}
					heading.style.display = hasVisible ? '' : 'none';
				} );

				if ( emptyMsg ) emptyMsg.style.display = anyVisible ? 'none' : 'block';
			});
		}

		document.querySelectorAll('.wpts-copy-example').forEach( function ( btn ) {
			btn.addEventListener('click', function () {
				var code = btn.getAttribute('data-code');
				if ( navigator.clipboard && code ) {
					navigator.clipboard.writeText( code ).then( function () {
						var orig = btn.textContent;
						btn.textContent = 'Copied!';
						setTimeout( function () { btn.textContent = orig; }, 1500 );
					} );
				}
			});
		} );
	});
} )();
