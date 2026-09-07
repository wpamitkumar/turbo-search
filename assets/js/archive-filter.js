/**
 * Turbo Search: Instant Archive / Category Filter
 * Filters server-rendered archive posts live in the DOM as you type.
 */
( function () {
	'use strict';

	function initArchiveFilter() {
		var filterInput = document.querySelector('[data-wpts-archive-filter]');
		var container   = document.querySelector('[data-wpts-archive-container]') || document.querySelector('.posts-grid') || document.querySelector('.products');

		if ( ! filterInput || ! container ) return;

		var items = container.querySelectorAll('article, .product, .post');

		filterInput.addEventListener('input', function () {
			var query = filterInput.value.trim().toLowerCase();

			items.forEach(function (item) {
				var text = item.textContent.toLowerCase();
				if ( '' === query || text.indexOf(query) !== -1 ) {
					item.style.display = '';
				} else {
					item.style.display = 'none';
				}
			});
		});
	}

	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initArchiveFilter);
	else initArchiveFilter();
} )();

