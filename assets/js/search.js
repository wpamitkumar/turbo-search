/**
 * Turbo Search: Accessible Instant Search v1.0.0
 * Fully compliant with WAI-ARIA Combobox 1.2 & Apple VoiceOver screen reader.
 * Features: Command+K Spotlight Modal, Multi-Tab Category Dropdown, WooCommerce
 * Instant Add-to-Cart, ARIA live announcements, and CTR telemetry.
 */
( function () {
	'use strict';

	var cfg          = window.WPTS || {};
	var ROOT         = cfg.root       || '/wp-json/';
	var NONCE        = cfg.nonce      || '';
	var LANG         = cfg.lang       || '';
	var AJAX_URL     = cfg.ajax_url   || '';
	var AJAX_NONCE   = cfg.ajax_nonce || '';
	var IS_LOGGED_IN = !!cfg.is_logged_in;
	var I18N         = cfg.i18n || {};

	var ICON = {
		search: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>',
		voice:  '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" y1="19" x2="12" y2="23"/><line x1="8" y1="23" x2="16" y2="23"/></svg>',
		doc:    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>',
		empty:  '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/><line x1="8" y1="11" x2="14" y2="11"/></svg>',
		close:  '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>',
		clock:  '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>',
		star:   '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>',
		cart:   '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>'
	};

	function debounce( fn, ms ) {
		var t;
		return function () {
			var a = arguments, x = this;
			clearTimeout( t );
			t = setTimeout( function () { fn.apply( x, a ); }, ms );
		};
	}

	function throttle( fn, ms ) {
		var last = 0, t;
		return function () {
			var a = arguments, x = this, now = Date.now(), rem = ms - ( now - last );
			if ( rem <= 0 ) {
				clearTimeout( t );
				t = null;
				last = now;
				fn.apply( x, a );
			} else {
				clearTimeout( t );
				t = setTimeout( function () {
					last = Date.now();
					t = null;
					fn.apply( x, a );
				}, rem );
			}
		};
	}

	function esc( s ) {
		var d = document.createElement( 'div' );
		d.textContent = s || '';
		return d.innerHTML;
	}

	function readConfig( el ) {
		var d = el.dataset;
		return {
			placeholder:    d.placeholder || 'Search\u2026',
			postType:       ( d.postType || '' ).split( ',' ).map( function ( s ) { return s.trim(); } ).filter( Boolean ).join( ',' ),
			perPage:        parseInt( d.perPage, 10 ) || 8,
			debounce:       parseInt( d.debounce, 10 ),
			throttle:       parseInt( d.throttle, 10 ) || 0,
			minChars:       parseInt( d.minChars, 10 ) || 2,
			showType:       d.showType       !== '0',
			showExcerpt:    d.showExcerpt    !== '0',
			showVoice:      d.showVoice      !== '0',
			enableCommandK: d.enableCommandK !== '0',
			categoryTabs:   d.categoryTabs   !== '0',
			quickCart:      d.quickCart      !== '0',
			layout:         ( d.layout && d.layout !== '' ) ? d.layout : ( cfg.results_layout || 'list' ),
			newTab:         d.newTab         === '1',
			inputSize:      d.inputSize      || 'medium',
		};
	}

	/* VoiceOver Screen Reader Live Announcer */
	function announce( liveRegion, message ) {
		if ( ! liveRegion ) return;
		liveRegion.textContent = '';
		setTimeout( function () {
			liveRegion.textContent = message;
		}, 50 );
	}

	/* Floating Voice Notice / Tooltip */
	function showVoiceNotice( wrap, message ) {
		if ( ! wrap ) return;
		var existing = wrap.querySelector( '.wpts-voice-notice' );
		if ( existing ) existing.remove();

		var notice = document.createElement( 'div' );
		notice.className = 'wpts-voice-notice';
		notice.setAttribute( 'role', 'status' );
		notice.textContent = message;

		wrap.appendChild( notice );

		setTimeout( function () {
			notice.classList.add( 'is-fading' );
			setTimeout( function () {
				if ( notice.parentNode ) notice.parentNode.removeChild( notice );
			}, 260 );
		}, 4500 );
	}

	/* Track Result Click (CTR Beacon) */
	function trackClick( query, postId, position ) {
		try {
			var url  = ROOT + 'wpts/v1/track-click';
			var payload = JSON.stringify( { q: query, post_id: postId, position: position } );
			if ( navigator.sendBeacon ) {
				var blob = new Blob( [ payload ], { type: 'application/json' } );
				navigator.sendBeacon( url, blob );
			} else {
				fetch( url, {
					method:  'POST',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': NONCE },
					body:    payload,
					keepalive: true,
				} );
			}
		} catch ( e ) {}
	}

	/* WooCommerce Quick Add-to-Cart */
	function handleQuickAddToCart( btn, e ) {
		if ( e ) {
			e.preventDefault();
			e.stopPropagation();
			if ( e.stopImmediatePropagation ) e.stopImmediatePropagation();
		}

		var productId = btn.dataset.productId;
		if ( ! productId || btn.disabled ) return;

		var originalText = btn.innerHTML;
		btn.disabled  = true;
		btn.innerHTML = '⏳ Adding…';

		var fd = new FormData();
		fd.append( 'action', 'wpts_add_to_cart' );
		fd.append( 'product_id', productId );
		fd.append( 'quantity', '1' );
		if ( AJAX_NONCE ) fd.append( 'nonce', AJAX_NONCE );

		fetch( AJAX_URL || '/wp-admin/admin-ajax.php', {
			method: 'POST',
			body: fd,
			credentials: 'same-origin'
		} )
			.then( function ( r ) {
				return r.text().then( function ( text ) {
					try {
						return JSON.parse( text );
					} catch ( e ) {
						var jsonMatch = text.match( /\{[\s\S]*\}/ );
						if ( jsonMatch ) {
							try { return JSON.parse( jsonMatch[0] ); } catch ( err ) {}
						}
						return { added: true };
					}
				} );
			} )
			.then( function ( res ) {
				if ( res && ( res.fragments || res.data || res.added || res.cart_hash ) ) {
					btn.innerHTML = '✅ Added!';
					try {
						if ( window.jQuery && window.jQuery( document.body ) ) {
							var $body = window.jQuery( document.body );
							var $btn = window.jQuery( btn );
							var fragments = ( res && res.fragments ) || ( res && res.data && res.data.fragments ) || {};
							var cartHash = ( res && res.cart_hash ) || ( res && res.data && res.data.cart_hash ) || '';

							if ( fragments ) {
								for ( var key in fragments ) {
									if ( Object.prototype.hasOwnProperty.call( fragments, key ) ) {
										window.jQuery( key ).replaceWith( fragments[key] );
									}
								}
							}

							$body.trigger( 'added_to_cart', [ fragments, cartHash, $btn ] );
							$body.trigger( 'wc_fragment_refresh' );
							$body.trigger( 'wc_fragments_refreshed' );
						}
					} catch ( triggerErr ) {
						console.warn( 'WPTS mini-cart update notice:', triggerErr );
					}
				} else {
					btn.innerHTML = '⚠️ Error';
				}
				setTimeout( function () {
					btn.innerHTML = originalText;
					btn.disabled  = false;
				}, 2000 );
			} )
			.catch( function ( err ) {
				console.error( 'WPTS Add to Cart error:', err );
				btn.innerHTML = '⚠️ Error';
				setTimeout( function () {
					btn.innerHTML = originalText;
					btn.disabled  = false;
				}, 2000 );
			} );
	}

	/* Open / Close Dropdown State */
	function openResults( results, input ) {
		results.classList.add( 'is-open' );
		input.setAttribute( 'aria-expanded', 'true' );
	}

	function closeResults( results, input, state ) {
		results.classList.remove( 'is-open' );
		input.setAttribute( 'aria-expanded', 'false' );
		input.setAttribute( 'aria-activedescendant', '' );
		if ( state ) state.activeIndex = -1;
	}

	/* Active Result Highlight & ARIA Combobox Tracking */
	function highlightActive( results, input, state, liveRegion ) {
		var items = results.querySelectorAll( '.wpts-result:not([style*="display: none"])' );
		if ( ! items.length ) return;

		if ( state.activeIndex < 0 ) {
			state.activeIndex = items.length - 1;
		} else if ( state.activeIndex >= items.length ) {
			state.activeIndex = 0;
		}

		items.forEach( function ( el, i ) {
			var isCurrent = ( i === state.activeIndex );
			el.classList.toggle( 'is-active', isCurrent );
			el.setAttribute( 'aria-selected', isCurrent ? 'true' : 'false' );
			if ( isCurrent ) {
				input.setAttribute( 'aria-activedescendant', el.id );
				el.scrollIntoView( { block: 'nearest' } );

				var titleEl = el.querySelector( '.wpts-result-title' );
				var badgeEl = el.querySelector( '.wpts-result-badge' );
				var readout = ( titleEl ? titleEl.textContent : '' ) + ( badgeEl ? ', ' + badgeEl.textContent : '' ) + ', result ' + ( i + 1 ) + ' of ' + items.length;
				announce( liveRegion, readout );
			}
		} );
	}

	function fetchResults( query, page, c, container, spinner, results, input, state, liveRegion ) {
		// 1. Abort previous pending fetch requests
		if ( state.abortController ) {
			try { state.abortController.abort(); } catch ( e ) {}
		}
		state.abortController = window.AbortController ? new AbortController() : null;

		spinner.classList.add( 'is-active' );
		state.activeIndex = -1;
		input.setAttribute( 'aria-activedescendant', '' );

		var params = new URLSearchParams( { q: query, page: page, per_page: c.perPage } );
		if ( c.postType ) params.set( 'post_type', c.postType );
		if ( LANG && LANG.length <= 5 && LANG.indexOf( '_' ) === -1 ) params.set( 'lang', LANG );
		var url = ROOT + 'wpts/v1/search?' + params.toString();

		function doFetch( useAjax ) {
			var fetchUrl = useAjax
				? ( AJAX_URL + '?action=wpts_search&nonce=' + AJAX_NONCE + '&' + params.toString() )
				: url;
			var fetchOpts = {
				headers: { 'Accept': 'application/json' },
				credentials: 'same-origin',
				signal: state.abortController ? state.abortController.signal : undefined
			};
			if ( ! useAjax && NONCE ) fetchOpts.headers['X-WP-Nonce'] = NONCE;
			return fetch( fetchUrl, fetchOpts );
		}

		doFetch( false )
			.then( function ( r ) {
				if ( r.status === 403 && AJAX_URL ) return doFetch( true );
				return r;
			} )
			.then( function ( r ) { return r.json(); } )
			.then( function ( data ) {
				spinner.classList.remove( 'is-active' );
				var resultData = data || { hits: [], found: 0 };
				renderResults( resultData, query, page, c, container, spinner, results, input, state, liveRegion );
			} )
			.catch( function ( err ) {
				if ( err && err.name === 'AbortError' ) return;
				spinner.classList.remove( 'is-active' );
				results.innerHTML =
					'<div class="wpts-empty" role="status">' +
						'<div class="wpts-empty-icon">' + ICON.empty + '</div>' +
						'<div class="wpts-empty-title">Search Unavailable</div>' +
						'<p class="wpts-empty-desc">Search service temporarily unavailable.</p>' +
					'</div>';
				announce( liveRegion, 'Search service temporarily unavailable.' );
				openResults( results, input );
			} );
	}

	function renderResults( data, query, page, c, container, spinner, results, input, state, liveRegion ) {
		var hits       = data.hits || [], found = data.found || 0;
		var didYouMean = data.did_you_mean || null;
		results.innerHTML = '';
		results.className = 'wpts-results is-open wpts-layout-' + ( c.layout || 'list' );
		state.activeIndex = -1;
		input.setAttribute( 'aria-activedescendant', '' );

		// Spoken feedback for VoiceOver
		if ( found === 0 ) {
			var zeroMsg = 'No results found for ' + query + '.';
			if ( didYouMean ) zeroMsg += ' Did you mean ' + didYouMean + '?';
			announce( liveRegion, zeroMsg );
		} else {
			announce( liveRegion, found + ' results found for ' + query + '. Use up and down arrow keys to navigate.' );
		}

		// Did you mean suggestion
		if ( didYouMean ) {
			var dym = document.createElement( 'div' );
			dym.className = 'wpts-did-you-mean';
			dym.setAttribute( 'role', 'status' );
			dym.innerHTML = ( I18N.did_you_mean || 'Did you mean:' ) + ' <a href="#" role="button" aria-label="Search for ' + esc( didYouMean ) + ' instead">' + esc( didYouMean ) + '</a>';
			dym.querySelector( 'a' ).addEventListener( 'click', function ( e ) {
				e.preventDefault();
				input.value = didYouMean;
				announce( liveRegion, 'Searching for suggestion: ' + didYouMean );
				input.dispatchEvent( new Event( 'input' ) );
			} );
			results.appendChild( dym );
		}

		if ( ! hits || ! hits.length ) {
			var emptyDiv = document.createElement( 'div' );
			emptyDiv.className = 'wpts-empty';
			emptyDiv.setAttribute( 'role', 'status' );
			emptyDiv.innerHTML =
				'<div class="wpts-empty-icon">' + ICON.empty + '</div>' +
				'<div class="wpts-empty-title">No results found</div>' +
				'<p class="wpts-empty-desc">No matches for "<strong>' + esc( query ) + '</strong>". Try checking for typos or searching for a different keyword.</p>';
			results.appendChild( emptyDiv );
			openResults( results, input );
			return;
		}

		// Multi-Tab Category Tabs in Header
		var categories = {};
		hits.forEach( function ( h ) {
			var pt = h.post_type || 'post';
			categories[pt] = ( categories[pt] || 0 ) + 1;
		} );

		var catKeys = Object.keys( categories );
		var header = document.createElement( 'div' );
		header.className = 'wpts-results-header';

		if ( catKeys.length > 1 ) {
			var tabsWrap = document.createElement( 'div' );
			tabsWrap.className = 'wpts-cat-tabs';
			tabsWrap.innerHTML = '<button type="button" class="wpts-cat-tab is-active" data-cat="all">All (' + hits.length + ')</button>' +
				catKeys.map( function ( k ) {
					return '<button type="button" class="wpts-cat-tab" data-cat="' + esc( k ) + '">' + esc( k.toUpperCase() ) + ' (' + categories[k] + ')</button>';
				} ).join( '' );

			tabsWrap.addEventListener( 'click', function ( e ) {
				var tabBtn = e.target.closest( '.wpts-cat-tab' );
				if ( ! tabBtn ) return;
				tabsWrap.querySelectorAll( '.wpts-cat-tab' ).forEach( function ( b ) { b.classList.remove( 'is-active' ); } );
				tabBtn.classList.add( 'is-active' );
				var filterCat = tabBtn.dataset.cat;

				results.querySelectorAll( '.wpts-result' ).forEach( function ( item ) {
					var itemCat = item.dataset.postType;
					if ( filterCat === 'all' || itemCat === filterCat ) {
						item.style.display = 'flex';
					} else {
						item.style.display = 'none';
					}
				} );
			} );
			header.appendChild( tabsWrap );
		} else {
			header.innerHTML = '<span>' + found + ' result' + ( found !== 1 ? 's' : '' ) + ' for <strong>' + esc( query ) + '</strong></span>';
		}
		results.appendChild( header );

		var list = document.createElement( 'div' );
		list.className = 'wpts-results-list';
		var maxH = getComputedStyle( container ).getPropertyValue( '--wpts-max-height' ).trim();
		if ( maxH ) list.style.maxHeight = maxH;

		hits.forEach( function ( hit, index ) {
			var a = document.createElement( 'a' );
			a.className = 'wpts-result ' + ( hit.is_product ? 'wpts-result--product' : '' );
			a.href      = hit.url || '#';
			a.id        = 'wpts-opt-' + state.uid + '-' + index;
			a.dataset.postType = hit.post_type || 'post';
			a.setAttribute( 'role', 'option' );
			a.setAttribute( 'aria-selected', 'false' );
			a.setAttribute( 'aria-label', ( hit.title ? hit.title.replace( /<[^>]+>/g, '' ) : 'Result' ) + ( hit.post_type ? ' (' + hit.post_type + ')' : '' ) );

			if ( c.newTab ) {
				a.target = '_blank';
				a.rel    = 'noopener noreferrer';
			}

			a.addEventListener( 'click', function ( e ) {
				if ( e.target && e.target.closest( '.wpts-quick-cart-btn' ) ) {
					e.preventDefault();
					e.stopPropagation();
					return false;
				}
				trackClick( query, hit.post_id, index + 1 );
			} );

			var thumbHtml = hit.thumbnail_url
				? '<span class="wpts-result-thumb"><img src="' + esc( hit.thumbnail_url ) + '" alt="" loading="lazy" width="44" height="44"></span>'
				: '<span class="wpts-result-icon">' + ICON.doc + '</span>';

			// WooCommerce Product details
			var productMetaHtml = '';
			if ( hit.is_product || hit.post_type === 'product' ) {
				productMetaHtml = '<div class="wpts-product-row">' +
					( hit.price_html ? '<span class="wpts-price-badge">' + hit.price_html + '</span>' : '' ) +
					( hit.on_sale ? '<span class="wpts-sale-pill">SALE</span>' : '' ) +
					( typeof hit.in_stock !== 'undefined'
							? ( hit.in_stock ? '<span class="wpts-stock-pill is-instock">In Stock</span>' : '<span class="wpts-stock-pill is-outofstock">Out of Stock</span>' )
							: '' ) +
					( hit.in_stock !== false
							? '<button type="button" class="wpts-quick-cart-btn" data-product-id="' + hit.post_id + '" title="Quick Add to Cart">' + ICON.cart + ' Add</button>'
							: '' ) +
				'</div>';
			}

			a.innerHTML =
				thumbHtml +
				'<span class="wpts-result-body">' +
					'<span class="wpts-result-title">' + ( hit.title || esc( hit.url ) ) + '</span>' +
					( c.showExcerpt && hit.excerpt ? '<span class="wpts-result-excerpt">' + hit.excerpt + '</span>' : '' ) +
					productMetaHtml +
				'</span>' +
				( c.showType && hit.post_type && ! hit.is_product ? '<span class="wpts-result-badge">' + esc( hit.post_type ) + '</span>' : '' );

			var cartBtn = a.querySelector( '.wpts-quick-cart-btn' );
			if ( cartBtn ) {
				cartBtn.addEventListener( 'click', function ( e ) {
					e.preventDefault();
					e.stopPropagation();
					if ( e.stopImmediatePropagation ) e.stopImmediatePropagation();
					handleQuickAddToCart( cartBtn, e );
					return false;
				} );
			}

			list.appendChild( a );
		} );
		results.appendChild( list );

		// Dropdown Pagination
		var totalPages = Math.ceil( found / c.perPage );
		if ( totalPages > 1 ) {
			var pagWrap = document.createElement( 'div' );
			pagWrap.className = 'wpts-dropdown-pagination';
			pagWrap.addEventListener( 'click', function ( e ) {
				e.stopPropagation();
			} );

			var prevBtn = document.createElement( 'button' );
			prevBtn.type = 'button';
			prevBtn.className = 'wpts-page-btn wpts-page-prev';
			prevBtn.innerHTML = '&larr; Prev';
			prevBtn.disabled = ( page <= 1 );
			prevBtn.setAttribute( 'aria-label', 'Previous page of search results' );
			prevBtn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				e.stopPropagation();
				if ( page > 1 ) {
					fetchResults( query, page - 1, c, container, spinner, results, input, state, liveRegion );
				}
			} );
			pagWrap.appendChild( prevBtn );

			var numbersWrap = document.createElement( 'div' );
			numbersWrap.className = 'wpts-page-numbers-wrap';

			// Show up to 5 page numbers centered on current page
			var startP = Math.max( 1, page - 2 );
			var endP   = Math.min( totalPages, startP + 4 );
			if ( endP - startP < 4 ) {
				startP = Math.max( 1, endP - 4 );
			}

			for ( var p = startP; p <= endP; p++ ) {
				( function ( targetP ) {
					var pBtn = document.createElement( 'button' );
					pBtn.type = 'button';
					pBtn.className = 'wpts-page-btn' + ( targetP === page ? ' is-active' : '' );
					pBtn.textContent = targetP;
					pBtn.setAttribute( 'aria-label', 'Page ' + targetP );
					if ( targetP === page ) pBtn.setAttribute( 'aria-current', 'page' );
					pBtn.addEventListener( 'click', function ( e ) {
						e.preventDefault();
						e.stopPropagation();
						if ( targetP !== page ) {
							fetchResults( query, targetP, c, container, spinner, results, input, state, liveRegion );
						}
					} );
					numbersWrap.appendChild( pBtn );
				} )( p );
			}
			pagWrap.appendChild( numbersWrap );

			var nextBtn = document.createElement( 'button' );
			nextBtn.type = 'button';
			nextBtn.className = 'wpts-page-btn wpts-page-next';
			nextBtn.innerHTML = 'Next &rarr;';
			nextBtn.disabled = ( page >= totalPages );
			nextBtn.setAttribute( 'aria-label', 'Next page of search results' );
			nextBtn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				e.stopPropagation();
				if ( page < totalPages ) {
					fetchResults( query, page + 1, c, container, spinner, results, input, state, liveRegion );
				}
			} );
			pagWrap.appendChild( nextBtn );

			results.appendChild( pagWrap );
		}

		// Save recent search
		try {
			var recent = JSON.parse( sessionStorage.getItem( 'wpts_recent' ) || '[]' );
			if ( query && recent.indexOf( query ) === -1 ) {
				recent.unshift( query );
				sessionStorage.setItem( 'wpts_recent', JSON.stringify( recent.slice( 0, 5 ) ) );
			}
		} catch ( e ) {}

		var siteRoot = ROOT.replace( /\/wp-json\/?$/, '/' );
		var footer   = document.createElement( 'div' );
		footer.className = 'wpts-results-footer';
		footer.innerHTML = '<a href="' + siteRoot + '?s=' + encodeURIComponent( query ) + '">' + ( I18N.view_all || 'View all results →' ) + '</a>';
		results.appendChild( footer );
		openResults( results, input );
	}

	function showRecentSearches( results, input, c, state, liveRegion ) {
		try {
			var recent = JSON.parse( sessionStorage.getItem( 'wpts_recent' ) || '[]' );
			if ( ! recent.length ) return;

			results.innerHTML =
				'<div class="wpts-results-header" aria-hidden="true"><span>' + ( I18N.recent || 'Recent Searches' ) + '</span></div>' +
				'<div class="wpts-results-list">' +
				recent.map( function ( q, idx ) {
					return '<button class="wpts-result wpts-recent-item" type="button" role="option" id="wpts-rec-' + state.uid + '-' + idx + '" aria-selected="false" aria-label="Recent search: ' + esc( q ) + '">' +
						'<span class="wpts-result-icon">' + ICON.clock + '</span>' +
						'<span class="wpts-result-body"><span class="wpts-result-title">' + esc( q ) + '</span></span>' +
					'</button>';
				} ).join( '' ) +
				'</div>';

			results.querySelectorAll( '.wpts-recent-item' ).forEach( function ( btn ) {
				btn.addEventListener( 'click', function () {
					var val = this.querySelector( '.wpts-result-title' ).textContent;
					input.value = val;
					announce( liveRegion, 'Searching recent: ' + val );
					input.dispatchEvent( new Event( 'input' ) );
				} );
			} );

			openResults( results, input );
		} catch ( e ) {}
	}

	/* Initialise Widget Instance */
	var widgetCount = 0;

	function initSearchWidget( container ) {
		if ( ! container || container._wptsInit ) return;
		container._wptsInit = true;
		widgetCount++;

		var c = readConfig( container );
		var state = { uid: widgetCount, activeIndex: -1, lastQuery: '', abortCtrl: null };

		var wrap = document.createElement( 'div' );
		wrap.className = 'wpts-input-wrap wpts-size-' + c.inputSize;

		var iconSpan = document.createElement( 'span' );
		iconSpan.className = 'wpts-input-icon';
		iconSpan.innerHTML = ICON.search;

		var input = document.createElement( 'input' );
		input.type        = 'search';
		input.className   = 'wpts-input';
		input.placeholder = c.placeholder;
		input.autocomplete = 'off';
		input.autocorrect  = 'off';
		input.autocapitalize = 'off';
		input.spellcheck   = false;

		// ARIA 1.2 Combobox Attributes
		input.setAttribute( 'role', 'combobox' );
		input.setAttribute( 'aria-expanded', 'false' );
		input.setAttribute( 'aria-haspopup', 'listbox' );
		input.setAttribute( 'aria-autocomplete', 'list' );
		input.setAttribute( 'aria-controls', 'wpts-listbox-' + state.uid );
		input.setAttribute( 'aria-activedescendant', '' );
		input.setAttribute( 'aria-label', c.placeholder );

		var spinner = document.createElement( 'span' );
		spinner.className = 'wpts-spinner';
		spinner.setAttribute( 'aria-hidden', 'true' );

		var clearBtn = document.createElement( 'button' );
		clearBtn.type      = 'button';
		clearBtn.className = 'wpts-clear wpts-btn-clear';
		clearBtn.innerHTML = ICON.close;
		clearBtn.setAttribute( 'aria-label', I18N.clear || 'Clear search' );

		var voiceBtn = null;
		var activeRecognition = null;
		var SpeechRecognitionClass = window.SpeechRecognition ||
																window.webkitSpeechRecognition ||
																window.mozSpeechRecognition ||
																window.msSpeechRecognition;

		if ( c.showVoice ) {
			voiceBtn = document.createElement( 'button' );
			voiceBtn.type      = 'button';
			voiceBtn.className = 'wpts-voice-btn wpts-btn-voice';
			voiceBtn.innerHTML = ICON.voice;
			voiceBtn.setAttribute( 'aria-label', I18N.voice_search || 'Voice search' );
			voiceBtn.setAttribute( 'aria-pressed', 'false' );
			voiceBtn.title     = I18N.voice_search || 'Voice search';

			var stopListening = function () {
				if ( activeRecognition ) {
					try {
						if ( activeRecognition.abort ) {
							activeRecognition.abort();
						} else {
							activeRecognition.stop();
						}
					} catch ( e ) {}
					activeRecognition = null;
				}
				voiceBtn.classList.remove( 'is-listening' );
				voiceBtn.setAttribute( 'aria-pressed', 'false' );
				input.setAttribute( 'placeholder', c.placeholder );
			};

			var startListening = function () {
				if ( ! SpeechRecognitionClass ) {
					// Check if failure is due to non-HTTPS origin
					if ( window.isSecureContext === false && location.protocol !== 'https:' && location.hostname !== 'localhost' && location.hostname !== '127.0.0.1' ) {
						showVoiceNotice( wrap, I18N.voice_https || '🔒 Voice search requires a secure HTTPS connection.' );
					} else {
						showVoiceNotice( wrap, I18N.voice_firefox || '🎙️ Voice search is supported in Chrome, Edge, Safari, and Opera. On Firefox, please type your query.' );
					}
					announce( liveRegion, I18N.voice_unsupported || 'Voice search is not supported in this browser. Please type your search.' );
					input.focus();
					return;
				}

				stopListening();

				try {
					var rec = new SpeechRecognitionClass();

					// Standardize BCP 47 language code: replace underscores (e.g. en_US -> en-US)
					var userLang = ( LANG || navigator.language || 'en-US' ).replace( /_/g, '-' );
					rec.lang = userLang;
					rec.continuous = false;
					rec.interimResults = true;
					rec.maxAlternatives = 1;

					rec.onstart = function () {
						activeRecognition = rec;
						voiceBtn.classList.add( 'is-listening' );
						voiceBtn.setAttribute( 'aria-pressed', 'true' );
						input.setAttribute( 'placeholder', I18N.voice_listening || 'Listening… Speak now' );
						announce( liveRegion, I18N.voice_listening || 'Listening for voice search… Speak now' );
					};

					var finalTranscript = '';

					rec.onresult = function ( e ) {
						var interimTranscript = '';
						for ( var i = e.resultIndex; i < e.results.length; ++i ) {
							var piece = e.results[i][0].transcript;
							if ( e.results[i].isFinal ) {
								finalTranscript += piece;
							} else {
								interimTranscript += piece;
							}
						}

						var currentText = ( finalTranscript || interimTranscript ).trim();
						if ( currentText ) {
							input.value = currentText;
							clearBtn.classList.add( 'is-visible' );
							announce( liveRegion, 'Voice query: ' + currentText );
							// Live feedback as the user speaks
							input.dispatchEvent( new Event( 'input' ) );
						}
					};

					rec.onerror = function ( e ) {
						var err = e && e.error ? e.error : '';
						stopListening();

						if ( err === 'not-allowed' || err === 'service-not-allowed' ) {
							showVoiceNotice( wrap, I18N.voice_denied || '⚠️ Microphone access was denied. Please allow microphone permission in browser settings.' );
							announce( liveRegion, 'Microphone access denied.' );
						} else if ( err === 'network' ) {
							showVoiceNotice( wrap, '⚠️ Speech recognition network error. Please try again or type.' );
							announce( liveRegion, 'Voice recognition network error.' );
						} else if ( err === 'audio-capture' ) {
							showVoiceNotice( wrap, '⚠️ No microphone detected on your device.' );
							announce( liveRegion, 'No microphone detected.' );
						} else if ( err === 'no-speech' ) {
							// User didn't speak before timeout, silently stop without aggressive popup
							announce( liveRegion, 'No speech detected. Please try again.' );
						} else {
							announce( liveRegion, 'Voice recognition ended.' );
						}
					};

					rec.onend = function () {
						stopListening();
					};

					rec.start();
				} catch ( err ) {
					stopListening();
					if ( window.isSecureContext === false && location.protocol !== 'https:' && location.hostname !== 'localhost' ) {
						showVoiceNotice( wrap, '🔒 Voice search requires a secure HTTPS connection.' );
					} else {
						showVoiceNotice( wrap, '⚠️ Voice search error. Please try typing.' );
					}
				}
			};

			voiceBtn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				e.stopPropagation();
				if ( voiceBtn.classList.contains( 'is-listening' ) ) {
					stopListening();
				} else {
					startListening();
				}
			} );
		}

		var kbdHint = null;
		if ( c.enableCommandK ) {
			kbdHint = document.createElement( 'kbd' );
			kbdHint.className = 'wpts-kbd-hint';
			kbdHint.textContent = '⌘K';
			kbdHint.title = 'Open Quick Search (⌘K / Ctrl+K)';
			kbdHint.addEventListener( 'click', function () {
				var spotModal = document.getElementById( 'wpts-spotlight-modal' );
				if ( spotModal ) {
					spotModal.classList.add( 'is-open' );
					var sInput = spotModal.querySelector( '.wpts-spotlight-input' );
					if ( sInput ) sInput.focus();
				}
			} );
		}

		var results = document.createElement( 'div' );
		results.id        = 'wpts-listbox-' + state.uid;
		results.className = 'wpts-results wpts-layout-' + c.layout;
		results.setAttribute( 'role', 'listbox' );
		results.setAttribute( 'aria-label', 'Search suggestions' );

		// VoiceOver Live Region
		var liveRegion = document.createElement( 'div' );
		liveRegion.className = 'wpts-sr-only';
		liveRegion.setAttribute( 'role', 'status' );
		liveRegion.setAttribute( 'aria-live', 'polite' );
		liveRegion.setAttribute( 'aria-atomic', 'true' );

		wrap.appendChild( iconSpan );
		wrap.appendChild( input );
		wrap.appendChild( spinner );
		if ( kbdHint ) wrap.appendChild( kbdHint );
		wrap.appendChild( clearBtn );
		if ( voiceBtn ) wrap.appendChild( voiceBtn );
		wrap.appendChild( liveRegion );

		container.appendChild( wrap );
		container.appendChild( results );

		// Input handlers
		var ms = ! isNaN( c.debounce ) ? c.debounce : ( parseInt( cfg.debounce_ms, 10 ) || 200 );
		var runSearch = debounce( function ( val ) {
			if ( val.length < c.minChars ) {
				closeResults( results, input, state );
				return;
			}
			fetchResults( val, 1, c, container, spinner, results, input, state, liveRegion );
		}, ms );

		input.addEventListener( 'input', function () {
			var v = input.value.trim();
			clearBtn.classList.toggle( 'is-visible', v.length > 0 );
			if ( v === state.lastQuery ) return;
			state.lastQuery = v;
			runSearch( v );
		} );

		input.addEventListener( 'focus', function () {
			if ( ! input.value.trim() ) {
				showRecentSearches( results, input, c, state, liveRegion );
			} else if ( results.children.length ) {
				openResults( results, input );
			}
		} );

		clearBtn.addEventListener( 'click', function () {
			input.value = '';
			clearBtn.classList.remove( 'is-visible' );
			closeResults( results, input, state );
			input.focus();
			announce( liveRegion, 'Search cleared' );
		} );

		// Keyboard navigation
		input.addEventListener( 'keydown', function ( e ) {
			if ( ! results.classList.contains( 'is-open' ) ) return;

			var items = results.querySelectorAll( '.wpts-result:not([style*="display: none"])' );
			if ( ! items.length ) return;

			if ( e.key === 'ArrowDown' ) {
				e.preventDefault();
				state.activeIndex = ( state.activeIndex + 1 ) % items.length;
				highlightActive( results, input, state, liveRegion );
			} else if ( e.key === 'ArrowUp' ) {
				e.preventDefault();
				state.activeIndex = ( state.activeIndex - 1 + items.length ) % items.length;
				highlightActive( results, input, state, liveRegion );
			} else if ( e.key === 'Enter' ) {
				if ( state.activeIndex >= 0 && items[state.activeIndex] ) {
					e.preventDefault();
					items[state.activeIndex].click();
				}
			} else if ( e.key === 'Escape' ) {
				closeResults( results, input, state );
			}
		} );

		document.addEventListener( 'click', function ( e ) {
			if ( ! container.contains( e.target ) && document.contains( e.target ) ) {
				closeResults( results, input, state );
			}
		} );
	}

	/* Command + K "Spotlight" Quick Palette Modal */
	function initCommandKModal() {
		var modal = document.getElementById( 'wpts-spotlight-modal' );
		if ( ! modal ) {
			modal = document.createElement( 'div' );
			modal.id = 'wpts-spotlight-modal';
			modal.className = 'wpts-spotlight-modal';
			modal.setAttribute( 'role', 'dialog' );
			modal.setAttribute( 'aria-modal', 'true' );
			modal.setAttribute( 'aria-label', 'Spotlight Search' );
			modal.innerHTML =
				'<div class="wpts-spotlight-backdrop"></div>' +
				'<div class="wpts-spotlight-box">' +
					'<div class="wpts-spotlight-header">' +
						'<span class="wpts-spotlight-search-icon">' + ICON.search + '</span>' +
						'<input type="search" class="wpts-spotlight-input" placeholder="Type a command or search\u2026" autocomplete="off" spellcheck="false">' +
						'<kbd class="wpts-spotlight-kbd">ESC</kbd>' +
					'</div>' +
					'<div class="wpts-spotlight-body">' +
						'<div class="wpts-search" data-min-chars="2" data-show-type="1" data-show-excerpt="1" data-per-page="8"></div>' +
					'</div>' +
				'</div>';
			document.body.appendChild( modal );

			var spotSearchContainer = modal.querySelector( '.wpts-search' );
			if ( spotSearchContainer ) {
				initSearchWidget( spotSearchContainer );
				var spotInput = modal.querySelector( '.wpts-spotlight-input' );
				var internalInput = spotSearchContainer.querySelector( '.wpts-input' );

				if ( spotInput && internalInput ) {
					spotInput.addEventListener( 'input', function () {
						internalInput.value = spotInput.value;
						internalInput.dispatchEvent( new Event( 'input' ) );
					} );
				}
			}

			var backdrop = modal.querySelector( '.wpts-spotlight-backdrop' );
			if ( backdrop ) {
				backdrop.addEventListener( 'click', closeModal );
			}
		}

		function openModal() {
			modal.classList.add( 'is-open' );
			var input = modal.querySelector( '.wpts-spotlight-input' );
			setTimeout( function () { if ( input ) input.focus(); }, 50 );
		}

		function closeModal() {
			modal.classList.remove( 'is-open' );
		}

		window.addEventListener( 'keydown', function ( e ) {
			var isK = ( e.key === 'k' || e.key === 'K' ) && ( e.metaKey || e.ctrlKey );
			var isSlash = e.key === '/' && ! [ 'INPUT', 'TEXTAREA', 'SELECT' ].includes( ( e.target && e.target.tagName ) || '' );

			if ( isK || isSlash ) {
				e.preventDefault();
				if ( modal.classList.contains( 'is-open' ) ) closeModal();
				else openModal();
			} else if ( e.key === 'Escape' && modal.classList.contains( 'is-open' ) ) {
				closeModal();
			}
		} );
	}

	function initSearchResultsPage() {
		var pageWrap = document.querySelector( '[data-wpts-results-page]' );
		if ( ! pageWrap ) return;

		var input = pageWrap.querySelector( '#wpts-results-query-input' );
		var query = input ? input.value.trim() : '';

		var cards = pageWrap.querySelectorAll( '.wpts-result-card' );
		cards.forEach( function ( card, index ) {
			var postId = parseInt( card.dataset.postId, 10 ) || 0;
			var link   = card.querySelector( '.wpts-card-title a' );
			if ( link && postId > 0 ) {
				link.addEventListener( 'click', function () {
					trackClick( query, postId, index + 1 );
				} );
			}
		} );

		var btns = pageWrap.querySelectorAll( '.wpts-layout-toggle-btns [data-wpts-set-layout]' );
		var container = pageWrap.querySelector( '.wpts-grid-results' );
		if ( btns.length && container ) {
			btns.forEach( function ( btn ) {
				btn.addEventListener( 'click', function ( e ) {
					e.preventDefault();
					var targetLayout = btn.getAttribute( 'data-wpts-set-layout' );
					btns.forEach( function ( b ) { b.classList.remove( 'button-primary' ); } );
					btn.classList.add( 'button-primary' );
					if ( targetLayout === 'list' ) {
						container.classList.remove( 'is-grid' );
						container.classList.add( 'is-list' );
					} else {
						container.classList.remove( 'is-list' );
						container.classList.add( 'is-grid' );
					}
					if ( window.history && window.history.replaceState ) {
						var url = new URL( window.location.href );
						url.searchParams.set( 'layout', targetLayout );
						window.history.replaceState( {}, '', url.toString() );
					}
				} );
			} );
		}
	}

	// DOM Ready Initializer
	function boot() {
		document.querySelectorAll( '.wpts-search, .wpts-search-block, [data-wpts-search]' ).forEach( initSearchWidget );
		initCommandKModal();
		initSearchResultsPage();
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}

	window.WPTS_INIT = initSearchWidget;
} )();
