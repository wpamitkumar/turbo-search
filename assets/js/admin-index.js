/**
 * Turbo Search: Admin Index Manager JS
 * Chunked re-index runner with progress bar and engine status widgets.
 */
( function () {
	'use strict';

	var cfg     = window.WPTS_Admin || {};
	var nonce   = cfg.nonce   || '';
	var ajaxUrl = cfg.ajax_url|| '';

	document.addEventListener('DOMContentLoaded', function () {
		var btn          = document.getElementById('wpts-reindex-chunked-btn');
		var wrap         = document.getElementById('wpts-reindex-progress-wrap');
		var bar          = document.getElementById('wpts-reindex-progress-bar');
		var label        = document.getElementById('wpts-reindex-progress-label');
		var text         = document.getElementById('wpts-reindex-progress-text');
		var statusEl     = document.getElementById('wpts-reindex-status');
		var statIndexed  = document.getElementById('wpts-stat-indexed');
		var statCovPct   = document.getElementById('wpts-stat-coverage-pct');
		var statCovBar   = document.getElementById('wpts-stat-coverage-bar');

		var BATCH_SIZE = 50;
		var totalPubEl = document.getElementById('wpts-stat-total');
		var TOTAL_PUB  = totalPubEl ? parseInt( totalPubEl.textContent.replace(/,/g, ''), 10 ) || 0 : 0;

		function updateStatsTable( indexedCount ) {
			if ( statIndexed ) statIndexed.textContent = indexedCount.toLocaleString();
			var pct = TOTAL_PUB > 0 ? Math.min( 100, Math.round( ( indexedCount / TOTAL_PUB ) * 100 ) ) : 0;
			if ( statCovPct ) statCovPct.textContent = pct + '%';
			if ( statCovBar ) statCovBar.style.width  = pct + '%';
		}

		if ( btn ) {
			btn.addEventListener('click', function () {
				if ( ! confirm( 'Re-index all posts? This runs in safe batches and will not timeout.' ) ) {
					return;
				}

				btn.disabled        = true;
				btn.textContent     = 'Re-indexing…';
				if ( wrap ) wrap.style.display = 'block';
				if ( statusEl ) statusEl.textContent = '';
				if ( bar ) bar.style.width = '0%';
				if ( label ) label.textContent = '0%';
				if ( text ) text.textContent = '';

				runChunk( 1 );
			});
		}

		function runChunk( page ) {
			var body = new URLSearchParams();
			body.set( 'action', 'wpts_reindex_chunk' );
			body.set( 'nonce', nonce );
			body.set( 'page', page );
			body.set( 'batch', BATCH_SIZE );

			fetch( ajaxUrl, {
				method: 'POST',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: body.toString()
			} )
				.then( function ( r ) { return r.json(); } )
				.then( function ( res ) {
					if ( ! res.success ) {
						if ( statusEl ) {
							statusEl.style.color = '#dc2626';
							statusEl.textContent = '❌ ' + ( res.data || 'Reindex failed.' );
						}
						resetButton();
						return;
					}

					var d   = res.data;
					var pct = d.max_pages > 0 ? Math.min( 100, Math.round( ( d.page / d.max_pages ) * 100 ) ) : 100;

					if ( bar ) bar.style.width = pct + '%';
					if ( label ) label.textContent = pct + '%';
					if ( text ) text.textContent = 'Page ' + d.page + ' of ' + d.max_pages + ' - ' + ( d.total || 0 ).toLocaleString() + ' posts total';

					var runningEst = Math.min( d.total || 0, d.page * BATCH_SIZE );
					updateStatsTable( runningEst );

					if ( d.done ) {
						if ( bar ) bar.style.width = '100%';
						if ( label ) label.textContent = '100%';
						if ( statusEl ) {
							statusEl.style.color = '#16a34a';
							statusEl.textContent = '✅ Done! ' + ( d.indexed_count || 0 ).toLocaleString() + ' posts indexed.';
						}
						updateStatsTable( d.indexed_count || 0 );
						resetButton();
					} else {
						runChunk( d.page + 1 );
					}
				} )
				.catch( function () {
					if ( statusEl ) {
						statusEl.style.color = '#dc2626';
						statusEl.textContent = '❌ Network error during reindex. Click again to resume.';
					}
					resetButton();
				} );
		}

		function resetButton() {
			if ( btn ) {
				btn.disabled    = false;
				btn.textContent = '↺ Re-index All Posts';
			}
		}
	});
} )();
