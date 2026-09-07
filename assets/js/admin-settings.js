/**
 * Turbo Search: Admin Settings JS
 * Manages tab switching, synonyms CRUD, field weight sliders, and connection test buttons.
 */
( function () {
	'use strict';

	var cfg     = window.WPTS_Admin || {};
	var nonce   = cfg.nonce   || '';
	var ajaxUrl = cfg.ajax_url|| '';

	document.addEventListener('DOMContentLoaded', function () {

		// Field weight live range labels
		document.querySelectorAll('.wpts-weight-slider').forEach( function ( slider ) {
			var output = document.getElementById( slider.id + '_val' );
			if ( output ) {
				slider.addEventListener('input', function () {
					output.textContent = this.value + 'x';
				});
			}
		});

		// Synonyms CRUD
		var addSynBtn    = document.getElementById('wpts-add-synonym-btn');
		var synInput     = document.getElementById('wpts-new-synonym-input');
		var synListBody  = document.getElementById('wpts-synonyms-table-body');
		var synStatusMsg = document.getElementById('wpts-synonym-status');

		if ( addSynBtn && synInput ) {
			addSynBtn.addEventListener('click', function () {
				var words = synInput.value.trim();
				if ( ! words ) return;

				addSynBtn.disabled = true;
				var body = new URLSearchParams();
				body.set( 'action', 'wpts_save_synonym' );
				body.set( 'nonce', nonce );
				body.set( 'words', words );

				fetch( ajaxUrl, {
					method: 'POST',
					headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
					body: body.toString()
				} )
					.then( function ( r ) { return r.json(); } )
					.then( function ( res ) {
						if ( res.success ) {
							synInput.value = '';
							if ( synStatusMsg ) {
								synStatusMsg.style.color = '#16a34a';
								synStatusMsg.textContent = '✅ ' + ( res.data.message || 'Saved' );
							}
							// Reload page or insert row
							setTimeout( function () { location.reload(); }, 600 );
						} else {
							if ( synStatusMsg ) {
								synStatusMsg.style.color = '#dc2626';
								synStatusMsg.textContent = '❌ ' + ( res.data || 'Error saving' );
							}
						}
					} )
					.finally( function () {
						addSynBtn.disabled = false;
					} );
			});
		}

		// Delete synonym row
		document.querySelectorAll('.wpts-delete-synonym-btn').forEach( function ( btn ) {
			btn.addEventListener('click', function () {
				if ( ! confirm( 'Delete this synonym pair?' ) ) return;
				var id = this.dataset.id;
				var tr = this.closest('tr');

				var body = new URLSearchParams();
				body.set( 'action', 'wpts_delete_synonym' );
				body.set( 'nonce', nonce );
				body.set( 'id', id );

				fetch( ajaxUrl, {
					method: 'POST',
					headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
					body: body.toString()
				} )
					.then( function ( r ) { return r.json(); } )
					.then( function ( res ) {
						if ( res.success && tr ) {
							tr.remove();
						}
					} );
			});
		});

		// Test Connection Buttons
		function attachTestBtn( btnId, statusId, actionName, extraFields ) {
			var btn = document.getElementById( btnId );
			var st  = document.getElementById( statusId );
			if ( ! btn || ! st ) return;

			btn.addEventListener('click', function () {
				btn.disabled = true;
				st.style.color = '#64748b';
				st.textContent = 'Testing connection…';

				var body = new URLSearchParams();
				body.set( 'action', actionName );
				body.set( 'nonce', nonce );

				if ( extraFields ) {
					extraFields.forEach( function ( f ) {
						var input = document.querySelector( '[name="' + f + '"]' );
						if ( input ) body.set( f, input.value );
					} );
				}

				fetch( ajaxUrl, {
					method: 'POST',
					headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
					body: body.toString()
				} )
					.then( function ( r ) { return r.json(); } )
					.then( function ( res ) {
						if ( res.success ) {
							st.style.color = '#16a34a';
							st.textContent = '✅ ' + ( res.data || 'Connected!' );
						} else {
							st.style.color = '#dc2626';
							st.textContent = '❌ ' + ( res.data || 'Connection failed.' );
						}
					} )
					.catch( function () {
						st.style.color = '#dc2626';
						st.textContent = '❌ Network request error.';
					} )
					.finally( function () {
						btn.disabled = false;
					} );
			});
		}

		attachTestBtn( 'wpts-test-typesense-btn', 'wpts-typesense-test-status', 'wpts_typesense_status' );
		attachTestBtn( 'wpts-test-es-btn', 'wpts-es-test-status', 'wpts_elasticsearch_status' );
		attachTestBtn( 'wpts-test-redis-btn', 'wpts-redis-test-status', 'wpts_test_redis', [ 'redis_host', 'redis_port', 'redis_password', 'redis_db' ] );
		attachTestBtn( 'wpts-test-mc-btn', 'wpts-mc-test-status', 'wpts_test_memcached', [ 'memcached_host', 'memcached_port' ] );
	});
} )();
