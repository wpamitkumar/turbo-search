/**
 * Turbo Search: Modern React Admin SPA Application
 * Built with WordPress wp.element (React) and WP REST API.
 */
( function () {
	'use strict';

	var wpEl = window.wp && window.wp.element;
	if ( ! wpEl ) {
		console.error( 'Turbo Search: wp.element is not available.' );
		return;
	}

	var createElement = wpEl.createElement;
	var useState      = wpEl.useState;
	var useEffect     = wpEl.useEffect;
	var useCallback   = wpEl.useCallback;
	var useMemo       = wpEl.useMemo;
	var useRef        = wpEl.useRef;

	var cfg     = window.WPTS_ADMIN_APP || {};
	var REST    = cfg.rest_url || '/wp-json/wpts/v1/admin/';
	var NONCE   = cfg.nonce    || '';

	function api( endpoint, method, data ) {
		method = method || 'GET';
		var opts = {
			method: method,
			headers: {
				'Accept': 'application/json',
				'X-WP-Nonce': NONCE,
			}
		};
		if ( data && ( method === 'POST' || method === 'PUT' || method === 'DELETE' ) ) {
			opts.headers['Content-Type'] = 'application/json';
			opts.body = JSON.stringify( data );
		}
		return fetch( REST + endpoint, opts ).then( function ( r ) {
			if ( ! r.ok ) {
				return r.json().then( function ( err ) { throw err; } );
			}
			return r.json();
		} );
	}

	function getTabFromUrl( defaultTab ) {
		var searchParams = new URLSearchParams( window.location.search );
		var urlTab = searchParams.get( 'tab' );
		if ( urlTab ) return urlTab;
		var hashTab = window.location.hash.replace( '#', '' );
		if ( hashTab ) return hashTab;
		return defaultTab || 'general';
	}

	/* Main App Component */
	function AdminApp() {
		var currentPage = cfg.current_page || 'wpts-dashboard';
		var defaultTab  = cfg.initial_tab || (
			currentPage === 'wpts-settings' ? 'general' : (
				currentPage === 'wpts-tracking' ? 'searches' : (
					currentPage === 'wpts-cache' ? 'general' : (
						currentPage === 'wpts-docs' ? 'docs' : (
							currentPage === 'wpts-hooks' ? 'hooks' : (
								currentPage === 'wpts-index' ? 'indexer' : 'dashboard'
							)
						)
					)
				)
			)
		);

		var bootstrapData = ( window.WPTS_ADMIN_APP && window.WPTS_ADMIN_APP.bootstrap && window.WPTS_ADMIN_APP.bootstrap.config ) || null;

		var [ tab, setTab ]                       = useState( getTabFromUrl( defaultTab ) );
		var [ config, setConfig ]                 = useState( bootstrapData );
		var [ settings, setSettings ]             = useState( ( bootstrapData && bootstrapData.settings ) || {} );
		var [ synonyms, setSynonyms ]             = useState( [] );
		var [ synonymsLoaded, setSynonymsLoaded ] = useState( false );
		var [ analytics, setAnalytics ]           = useState( null );
		var [ hooks, setHooks ]                   = useState( [] );
		var [ hooksLoaded, setHooksLoaded ]       = useState( false );
		var [ loading, setLoading ]               = useState( ! bootstrapData );
		var [ error, setError ]                   = useState( null );
		var [ saving, setSaving ]                 = useState( false );
		var [ toast, setToast ]                   = useState( null );
		var [ isDirty, setIsDirty ]               = useState( false );

		// Indexer state
		var [ indexing, setIndexing ]   = useState( false );
		var [ indexProg, setIndexProg ] = useState( { page: 0, max_pages: 0, total: 0, pct: 0, done: false, status: '' } );

		// Live Auto-Refresh State
		var [ autoRefreshRate, setAutoRefreshRate ] = useState( function () {
			try {
				var saved = localStorage.getItem( 'wpts_auto_refresh_rate' );
				if ( saved !== null ) return parseInt( saved, 10 );
			} catch ( e ) {}
			if ( bootstrapData && bootstrapData.settings && bootstrapData.settings.admin_auto_refresh !== undefined ) {
				return parseInt( bootstrapData.settings.admin_auto_refresh, 10 );
			}
			return 30;
		} );
		var [ lastUpdated, setLastUpdated ] = useState( new Date() );
		var [ isPolling, setIsPolling ]     = useState( false );

		var showToast = function ( msg, type ) {
			setToast( { msg: msg, type: type || 'success' } );
			setTimeout( function () { setToast( null ); }, 3500 );
		};

		// Sync tab when browser Back / Forward buttons are pressed
		useEffect( function () {
			var onPopState = function () {
				setTab( getTabFromUrl( defaultTab ) );
			};
			window.addEventListener( 'popstate', onPopState );
			return function () {
				window.removeEventListener( 'popstate', onPopState );
			};
		}, [ defaultTab ] );

		// Load analytics data (supports optional isRefresh to bypass transient caching)
		var loadAnalytics = useCallback( function ( days, isRefresh ) {
			var d = days || 30;
			var endpoint = 'analytics?days=' + d + ( isRefresh ? '&refresh=1' : '' );
			return api( endpoint ).then( function ( data ) {
				setAnalytics( data || null );
				setLastUpdated( new Date() );
				return data;
			} ).catch( function ( err ) {
				console.error( 'Analytics load error:', err );
			} );
		}, [] );

		// Quiet background analytics refresh without full blocking spinner
		var refreshLiveAnalytics = useCallback( function () {
			setIsPolling( true );
			return Promise.all( [
				loadAnalytics( 30, true ),
				api( 'config' )
			] ).then( function ( res ) {
				if ( res && res[1] ) {
					setConfig( res[1] );
				}
				setLastUpdated( new Date() );
				setIsPolling( false );
			} ).catch( function () {
				setIsPolling( false );
			} );
		}, [ loadAnalytics ] );

		// Automatic live polling timer
		useEffect( function () {
			if ( autoRefreshRate <= 0 ) return;
			if ( currentPage !== 'wpts-dashboard' && currentPage !== 'wpts-tracking' ) return;

			var timer = setInterval( function () {
				refreshLiveAnalytics();
			}, autoRefreshRate * 1000 );

			return function () {
				clearInterval( timer );
			};
		}, [ autoRefreshRate, currentPage, refreshLiveAnalytics ] );

		var handleAutoRefreshChange = function ( e ) {
			var rate = parseInt( e.target.value, 10 );
			setAutoRefreshRate( rate );
			try {
				localStorage.setItem( 'wpts_auto_refresh_rate', rate );
			} catch ( err ) {}
			updateSetting( 'admin_auto_refresh', rate );
			if ( rate > 0 ) {
				refreshLiveAnalytics();
			}
		};

		// Lazy-load analytics only on Dashboard or Tracking pages
		useEffect( function () {
			if ( ( currentPage === 'wpts-dashboard' || currentPage === 'wpts-tracking' ) && ! analytics ) {
				loadAnalytics( 30 );
			}
		}, [ currentPage, analytics, loadAnalytics ] );

		// Lazy-load synonyms only when Synonyms tab is active
		useEffect( function () {
			if ( tab === 'synonyms' && ! synonymsLoaded ) {
				api( 'synonyms' ).then( function ( res ) {
					setSynonyms( res && res.synonyms ? res.synonyms : [] );
					setSynonymsLoaded( true );
				} ).catch( function () {
					setSynonymsLoaded( true );
				} );
			}
		}, [ tab, synonymsLoaded ] );

		// Lazy-load dev hooks only when Hooks tab/page is active
		useEffect( function () {
			if ( ( currentPage === 'wpts-hooks' || tab === 'hooks' ) && ! hooksLoaded ) {
				api( 'hooks' ).then( function ( res ) {
					setHooks( res && res.hooks ? res.hooks : [] );
					setHooksLoaded( true );
				} ).catch( function () {
					setHooksLoaded( true );
				} );
			}
		}, [ currentPage, tab, hooksLoaded ] );

		// Load configuration and ensure live synchronization with server
		var loadConfig = useCallback( function () {
			if ( ! bootstrapData ) {
				setLoading( true );
			}
			setError( null );
			api( 'config' ).then( function ( conf ) {
				if ( conf && conf.settings ) {
					setConfig( conf );
					setSettings( conf.settings );
					settingsRef.current = conf.settings;
					if ( window.WPTS_ADMIN_APP && window.WPTS_ADMIN_APP.bootstrap && window.WPTS_ADMIN_APP.bootstrap.config ) {
						window.WPTS_ADMIN_APP.bootstrap.config.settings = conf.settings;
						window.WPTS_ADMIN_APP.bootstrap.config.active_engine = conf.settings.search_engine || conf.active_engine || 'mysql';
					}
				}
				setLoading( false );
			} ).catch( function ( err ) {
				console.error( 'Turbo Search loadConfig error:', err );
				setLoading( false );
				if ( ! bootstrapData ) {
					setError( ( err && err.message ) || 'Failed to load configuration via REST API.' );
					showToast( 'Failed to load configuration.', 'error' );
				}
			} );
		}, [ bootstrapData ] );

		useEffect( function () {
			loadConfig();
		}, [ loadConfig ] );

		var settingsRef = useRef( settings );
		useEffect( function () {
			settingsRef.current = settings;
		}, [ settings ] );

		var updateSetting = function ( keyOrPatch, val ) {
			var patch = {};
			if ( typeof keyOrPatch === 'object' && keyOrPatch !== null ) {
				patch = keyOrPatch;
			} else {
				patch[ keyOrPatch ] = val;
			}
			settingsRef.current = Object.assign( {}, settingsRef.current, patch );
			setSettings( function ( prev ) {
				return Object.assign( {}, prev, patch );
			} );
			setIsDirty( true );
		};

		var updateBatch = function ( patch ) {
			updateSetting( patch );
		};

		var saveSettings = function () {
			setSaving( true );
			var toSave = Object.assign( {}, settingsRef.current );
			api( 'config', 'POST', toSave )
				.then( function ( res ) {
					setSaving( false );
					if ( res && res.success ) {
						var serverSettings = ( res && res.settings ) ? res.settings : {};
						var updatedSettings = Object.assign( {}, toSave, serverSettings );
						settingsRef.current = updatedSettings;
						setSettings( updatedSettings );
						if ( window.WPTS_ADMIN_APP && window.WPTS_ADMIN_APP.bootstrap && window.WPTS_ADMIN_APP.bootstrap.config ) {
							window.WPTS_ADMIN_APP.bootstrap.config.settings = updatedSettings;
							window.WPTS_ADMIN_APP.bootstrap.config.active_engine = updatedSettings.search_engine || 'mysql';
						}
						setConfig( function ( prev ) {
							if ( ! prev ) return prev;
							return Object.assign( {}, prev, {
								settings: updatedSettings,
								active_engine: updatedSettings.search_engine || ( res && res.active_engine ) || prev.active_engine,
								cache_driver: updatedSettings.cache_driver || ( res && res.cache_driver ) || prev.cache_driver
							} );
						} );
						setIsDirty( false );
						showToast( res.message || 'Settings saved!' );
					} else {
						showToast( ( res && res.message ) ? res.message : 'Error saving settings.', 'error' );
					}
				} )
				.catch( function ( err ) {
					setSaving( false );
					var msg = ( err && err.message ) ? err.message : 'Network error while saving.';
					showToast( msg, 'error' );
				} );
		};

		var changeTab = function ( newTab ) {
			setTab( newTab );
			var searchParams = new URLSearchParams( window.location.search );
			searchParams.set( 'page', currentPage );
			searchParams.set( 'tab', newTab );
			var newUrl = window.location.pathname + '?' + searchParams.toString();
			window.history.pushState( { tab: newTab }, '', newUrl );
		};

		if ( loading ) {
			return createElement( 'div', { style: { padding: 40, textAlign: 'center', color: '#64748b' } },
				createElement( 'span', { className: 'spinner is-active', style: { float: 'none', margin: '0 auto 10px' } } ),
				createElement( 'p', null, 'Loading Turbo Search settings…' )
			);
		}

		if ( error ) {
			return createElement( 'div', { className: 'notice notice-error', style: { padding: 20, margin: '20px 0' } },
				createElement( 'p', { style: { fontWeight: 600 } }, '⚠️ Error loading Turbo Search configuration: ' + error ),
				createElement( 'button', { type: 'button', className: 'button button-primary', onClick: loadConfig }, 'Retry Loading' )
			);
		}

		// Settings Sub-Tabs
		var settingsTabs = [
			{ id: 'general',   label: '⚙️ General' },
			{ id: 'weights',   label: '⚖️ Field Weights' },
			{ id: 'synonyms',  label: '📖 Synonyms' },
			{ id: 'coverage',  label: '🗂️ Content Coverage' },
			{ id: 'engines',   label: '🚀 Search Engines' },
			{ id: 'vector',    label: '🧠 AI Vector Search' },
			{ id: 'frontend',  label: '🎨 Frontend & UX' },
			{ id: 'security',  label: '🔐 Security & GDPR' },
			{ id: 'backup',    label: '📦 Export / Import' },
		];

		// Tracking Sub-Tabs
		var trackingTabs = [
			{ id: 'searches', label: '🔎 Search Log' },
			{ id: 'ctr',      label: '🎯 CTR & Ranking' },
			{ id: 'top',      label: '📈 Top Queries' },
			{ id: 'zero',     label: '🚫 Zero Results Gaps' },
			{ id: 'noclick',  label: '👁️ No-Click Queries' },
			{ id: 'ab',       label: '🧪 A/B Testing' },
			{ id: 'trending', label: '🔥 Trending Searches' },
			{ id: 'exports',  label: '⬇️ CSV Exports' },
		];

		// Cache Sub-Tabs
		var cacheTabs = [
			{ id: 'general',   label: '⚡ Cache Drivers & TTL' },
			{ id: 'redis',     label: '🔴 Redis Server' },
			{ id: 'memcached', label: '🔵 Memcached Server' },
			{ id: 'cdn',       label: '🌐 CDN Caching Headers' },
			{ id: 'flush',     label: '🧹 Flush Cache' },
		];

		var pageTitle = '🔍 Turbo Search';
		if ( currentPage === 'wpts-settings' ) pageTitle = '⚙️ Search Settings';
		else if ( currentPage === 'wpts-tracking' ) pageTitle = '📊 Search Tracking & Analytics';
		else if ( currentPage === 'wpts-cache' ) pageTitle = '⚡ Cache & Performance';
		else if ( currentPage === 'wpts-index' ) pageTitle = '🔄 Index Manager';
		else if ( currentPage === 'wpts-hooks' ) pageTitle = '🔌 Developer Hooks Reference';
		else if ( currentPage === 'wpts-docs' ) pageTitle = '📖 Documentation & User Guide';
		else if ( currentPage === 'wpts-dashboard' ) pageTitle = '📊 Dashboard & Analytics';

		return createElement( 'div', null,
			// Toast notification
			toast ? createElement( 'div', { className: 'wpts-toast' }, ( toast.type === 'error' ? '❌ ' : '✅ ' ) + toast.msg ) : null,

			// Header
			createElement( 'div', { className: 'wpts-app-header' },
				createElement( 'div', { className: 'wpts-app-header__title' },
					createElement( 'span', null, pageTitle ),
					createElement( 'span', { className: 'wpts-app-header__badge' }, 'v' + ( ( config && config.version ) || '1.0.0' ) )
				),
				createElement( 'div', { className: 'wpts-app-header__pills' },
					createElement( 'span', { className: 'wpts-pill wpts-pill--blue' }, '● Engine: ' + ( ( config && config.active_engine ) || ( config && config.settings && config.settings.search_engine ) || 'mysql' ).toUpperCase() ),
					createElement( 'span', { className: 'wpts-pill wpts-pill--green' }, '● Cache: ' + ( ( config && config.cache_status && config.cache_status.driver ) || ( config && config.cache_driver ) || 'auto' ).toUpperCase() ),
					createElement( 'span', { className: 'wpts-pill wpts-pill--amber' }, '● ' + ( ( config && config.indexed_count ) || 0 ).toLocaleString() + ' Posts Indexed' ),
					createElement( 'div', { className: 'wpts-live-controls' },
						createElement( 'span', {
							className: 'wpts-live-pulse' + ( autoRefreshRate > 0 ? ' is-active' : '' ),
							title: autoRefreshRate > 0 ? ( 'Auto-refreshing every ' + autoRefreshRate + 's (Last: ' + lastUpdated.toLocaleTimeString() + ')' ) : 'Auto-refresh paused'
						},
							createElement( 'span', { className: 'wpts-pulse-dot' } ),
							autoRefreshRate > 0 ? 'LIVE' : 'PAUSED'
						),
						createElement( 'select', {
							className: 'wpts-refresh-select',
							value: autoRefreshRate,
							onChange: handleAutoRefreshChange,
							title: 'Auto-refresh interval'
						},
							createElement( 'option', { value: 0 }, 'Auto-refresh: Off' ),
							createElement( 'option', { value: 10 }, 'Every 10s' ),
							createElement( 'option', { value: 30 }, 'Every 30s' ),
							createElement( 'option', { value: 60 }, 'Every 60s' )
						),
						createElement( 'button', {
							type: 'button',
							className: 'wpts-btn-refresh' + ( isPolling ? ' is-spinning' : '' ),
							onClick: function () { refreshLiveAnalytics(); },
							title: 'Refresh now (Last updated: ' + lastUpdated.toLocaleTimeString() + ')',
							'aria-label': 'Refresh analytics data'
						},
							createElement( 'svg', {
								width: 15,
								height: 15,
								viewBox: '0 0 24 24',
								fill: 'none',
								stroke: 'currentColor',
								strokeWidth: 2.2,
								strokeLinecap: 'round',
								strokeLinejoin: 'round',
								style: { display: 'block' }
							},
								createElement( 'path', { d: 'M21 12a9 9 0 1 1-9-9c2.52 0 4.93 1 6.74 2.74L21 8' } ),
								createElement( 'path', { d: 'M21 3v5h-5' } )
							)
						)
					)
				)
			),

			// Dashboard Page View
			currentPage === 'wpts-dashboard' && createElement( DashboardTab, { config: config, analytics: analytics, changeTab: changeTab, isPolling: isPolling, lastUpdated: lastUpdated, refreshLiveAnalytics: refreshLiveAnalytics } ),

			// Index Manager Page View
			currentPage === 'wpts-index' && createElement( IndexerTab, { config: config, settings: settings, update: updateSetting, save: saveSettings, saving: saving, isDirty: isDirty, indexing: indexing, setIndexing: setIndexing, prog: indexProg, setProg: setIndexProg, loadConfig: loadConfig, showToast: showToast } ),

			// Dev Hooks Page View
			currentPage === 'wpts-hooks' && createElement( HooksTab, { hooks: hooks } ),

			// Documentation Page View
			currentPage === 'wpts-docs' && createElement( DocsTab, { showToast: showToast } ),

			// Settings Page View (2-Column Sidebar Layout)
			currentPage === 'wpts-settings' && createElement( 'div', { className: 'wpts-settings-layout' },
				createElement( 'aside', { className: 'wpts-settings-sidebar' },
					createElement( 'nav', { className: 'wpts-app-nav' },
						settingsTabs.map( function ( t ) {
							return createElement( 'a', {
								key: t.id,
								href: 'admin.php?page=wpts-settings&tab=' + t.id,
								className: 'wpts-nav-btn ' + ( tab === t.id ? 'is-active' : '' ),
								onClick: function ( e ) { e.preventDefault(); changeTab( t.id ); }
							}, t.label );
						} )
					)
				),
				createElement( 'div', { className: 'wpts-settings-content' },
					( tab === 'general'   && createElement( GeneralTab, { settings: settings, config: config, update: updateSetting, save: saveSettings, saving: saving, isDirty: isDirty } ) ) ||
					( tab === 'weights'   && createElement( WeightsTab, { settings: settings, update: updateSetting, save: saveSettings, saving: saving, isDirty: isDirty } ) ) ||
					( tab === 'synonyms'  && createElement( SynonymsTab, { synonyms: synonyms, setSynonyms: setSynonyms, showToast: showToast } ) ) ||
					( tab === 'coverage'  && createElement( CoverageTab, { settings: settings, update: updateSetting, save: saveSettings, saving: saving, isDirty: isDirty } ) ) ||
					( tab === 'engines'   && createElement( EnginesTab, { settings: settings, update: updateSetting, updateBatch: updateBatch, showToast: showToast, save: saveSettings, saving: saving, isDirty: isDirty } ) ) ||
					( tab === 'vector'    && createElement( VectorSearchTab, { settings: settings, update: updateSetting, save: saveSettings, saving: saving, isDirty: isDirty } ) ) ||
					( tab === 'frontend'  && createElement( FrontendTab, { settings: settings, update: updateSetting, save: saveSettings, saving: saving, isDirty: isDirty } ) ) ||
					( tab === 'security'  && createElement( SecurityTab, { settings: settings, config: config, update: updateSetting, save: saveSettings, saving: saving, isDirty: isDirty } ) ) ||
					( tab === 'backup'    && createElement( BackupTab, { showToast: showToast, loadConfig: loadConfig } ) ) ||
					createElement( GeneralTab, { settings: settings, config: config, update: updateSetting, save: saveSettings, saving: saving, isDirty: isDirty } )
				)
			),

			// Tracking & Analytics Page View (2-Column Sidebar Layout)
			currentPage === 'wpts-tracking' && createElement( 'div', { className: 'wpts-settings-layout' },
				createElement( 'aside', { className: 'wpts-settings-sidebar' },
					createElement( 'nav', { className: 'wpts-app-nav' },
						trackingTabs.map( function ( t ) {
							return createElement( 'a', {
								key: t.id,
								href: 'admin.php?page=wpts-tracking&tab=' + t.id,
								className: 'wpts-nav-btn ' + ( tab === t.id ? 'is-active' : '' ),
								onClick: function ( e ) { e.preventDefault(); changeTab( t.id ); }
							}, t.label );
						} )
					)
				),
				createElement( 'div', { className: 'wpts-settings-content' },
					( tab === 'searches' && createElement( SearchLogTab, { analytics: analytics, showToast: showToast, loadAnalytics: loadAnalytics } ) ) ||
					( tab === 'ctr'      && createElement( CtrRankingTab, { analytics: analytics } ) ) ||
					( tab === 'top'      && createElement( TopQueriesTab, { analytics: analytics } ) ) ||
					( tab === 'zero'     && createElement( ZeroResultsTab, { analytics: analytics } ) ) ||
					( tab === 'noclick'  && createElement( NoClickTab, { analytics: analytics } ) ) ||
					( tab === 'ab'       && createElement( ABTestingTab, { analytics: analytics } ) ) ||
					( tab === 'trending' && createElement( TrendingTab, { analytics: analytics } ) ) ||
					( tab === 'exports'  && createElement( ExportsTab, { cfg: cfg, showToast: showToast, loadAnalytics: loadAnalytics } ) ) ||
					createElement( SearchLogTab, { analytics: analytics, showToast: showToast, loadAnalytics: loadAnalytics } )
				)
			),

			// Cache Settings Page View (2-Column Sidebar Layout)
			currentPage === 'wpts-cache' && createElement( 'div', { className: 'wpts-settings-layout' },
				createElement( 'aside', { className: 'wpts-settings-sidebar' },
					createElement( 'nav', { className: 'wpts-app-nav' },
						cacheTabs.map( function ( t ) {
							return createElement( 'a', {
								key: t.id,
								href: 'admin.php?page=wpts-cache&tab=' + t.id,
								className: 'wpts-nav-btn ' + ( tab === t.id ? 'is-active' : '' ),
								onClick: function ( e ) { e.preventDefault(); changeTab( t.id ); }
							}, t.label );
						} )
					)
				),
				createElement( 'div', { className: 'wpts-settings-content' },
					( tab === 'general'   && createElement( CacheDriversTab, { settings: settings, update: updateSetting, showToast: showToast, save: saveSettings, saving: saving, isDirty: isDirty } ) ) ||
					( tab === 'redis'     && createElement( RedisConfigTab, { settings: settings, update: updateSetting, showToast: showToast, save: saveSettings, saving: saving, isDirty: isDirty } ) ) ||
					( tab === 'memcached' && createElement( MemcachedConfigTab, { settings: settings, update: updateSetting, showToast: showToast, save: saveSettings, saving: saving, isDirty: isDirty } ) ) ||
					( tab === 'cdn'       && createElement( CdnHeadersTab, { settings: settings, update: updateSetting, save: saveSettings, saving: saving, isDirty: isDirty } ) ) ||
					( tab === 'flush'     && createElement( FlushCacheTab, { showToast: showToast, loadConfig: loadConfig } ) ) ||
					createElement( CacheDriversTab, { settings: settings, update: updateSetting, showToast: showToast, save: saveSettings, saving: saving, isDirty: isDirty } )
				)
			),

			// Floating Save Changes Bar
			isDirty && createElement( 'div', { className: 'wpts-save-bar' },
				createElement( 'span', null, '⚠️ You have unsaved configuration changes.' ),
				createElement( 'button', {
					type: 'button',
					className: 'wpts-btn-primary',
					disabled: saving,
					onClick: saveSettings
				}, saving ? 'Saving Changes…' : '💾 Save Settings' )
			)
		);
	}

	/* Component: Advanced Chart.js Volume & Latency Chart */
	function AdvancedVolumeChart( props ) {
		var a = props.analytics || {};
		var canvasRef = useRef( null );
		var chartInstance = useRef( null );
		var [ days, setDays ] = useState( 14 );
		var [ chartData, setChartData ] = useState( a.daily_volume || [] );
		var [ loading, setLoading ] = useState( false );

		var fetchChartData = function ( d ) {
			setLoading( true );
			api( 'analytics?days=' + d )
				.then( function ( res ) {
					setLoading( false );
					if ( res && res.daily_volume ) {
						setChartData( res.daily_volume );
					}
				} )
				.catch( function () { setLoading( false ); } );
		};

		var handleDaysChange = function ( newDays ) {
			setDays( newDays );
			fetchChartData( newDays );
		};

		useEffect( function () {
			if ( a.daily_volume ) setChartData( a.daily_volume );
		}, [ a.daily_volume ] );

		useEffect( function () {
			var ctx = canvasRef.current;
			if ( ! ctx || typeof Chart === 'undefined' ) return;

			var labels = [];
			var totalData = [];
			var cachedData = [];
			var zeroData = [];

			if ( chartData && chartData.length ) {
				chartData.forEach( function ( row ) {
					labels.push( row.day );
					totalData.push( parseInt( row.total, 10 ) || 0 );
					cachedData.push( parseInt( row.cached, 10 ) || 0 );
					zeroData.push( parseInt( row.zero_results, 10 ) || 0 );
				} );
			} else {
				var today = new Date();
				for ( var i = days - 1; i >= 0; i-- ) {
					var d = new Date( today );
					d.setDate( d.getDate() - i );
					labels.push( d.toISOString().split( 'T' )[0] );
					totalData.push( 0 );
					cachedData.push( 0 );
					zeroData.push( 0 );
				}
			}

			if ( chartInstance.current ) {
				chartInstance.current.destroy();
			}

			chartInstance.current = new Chart( ctx, {
				type: 'bar',
				data: {
					labels: labels,
					datasets: [
						{
							label: 'Total Searches',
							data: totalData,
							backgroundColor: 'rgba(37, 99, 235, 0.75)',
							borderColor: '#2563eb',
							borderWidth: 1,
							borderRadius: 4,
							order: 2,
							yAxisID: 'y'
						},
						{
							label: 'Cache Hits (⚡ Instant)',
							data: cachedData,
							backgroundColor: 'rgba(16, 185, 129, 0.75)',
							borderColor: '#10b981',
							borderWidth: 1,
							borderRadius: 4,
							order: 1,
							yAxisID: 'y'
						},
						{
							label: 'Zero-Result Queries',
							data: zeroData,
							type: 'line',
							borderColor: '#ef4444',
							backgroundColor: 'rgba(239, 68, 68, 0.1)',
							borderWidth: 2.5,
							pointBackgroundColor: '#ef4444',
							pointRadius: 4,
							pointHoverRadius: 6,
							tension: 0.3,
							fill: false,
							order: 0,
							yAxisID: 'y'
						}
					]
				},
				options: {
					responsive: true,
					maintainAspectRatio: false,
					interaction: {
						mode: 'index',
						intersect: false
					},
					plugins: {
						legend: {
							position: 'top',
							labels: {
								boxWidth: 14,
								font: { size: 12, weight: '600' },
								padding: 16
							}
						},
						tooltip: {
							backgroundColor: '#1e293b',
							titleFont: { size: 13, weight: '700' },
							bodyFont: { size: 12 },
							padding: 12,
							cornerRadius: 6
						}
					},
					scales: {
						x: {
							grid: { display: false },
							ticks: { font: { size: 11 } }
						},
						y: {
							beginAtZero: true,
							grid: { color: '#f1f5f9' },
							ticks: { precision: 0, font: { size: 11 } }
						}
					}
				}
			} );

			return function () {
				if ( chartInstance.current ) {
					chartInstance.current.destroy();
				}
			};
		}, [ chartData, days ] );

		return createElement( 'div', { className: 'wpts-app-panel', style: { marginBottom: 24 } },
			createElement( 'div', { style: { display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16, flexWrap: 'wrap', gap: 10 } },
				createElement( 'div', null,
					createElement( 'h3', { style: { margin: '0 0 4px', fontSize: 16, fontWeight: 700 } }, '📈 Search Volume & Engine Performance Timeline' ),
					createElement( 'p', { style: { margin: 0, fontSize: 13, color: '#64748b' } }, 'Interactive daily search traffic, cache hit ratios, and zero-result search velocity.' )
				),
				createElement( 'div', { style: { display: 'flex', gap: 6, alignItems: 'center' } },
					[ [ 7, '7 Days' ], [ 14, '14 Days' ], [ 30, '30 Days' ] ].map( function ( opt ) {
						return createElement( 'button', {
							key: opt[0],
							type: 'button',
							className: 'button ' + ( days === opt[0] ? 'button-primary' : '' ),
							disabled: loading,
							onClick: function () { handleDaysChange( opt[0] ); }
						}, opt[1] );
					} )
				)
			),
			createElement( 'div', { style: { position: 'relative', height: 320, width: '100%' } },
				typeof Chart !== 'undefined' ?
					createElement( 'canvas', { ref: canvasRef } ) :
					createElement( 'div', { style: { display: 'flex', alignItems: 'center', justifyContent: 'center', height: '100%', color: '#64748b' } }, '📈 Chart is loading or not available.' )
			)
		);
	}

	/* Tab: Dashboard (ALL 8 KPI CARDS + ADVANCED CHARTS) */
	function DashboardTab( props ) {
		var c = props.config || {};
		var a = props.analytics || {};
		var sum = a.summary || {};

		return createElement( 'div', null,
			// All 8 KPI Cards
			createElement( 'div', { className: 'wpts-kpi-grid' },
				createElement( 'div', { className: 'wpts-kpi-card' },
					createElement( 'div', { className: 'wpts-kpi-icon' }, '🔎' ),
					createElement( 'div', null,
						createElement( 'div', { className: 'wpts-kpi-value' }, ( sum.total_searches || 0 ).toLocaleString() ),
						createElement( 'div', { className: 'wpts-kpi-label' }, 'Total Searches (30d)' )
					)
				),
				createElement( 'div', { className: 'wpts-kpi-card' },
					createElement( 'div', { className: 'wpts-kpi-icon' }, '🎯' ),
					createElement( 'div', null,
						createElement( 'div', { className: 'wpts-kpi-value' }, ( sum.overall_ctr || 0 ) + '%' ),
						createElement( 'div', { className: 'wpts-kpi-label' }, 'Click-Through Rate' )
					)
				),
				createElement( 'div', { className: 'wpts-kpi-card' },
					createElement( 'div', { className: 'wpts-kpi-icon' }, '🖱️' ),
					createElement( 'div', null,
						createElement( 'div', { className: 'wpts-kpi-value' }, ( sum.total_clicks || 0 ).toLocaleString() ),
						createElement( 'div', { className: 'wpts-kpi-label' }, 'Total Result Clicks' )
					)
				),
				createElement( 'div', { className: 'wpts-kpi-card' },
					createElement( 'div', { className: 'wpts-kpi-icon' }, '⚡' ),
					createElement( 'div', null,
						createElement( 'div', { className: 'wpts-kpi-value' }, ( sum.cache_hit_rate || 0 ) + '%' ),
						createElement( 'div', { className: 'wpts-kpi-label' }, 'Cache Hit Rate' )
					)
				),
				createElement( 'div', { className: 'wpts-kpi-card' },
					createElement( 'div', { className: 'wpts-kpi-icon' }, '✅' ),
					createElement( 'div', null,
						createElement( 'div', { className: 'wpts-kpi-value' }, ( sum.cache_hits || 0 ).toLocaleString() ),
						createElement( 'div', { className: 'wpts-kpi-label' }, 'Cache Hits' )
					)
				),
				createElement( 'div', { className: 'wpts-kpi-card' },
					createElement( 'div', { className: 'wpts-kpi-icon' }, '❌' ),
					createElement( 'div', null,
						createElement( 'div', { className: 'wpts-kpi-value' }, ( sum.cache_misses || 0 ).toLocaleString() ),
						createElement( 'div', { className: 'wpts-kpi-label' }, 'Cache Misses' )
					)
				),
				createElement( 'div', { className: 'wpts-kpi-card' },
					createElement( 'div', { className: 'wpts-kpi-icon' }, '📄' ),
					createElement( 'div', null,
						createElement( 'div', { className: 'wpts-kpi-value' }, ( c.indexed_count || 0 ).toLocaleString() ),
						createElement( 'div', { className: 'wpts-kpi-label' }, 'Indexed Documents' )
					)
				),
				createElement( 'div', { className: 'wpts-kpi-card' },
					createElement( 'div', { className: 'wpts-kpi-icon' }, '🚫' ),
					createElement( 'div', null,
						createElement( 'div', { className: 'wpts-kpi-value' }, ( sum.zero_results || 0 ).toLocaleString() ),
						createElement( 'div', { className: 'wpts-kpi-label' }, 'Zero-Result Queries' )
					)
				)
			),

			// Advanced Chart.js Volume Timeline
			createElement( AdvancedVolumeChart, { analytics: a } ),

			// CTR by Rank Position Table
			createElement( 'div', { className: 'wpts-app-panel' },
				createElement( 'h3', { className: 'wpts-app-panel__title' }, '🎯 Search Console CTR by Rank Position' ),
				createElement( 'p', { className: 'wpts-app-panel__desc' }, 'Measures the proportion of searches where users clicked the 1st, 2nd, 3rd, or subsequent organic search result.' ),
				( a.ctr_by_rank && a.ctr_by_rank.length )
					? createElement( 'table', { className: 'widefat striped', style: { border: '1px solid #e2e8f0' } },
							createElement( 'thead', null,
								createElement( 'tr', null,
									createElement( 'th', null, 'Rank Position' ),
									createElement( 'th', null, 'Clicks' ),
									createElement( 'th', null, 'CTR %' ),
									createElement( 'th', null, 'Share' )
								)
							),
							createElement( 'tbody', null,
								a.ctr_by_rank.map( function ( r ) {
									return createElement( 'tr', { key: r.position },
										createElement( 'td', null, createElement( 'strong', null, 'Position #' + r.position ) ),
										createElement( 'td', null, ( r.clicks || 0 ).toLocaleString() ),
										createElement( 'td', null, createElement( 'strong', null, r.ctr + '%' ) ),
										createElement( 'td', null,
											createElement( 'div', { style: { background: '#e2e8f0', height: 8, width: 140, borderRadius: 99, overflow: 'hidden' } },
												createElement( 'div', { style: { background: '#2563eb', height: '100%', width: Math.min( 100, r.ctr * 2 ) + '%' } } )
											)
										)
									);
								} )
							)
						)
					: createElement( 'p', { style: { color: '#64748b', fontStyle: 'italic' } }, 'No click tracking data recorded yet.' )
			),

			// Top Searches & Zero Results 2-Column Grid
			createElement( 'div', { style: { display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 20 } },
				createElement( 'div', { className: 'wpts-app-panel' },
					createElement( 'h3', { className: 'wpts-app-panel__title' }, '📈 Top Queries' ),
					( a.top_queries && a.top_queries.length )
						? createElement( 'table', { className: 'widefat striped', style: { border: '1px solid #e2e8f0' } },
								createElement( 'thead', null,
									createElement( 'tr', null, createElement( 'th', null, 'Query' ), createElement( 'th', null, 'Searches' ), createElement( 'th', null, 'Speed' ) )
								),
								createElement( 'tbody', null,
									a.top_queries.slice( 0, 8 ).map( function ( q, i ) {
										return createElement( 'tr', { key: i },
											createElement( 'td', null, createElement( 'strong', null, q.query ) ),
											createElement( 'td', null, q.search_count ),
											createElement( 'td', null, ( parseInt( q.avg_ms, 10 ) || 0 ) + 'ms' )
										);
									} )
								)
							)
						: createElement( 'p', { style: { color: '#64748b', fontStyle: 'italic' } }, 'No search queries yet.' )
				),

				createElement( 'div', { className: 'wpts-app-panel' },
					createElement( 'h3', { className: 'wpts-app-panel__title' }, '🚫 Content Gaps (Zero Hits)' ),
					( a.zero_queries && a.zero_queries.length )
						? createElement( 'table', { className: 'widefat striped', style: { border: '1px solid #e2e8f0' } },
								createElement( 'thead', null,
									createElement( 'tr', null, createElement( 'th', null, 'Query' ), createElement( 'th', null, 'Searches' ) )
								),
								createElement( 'tbody', null,
									a.zero_queries.slice( 0, 8 ).map( function ( z, i ) {
										return createElement( 'tr', { key: i },
											createElement( 'td', null, createElement( 'strong', { style: { color: '#dc2626' } }, z.query ) ),
											createElement( 'td', null, z.search_count )
										);
									} )
								)
							)
						: createElement( 'p', { style: { color: '#64748b', fontStyle: 'italic' } }, 'No zero-result searches.' )
				)
			)
		);
	}

	/* Tab: Search Log (Tracking Page) with Live Pagination & Filters */
	function SearchLogTab( props ) {
		var initialLog   = ( props.analytics && props.analytics.search_log ) || null;
		var initialItems = [];
		var initialTotal = 0;
		var initialPages = 0;

		if ( initialLog ) {
			if ( Array.isArray( initialLog ) ) {
				initialItems = initialLog;
				initialTotal = initialLog.length;
				initialPages = Math.ceil( initialTotal / 25 ) || ( initialTotal > 0 ? 1 : 0 );
			} else if ( typeof initialLog === 'object' && initialLog !== null ) {
				initialItems = Array.isArray( initialLog.items ) ? initialLog.items : [];
				initialTotal = ( initialLog.total !== undefined && initialLog.total !== null ) ? parseInt( initialLog.total, 10 ) : initialItems.length;
				initialPages = ( initialLog.total_pages !== undefined && initialLog.total_pages !== null ) ? parseInt( initialLog.total_pages, 10 ) : ( initialTotal > 0 ? 1 : 0 );
			}
		}

		var [ page, setPage ]                 = useState( 1 );
		var [ perPage, setPerPage ]           = useState( 25 );
		var [ searchFilter, setSearchFilter ] = useState( '' );
		var [ statusFilter, setStatusFilter ] = useState( 'all' );
		var [ engineFilter, setEngineFilter ] = useState( 'all' );
		var [ items, setItems ]               = useState( initialItems );
		var [ total, setTotal ]               = useState( initialTotal );
		var [ totalPages, setTotalPages ]     = useState( initialPages );
		var [ loading, setLoading ]           = useState( false );

		// Sync when props.analytics refreshes (e.g. from live polling)
		useEffect( function () {
			if ( page === 1 && ! searchFilter && statusFilter === 'all' && engineFilter === 'all' ) {
				if ( props.analytics && props.analytics.search_log ) {
					var sl = props.analytics.search_log;
					if ( Array.isArray( sl ) ) {
						setItems( sl );
						setTotal( sl.length );
						setTotalPages( Math.ceil( sl.length / perPage ) || ( sl.length > 0 ? 1 : 0 ) );
					} else if ( typeof sl === 'object' && sl !== null ) {
						var itemsList = Array.isArray( sl.items ) ? sl.items : [];
						var tot       = ( sl.total !== undefined && sl.total !== null ) ? parseInt( sl.total, 10 ) : itemsList.length;
						var pgs       = ( sl.total_pages !== undefined && sl.total_pages !== null ) ? parseInt( sl.total_pages, 10 ) : ( tot > 0 ? 1 : 0 );
						setItems( itemsList );
						setTotal( tot );
						setTotalPages( pgs );
					}
				}
			}
		}, [ props.analytics ] );

		var fetchLogs = function ( targetPage, targetPerPage, targetSearch, targetStatus, targetEngine ) {
			setLoading( true );
			var p = targetPage !== undefined ? targetPage : page;
			var pp = targetPerPage !== undefined ? targetPerPage : perPage;
			var s = targetSearch !== undefined ? targetSearch : searchFilter;
			var st = targetStatus !== undefined ? targetStatus : statusFilter;
			var eng = targetEngine !== undefined ? targetEngine : engineFilter;

			var queryStr = 'page=' + p + '&per_page=' + pp;
			if ( s ) queryStr += '&search=' + encodeURIComponent( s );
			if ( st && st !== 'all' ) queryStr += '&status=' + encodeURIComponent( st );
			if ( eng && eng !== 'all' ) queryStr += '&engine=' + encodeURIComponent( eng );

			api( 'analytics/logs?' + queryStr, 'GET' )
				.then( function ( res ) {
					setLoading( false );
					if ( res && res.items !== undefined ) {
						var itemsList = Array.isArray( res.items ) ? res.items : [];
						var tot       = ( res.total !== undefined && res.total !== null ) ? parseInt( res.total, 10 ) : itemsList.length;
						var pgs       = ( res.total_pages !== undefined && res.total_pages !== null ) ? parseInt( res.total_pages, 10 ) : ( tot > 0 ? 1 : 0 );
						setItems( itemsList );
						setTotal( tot );
						setTotalPages( pgs );
						if ( res.current_page ) {
							setPage( parseInt( res.current_page, 10 ) || 1 );
						}
					}
				} )
				.catch( function () {
					setLoading( false );
				} );
		};

		var handlePageChange = function ( newPage ) {
			if ( newPage < 1 || ( totalPages > 0 && newPage > totalPages ) || newPage === page ) return;
			setPage( newPage );
			fetchLogs( newPage, perPage, searchFilter, statusFilter, engineFilter );
		};

		var handlePerPageChange = function ( e ) {
			var newPerPage = parseInt( e.target.value, 10 ) || 25;
			setPerPage( newPerPage );
			setPage( 1 );
			fetchLogs( 1, newPerPage, searchFilter, statusFilter, engineFilter );
		};

		var handleStatusFilterChange = function ( newStatus ) {
			setStatusFilter( newStatus );
			setPage( 1 );
			fetchLogs( 1, perPage, searchFilter, newStatus, engineFilter );
		};

		var handleSearchChange = function ( e ) {
			setSearchFilter( e.target.value );
		};

		var handleSearchSubmit = function ( e ) {
			e.preventDefault();
			setPage( 1 );
			fetchLogs( 1, perPage, searchFilter, statusFilter, engineFilter );
		};

		var handleResetFilters = function () {
			setSearchFilter( '' );
			setStatusFilter( 'all' );
			setEngineFilter( 'all' );
			setPage( 1 );
			fetchLogs( 1, perPage, '', 'all', 'all' );
		};

		var flushTracking = function () {
			if ( ! confirm( 'Are you sure you want to permanently clear all search analytics logs and click history? This cannot be undone.' ) ) return;
			api( 'flush-tracking', 'POST' ).then( function ( res ) {
				if ( res && res.success ) {
					if ( props.showToast ) props.showToast( res.message );
					setItems( [] );
					setTotal( 0 );
					setTotalPages( 0 );
					setPage( 1 );
					if ( props.loadAnalytics ) props.loadAnalytics();
				}
			} );
		};

		var fromIndex = total === 0 ? 0 : ( page - 1 ) * perPage + 1;
		var toIndex = Math.min( page * perPage, total );

		return createElement( 'div', { className: 'wpts-app-panel' },
			// Header & Action buttons
			createElement( 'div', { style: { display: 'flex', justifyContent: 'space-between', alignItems: 'center', flexWrap: 'wrap', gap: 12, marginBottom: 16 } },
				createElement( 'div', null,
					createElement( 'h3', { className: 'wpts-app-panel__title', style: { margin: 0 } }, '🔎 Live Search Stream & Telemetry Log' ),
					createElement( 'p', { className: 'wpts-app-panel__desc', style: { margin: '4px 0 0' } },
						'Complete chronological record of all search queries, response times, caching hits, and user interaction click-ranks.'
					)
				),
				createElement( 'div', { style: { display: 'flex', gap: 8 } },
					createElement( 'button', {
						type: 'button',
						className: 'button',
						onClick: function () { fetchLogs( page, perPage, searchFilter, statusFilter, engineFilter ); },
						disabled: loading,
						title: 'Refresh logs table'
					}, loading ? '⏳ Refreshing…' : '🔄 Refresh' ),
					createElement( 'button', {
						type: 'button',
						className: 'button button-link-delete',
						style: { color: '#ef4444', border: '1px solid #fca5a5', padding: '4px 12px', borderRadius: 6, fontWeight: 600, fontSize: 13 },
						onClick: flushTracking
					}, '🗑️ Flush Search Logs' )
				)
			),

			// Filter Toolbar (Search input, Status filter pills, Rows per page)
			createElement( 'div', { className: 'wpts-log-toolbar' },
				createElement( 'form', { onSubmit: handleSearchSubmit, style: { display: 'flex', gap: 6, flex: '1 1 240px' } },
					createElement( 'input', {
						type: 'search',
						className: 'wpts-form-input',
						placeholder: 'Filter by query keyword…',
						value: searchFilter,
						onChange: handleSearchChange,
						style: { width: '100%', maxWidth: 280 }
					} ),
					createElement( 'button', { type: 'submit', className: 'button', style: { whiteSpace: 'nowrap' } }, '🔍 Filter' ),
					searchFilter ? createElement( 'button', {
						type: 'button',
						className: 'button',
						onClick: handleResetFilters,
						title: 'Clear search filter'
					}, '✕ Clear' ) : null
				),

				createElement( 'div', { className: 'wpts-filter-pills' },
					[
						{ id: 'all', label: 'All Queries' },
						{ id: 'cached', label: '⚡ Cached' },
						{ id: 'zero_hits', label: '🚫 Zero Hits' },
						{ id: 'clicked', label: '🎯 Clicked' }
					].map( function ( f ) {
						return createElement( 'button', {
							key: f.id,
							type: 'button',
							className: 'wpts-filter-pill ' + ( statusFilter === f.id ? 'is-active' : '' ),
							onClick: function () { handleStatusFilterChange( f.id ); }
						}, f.label );
					} )
				),

				createElement( 'div', { style: { display: 'flex', alignItems: 'center', gap: 8, marginLeft: 'auto' } },
					createElement( 'span', { style: { fontSize: 12, color: '#64748b', fontWeight: 600 } }, 'Rows:' ),
					createElement( 'select', {
						className: 'wpts-form-select',
						value: perPage,
						onChange: handlePerPageChange,
						style: { width: 80, padding: '0 24px 0 10px', fontSize: 12, height: 32, lineHeight: '30px' }
					},
						createElement( 'option', { value: 10 }, '10' ),
						createElement( 'option', { value: 25 }, '25' ),
						createElement( 'option', { value: 50 }, '50' ),
						createElement( 'option', { value: 100 }, '100' )
					)
				)
			),

			// Main Table with Overlay
			items.length ? createElement( 'div', { style: { position: 'relative', overflowX: 'auto', margin: '14px 0 0' } },
				loading ? createElement( 'div', { className: 'wpts-table-loading-overlay' }, '⏳ Updating search logs…' ) : null,
				createElement( 'table', { className: 'widefat striped', style: { border: '1px solid #e2e8f0' } },
					createElement( 'thead', null,
						createElement( 'tr', null,
							createElement( 'th', null, 'Query' ),
							createElement( 'th', null, 'Hits' ),
							createElement( 'th', null, 'Speed' ),
							createElement( 'th', null, 'Source' ),
							createElement( 'th', null, 'Engine' ),
							createElement( 'th', null, 'Clicked Rank' ),
							createElement( 'th', null, 'Date / Time' )
						)
					),
					createElement( 'tbody', null,
						items.map( function ( row ) {
							return createElement( 'tr', { key: row.id },
								createElement( 'td', null, createElement( 'strong', null, row.query ) ),
								createElement( 'td', null,
									parseInt( row.results, 10 ) === 0
										? createElement( 'span', { className: 'wpts-pill wpts-pill--amber' }, '0 hits' )
										: ( parseInt( row.results, 10 ).toLocaleString() + ' hits' )
								),
								createElement( 'td', null, ( row.duration_ms || 0 ) + 'ms' ),
								createElement( 'td', null,
									parseInt( row.from_cache, 10 ) === 1
										? createElement( 'span', { className: 'wpts-pill wpts-pill--green' }, '⚡ Cached' )
										: createElement( 'span', { className: 'wpts-pill wpts-pill--blue' }, 'DB Query' )
								),
								createElement( 'td', null, ( row.engine || 'mysql' ).toUpperCase() ),
								createElement( 'td', null,
									row.clicked_position && parseInt( row.clicked_position, 10 ) > 0
										? createElement( 'span', { className: 'wpts-pill wpts-pill--green' }, 'Rank #' + row.clicked_position )
										: createElement( 'span', { style: { color: '#94a3b8', fontSize: 12 } }, ' - ' )
								),
								createElement( 'td', null, row.searched_at )
							);
						} )
					)
				)
			) : createElement( 'div', { style: { padding: '30px 20px', textAlign: 'center', color: '#64748b', background: '#f8fafc', borderRadius: 8, marginTop: 14 } },
				searchFilter || statusFilter !== 'all'
					? createElement( 'div', null,
							createElement( 'p', { style: { margin: '0 0 8px', fontWeight: 600 } }, 'No search logs matched your filter criteria.' ),
							createElement( 'button', { type: 'button', className: 'button', onClick: handleResetFilters }, 'Reset Filters' )
						)
					: createElement( 'p', { style: { margin: 0, fontStyle: 'italic' } }, 'No search activity recorded yet.' )
			),

			// Pagination Footer Bar
			total > 0 ? createElement( 'div', { className: 'wpts-pagination-bar' },
				createElement( 'div', { className: 'wpts-pagination-info' },
					'Showing ' + fromIndex.toLocaleString() + '–' + toIndex.toLocaleString() + ' of ' + total.toLocaleString() + ' search logs'
				),
				createElement( 'div', { className: 'wpts-pagination-nav' },
					createElement( 'button', {
						type: 'button',
						className: 'button',
						onClick: function () { handlePageChange( 1 ); },
						disabled: page <= 1 || loading,
						title: 'First Page'
					}, '«' ),
					createElement( 'button', {
						type: 'button',
						className: 'button',
						onClick: function () { handlePageChange( page - 1 ); },
						disabled: page <= 1 || loading
					}, '← Prev' ),
					createElement( 'span', { className: 'wpts-pagination-current' },
						'Page ' + page + ' of ' + Math.max( 1, totalPages )
					),
					createElement( 'button', {
						type: 'button',
						className: 'button',
						onClick: function () { handlePageChange( page + 1 ); },
						disabled: page >= totalPages || loading
					}, 'Next →' ),
					createElement( 'button', {
						type: 'button',
						className: 'button',
						onClick: function () { handlePageChange( totalPages ); },
						disabled: page >= totalPages || loading,
						title: 'Last Page'
					}, '»' )
				)
			) : null
		);
	}

	/* Tab: CTR Ranking (Tracking Page) */
	function CtrRankingTab( props ) {
		var a = props.analytics || {};
		var ctrRows = a.ctr_by_rank || [];
		var keywordsBreakdown = a.ctr_keywords || [];
		var homeUrl = a.home_url || '/';

		function buildSearchUrl( query, postType ) {
			var base = homeUrl.indexOf( '?' ) !== -1
				? homeUrl + '&s=' + encodeURIComponent( query )
				: homeUrl.replace( /\/+$/, '' ) + '/?s=' + encodeURIComponent( query );
			if ( postType && postType !== 'post' && postType !== 'any' ) {
				base += '&post_type=' + encodeURIComponent( postType );
			}
			return base;
		}

		return createElement( 'div', null,
			// Card 1: Aggregate CTR by Rank Position with Top Keywords
			createElement( 'div', { className: 'wpts-app-panel' },
				createElement( 'h3', { className: 'wpts-app-panel__title' }, '🎯 Organic Click-Through Rate by Position' ),
				createElement( 'p', { className: 'wpts-app-panel__desc' }, 'Proportion of users who clicked the #1, #2, #3, etc. result, and top search keywords clicked for each rank.' ),

				ctrRows.length ? createElement( 'table', { className: 'widefat striped', style: { border: '1px solid #e2e8f0', marginTop: 12 } },
					createElement( 'thead', null,
						createElement( 'tr', null,
							createElement( 'th', null, 'Rank' ),
							createElement( 'th', null, 'Clicks' ),
							createElement( 'th', null, 'CTR %' ),
							createElement( 'th', { style: { minWidth: 260 } }, 'Top Clicked Search Keywords' ),
							createElement( 'th', null, 'Distribution' )
						)
					),
					createElement( 'tbody', null,
						ctrRows.map( function ( r ) {
							var topKeywords = r.top_keywords || [];
							return createElement( 'tr', { key: r.position },
								createElement( 'td', null, createElement( 'span', { className: 'wpts-pill wpts-pill--blue', style: { fontWeight: 700 } }, 'Rank #' + r.position ) ),
								createElement( 'td', null, createElement( 'strong', null, ( r.clicks || 0 ).toLocaleString() ) ),
								createElement( 'td', null, createElement( 'strong', { style: { color: '#16a34a' } }, r.ctr + '%' ) ),
								createElement( 'td', null,
									topKeywords.length ? createElement( 'div', { style: { display: 'flex', flexWrap: 'wrap', gap: 6 } },
										topKeywords.map( function ( kw, kwIdx ) {
											var searchUrl = buildSearchUrl( kw.query, kw.post_type );
											return createElement( 'a', {
												key: kwIdx,
												href: searchUrl,
												target: '_blank',
												rel: 'noopener noreferrer',
												className: 'wpts-keyword-badge',
												title: 'Click to test search "' + kw.query + '" on your site (' + kw.keyword_clicks + ' clicks at Rank #' + r.position + ( kw.post_type && kw.post_type !== 'post' ? ' · ' + kw.post_type : '' ) + ')'
											},
												createElement( 'span', { className: 'wpts-keyword-text' }, kw.query ),
												kw.post_type && kw.post_type !== 'post' && kw.post_type !== 'any'
													? createElement( 'span', {
														style: {
															fontSize: 9,
															textTransform: 'uppercase',
															fontWeight: 700,
															background: 'rgba(37, 99, 235, 0.12)',
															color: '#1d4ed8',
															padding: '1px 4px',
															borderRadius: 3,
															marginLeft: 4
														}
													}, kw.post_type )
													: null,
												createElement( 'span', { className: 'wpts-keyword-count' }, kw.keyword_clicks )
											);
										} )
									) : createElement( 'span', { style: { color: '#94a3b8', fontSize: 12 } }, ' - ' )
								),
								createElement( 'td', null,
									createElement( 'div', { style: { background: '#e2e8f0', height: 10, width: 140, borderRadius: 99, overflow: 'hidden' } },
										createElement( 'div', { style: { background: '#2563eb', height: '100%', width: Math.min( 100, r.ctr * 2 ) + '%' } } )
									)
								)
							);
						} )
					)
				) : createElement( 'p', { style: { color: '#64748b', fontStyle: 'italic' } }, 'No ranking data recorded yet.' )
			),

			// Card 2: Detailed Search Keyword Click-Through Leaderboard
			createElement( 'div', { className: 'wpts-app-panel' },
				createElement( 'h3', { className: 'wpts-app-panel__title' }, '🔍 Search Keyword Click-Through Leaderboard' ),
				createElement( 'p', { className: 'wpts-app-panel__desc' }, 'Exact search keywords clicked by users, their chosen rank positions, and direct links to verify search results live.' ),

				keywordsBreakdown.length ? createElement( 'table', { className: 'widefat striped', style: { border: '1px solid #e2e8f0', marginTop: 12 } },
					createElement( 'thead', null,
						createElement( 'tr', null,
							createElement( 'th', null, 'Search Keyword' ),
							createElement( 'th', null, 'Clicked Position' ),
							createElement( 'th', null, 'Click Volume' ),
							createElement( 'th', null, 'Last Clicked' ),
							createElement( 'th', { style: { textAlign: 'right' } }, 'Live Preview' )
						)
					),
					createElement( 'tbody', null,
						keywordsBreakdown.map( function ( row, idx ) {
							var searchUrl = buildSearchUrl( row.query, row.post_type );
							return createElement( 'tr', { key: idx },
								createElement( 'td', null,
									createElement( 'a', {
										href: searchUrl,
										target: '_blank',
										rel: 'noopener noreferrer',
										style: { fontWeight: 700, color: '#2563eb', textDecoration: 'none', display: 'inline-flex', alignItems: 'center', gap: 6 }
									},
										row.query,
										row.post_type && row.post_type !== 'post' && row.post_type !== 'any'
											? createElement( 'span', {
												className: 'wpts-pill wpts-pill--gray',
												style: { fontSize: 10, textTransform: 'uppercase', padding: '1px 6px' }
											}, row.post_type )
											: null,
										createElement( 'span', { style: { fontSize: 11, opacity: 0.7 } }, '↗' )
									)
								),
								createElement( 'td', null,
									createElement( 'span', { className: 'wpts-pill wpts-pill--green' }, 'Rank #' + row.clicked_position )
								),
								createElement( 'td', null, createElement( 'strong', null, ( row.clicks || 0 ).toLocaleString() + ' clicks' ) ),
								createElement( 'td', null, row.last_clicked || ' - ' ),
								createElement( 'td', { style: { textAlign: 'right' } },
									createElement( 'a', {
										href: searchUrl,
										target: '_blank',
										rel: 'noopener noreferrer',
										className: 'button button-small',
										style: { fontSize: 11 }
									}, '🔍 Test Search' )
								)
							);
						} )
					)
				) : createElement( 'p', { style: { color: '#64748b', fontStyle: 'italic' } }, 'No keyword click events logged yet.' )
			)
		);
	}

	/* Tab: Top Queries (Tracking Page) */
	function TopQueriesTab( props ) {
		var a = props.analytics || {};
		var top = a.top_queries || [];

		return createElement( 'div', { className: 'wpts-app-panel' },
			createElement( 'h3', { className: 'wpts-app-panel__title' }, '📈 Most Popular Search Terms' ),
			top.length ? createElement( 'table', { className: 'widefat striped', style: { border: '1px solid #e2e8f0' } },
				createElement( 'thead', null,
					createElement( 'tr', null,
						createElement( 'th', null, 'Query' ),
						createElement( 'th', null, 'Searches' ),
						createElement( 'th', null, 'Avg Hits' ),
						createElement( 'th', null, 'Cache Hits' ),
						createElement( 'th', null, 'Avg Speed' )
					)
				),
				createElement( 'tbody', null,
					top.map( function ( q, idx ) {
						return createElement( 'tr', { key: idx },
							createElement( 'td', null, createElement( 'strong', null, q.query ) ),
							createElement( 'td', null, q.search_count ),
							createElement( 'td', null, Math.round( q.avg_results || 0 ) ),
							createElement( 'td', null, q.cache_hits || 0 ),
							createElement( 'td', null, ( parseInt( q.avg_ms, 10 ) || 0 ) + 'ms' )
						);
					} )
				)
			) : createElement( 'p', { style: { color: '#64748b', fontStyle: 'italic' } }, 'No search queries yet.' )
		);
	}

	/* Tab: Zero Results (Tracking Page) */
	function ZeroResultsTab( props ) {
		var a = props.analytics || {};
		var zero = a.zero_queries || [];

		return createElement( 'div', { className: 'wpts-app-panel' },
			createElement( 'h3', { className: 'wpts-app-panel__title' }, '🚫 Content Gaps (Queries with Zero Hits)' ),
			createElement( 'p', { className: 'wpts-app-panel__desc' }, 'These searches returned no results. Use this list to publish new content or create custom synonyms!' ),
			zero.length ? createElement( 'table', { className: 'widefat striped', style: { border: '1px solid #e2e8f0' } },
				createElement( 'thead', null,
					createElement( 'tr', null,
						createElement( 'th', null, 'Query' ),
						createElement( 'th', null, 'Search Count' ),
						createElement( 'th', null, 'Last Searched' )
					)
				),
				createElement( 'tbody', null,
					zero.map( function ( z, idx ) {
						return createElement( 'tr', { key: idx },
							createElement( 'td', null, createElement( 'strong', { style: { color: '#dc2626' } }, z.query ) ),
							createElement( 'td', null, z.search_count ),
							createElement( 'td', null, z.last_searched || '-' )
						);
					} )
				)
			) : createElement( 'p', { style: { color: '#64748b', fontStyle: 'italic' } }, 'Zero-result list is clean! All searches found matching documents.' )
		);
	}

	/* Tab: No-Click Queries (Tracking Page) */
	function NoClickTab( props ) {
		var a = props.analytics || {};
		var noclick = a.noclick || [];

		return createElement( 'div', { className: 'wpts-app-panel' },
			createElement( 'h3', { className: 'wpts-app-panel__title' }, '👁️ No-Click Queries' ),
			createElement( 'p', { className: 'wpts-app-panel__desc' }, 'Queries that returned matching documents but users never clicked any result (low relevance).' ),
			noclick.length ? createElement( 'table', { className: 'widefat striped', style: { border: '1px solid #e2e8f0' } },
				createElement( 'thead', null,
					createElement( 'tr', null, createElement( 'th', null, 'Query' ), createElement( 'th', null, 'Searches with 0 Clicks' ) )
				),
				createElement( 'tbody', null,
					noclick.map( function ( n, idx ) {
						return createElement( 'tr', { key: idx },
							createElement( 'td', null, createElement( 'strong', null, n.query ) ),
							createElement( 'td', null, n.no_click_count )
						);
					} )
				)
			) : createElement( 'p', { style: { color: '#64748b', fontStyle: 'italic' } }, 'No no-click queries recorded.' )
		);
	}

	/* Tab: A/B Testing (Tracking Page) */
	function ABTestingTab( props ) {
		var a = props.analytics || {};
		var ab = a.ab_testing || [];

		return createElement( 'div', { className: 'wpts-app-panel' },
			createElement( 'h3', { className: 'wpts-app-panel__title' }, '🧪 A/B Testing Experiments' ),
			createElement( 'p', { className: 'wpts-app-panel__desc' }, 'Compares user engagement between Variant A and Variant B.' ),
			ab.length ? createElement( 'table', { className: 'widefat striped', style: { border: '1px solid #e2e8f0' } },
				createElement( 'thead', null,
					createElement( 'tr', null,
						createElement( 'th', null, 'Variant' ),
						createElement( 'th', null, 'Searches' ),
						createElement( 'th', null, 'Clicks' ),
						createElement( 'th', null, 'CTR %' )
					)
				),
				createElement( 'tbody', null,
					ab.map( function ( r ) {
						return createElement( 'tr', { key: r.variant },
							createElement( 'td', null, createElement( 'strong', null, 'Variant ' + r.variant ) ),
							createElement( 'td', null, r.searches ),
							createElement( 'td', null, r.clicks ),
							createElement( 'td', null, createElement( 'strong', null, r.ctr + '%' ) )
						);
					} )
				)
			) : createElement( 'p', { style: { color: '#64748b', fontStyle: 'italic' } }, 'No A/B testing data available.' )
		);
	}

	/* Tab: Trending (Tracking Page) */
	function TrendingTab( props ) {
		var a = props.analytics || {};
		var trending = a.trending || [];

		return createElement( 'div', { className: 'wpts-app-panel' },
			createElement( 'h3', { className: 'wpts-app-panel__title' }, '🔥 Trending Searches' ),
			createElement( 'p', { className: 'wpts-app-panel__desc' }, 'Top searches with increasing velocity in the last 7 days.' ),
			trending.length ? createElement( 'table', { className: 'widefat striped', style: { border: '1px solid #e2e8f0' } },
				createElement( 'thead', null,
					createElement( 'tr', null, createElement( 'th', null, 'Rank' ), createElement( 'th', null, 'Query' ), createElement( 'th', null, 'Volume (7d)' ) )
				),
				createElement( 'tbody', null,
					trending.map( function ( t, idx ) {
						return createElement( 'tr', { key: idx },
							createElement( 'td', null, '#' + ( idx + 1 ) ),
							createElement( 'td', null, createElement( 'strong', null, t.query ) ),
							createElement( 'td', null, t.search_count )
						);
					} )
				)
			) : createElement( 'p', { style: { color: '#64748b', fontStyle: 'italic' } }, 'No trending data recorded.' )
		);
	}

	/* Tab: CSV Exports (Tracking Page) */
	function ExportsTab( props ) {
		var c = props.cfg || {};
		var url = c.admin_post_url || 'admin-post.php';
		var nonce = c.export_searches_nonce || c.export_nonce || '';
		var [ days, setDays ] = useState( 30 );

		var flushTracking = function () {
			if ( ! confirm( 'Are you sure you want to permanently clear all search analytics logs, click stream history, and events? This action cannot be undone.' ) ) return;
			api( 'flush-tracking', 'POST' ).then( function ( res ) {
				if ( res && res.success ) {
					if ( props.showToast ) props.showToast( res.message );
					if ( props.loadAnalytics ) props.loadAnalytics();
				}
			} );
		};

		var buildExportUrl = function ( mode ) {
			return url + '?action=wpts_export_searches&mode=' + encodeURIComponent( mode ) + '&days=' + encodeURIComponent( days ) + '&_wpnonce=' + encodeURIComponent( nonce );
		};

		return createElement( 'div', { className: 'wpts-app-panel' },
			createElement( 'div', { style: { display: 'flex', justifyContent: 'space-between', alignItems: 'center', flexWrap: 'wrap', gap: 12, marginBottom: 16 } },
				createElement( 'div', null,
					createElement( 'h3', { className: 'wpts-app-panel__title', style: { margin: 0 } }, '⬇️ Export Search Analytics to CSV' ),
					createElement( 'p', { className: 'wpts-app-panel__desc', style: { margin: '4px 0 0' } }, 'Download clean CSV reports compatible with Excel, Google Sheets, and Business Intelligence tools.' )
				),
				createElement( 'div', { style: { display: 'flex', alignItems: 'center', gap: 8 } },
					createElement( 'label', { style: { fontWeight: 600, fontSize: 13, color: '#475569' } }, 'Date Range:' ),
					createElement( 'select', {
						value: days,
						onChange: function ( e ) { setDays( parseInt( e.target.value, 10 ) ); },
						style: { padding: '5px 10px', borderRadius: 6, borderColor: '#cbd5e1', fontSize: 13, minWidth: 140 }
					},
						createElement( 'option', { value: 7 }, 'Last 7 Days' ),
						createElement( 'option', { value: 30 }, 'Last 30 Days' ),
						createElement( 'option', { value: 90 }, 'Last 90 Days' ),
						createElement( 'option', { value: 180 }, 'Last 180 Days' ),
						createElement( 'option', { value: 365 }, 'Last 365 Days' )
					)
				)
			),
			createElement( 'div', { style: { display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))', gap: 16, marginTop: 16 } },
				createElement( 'div', { style: { border: '1px solid #e2e8f0', padding: 16, borderRadius: 8 } },
					createElement( 'h4', null, 'Full Search Stream Log' ),
					createElement( 'p', { style: { fontSize: 13, color: '#64748b' } }, 'Export every recorded search query with speed and timestamp.' ),
					createElement( 'a', { href: buildExportUrl( 'searches' ), className: 'button button-primary' }, '⬇ Export Searches (CSV)' )
				),
				createElement( 'div', { style: { border: '1px solid #e2e8f0', padding: 16, borderRadius: 8 } },
					createElement( 'h4', null, 'Top Queries Aggregation' ),
					createElement( 'p', { style: { fontSize: 13, color: '#64748b' } }, 'Export top queries grouped by search volume and cache hit rate.' ),
					createElement( 'a', { href: buildExportUrl( 'top' ), className: 'button' }, '⬇ Export Top Queries (CSV)' )
				),
				createElement( 'div', { style: { border: '1px solid #e2e8f0', padding: 16, borderRadius: 8 } },
					createElement( 'h4', null, 'Zero-Result Content Gaps' ),
					createElement( 'p', { style: { fontSize: 13, color: '#64748b' } }, 'Export queries that resulted in 0 hits for content strategy.' ),
					createElement( 'a', { href: buildExportUrl( 'zero' ), className: 'button' }, '⬇ Export Gaps (CSV)' )
				)
			),

			createElement( 'div', { style: { marginTop: 28, paddingTop: 20, borderTop: '1px solid #e2e8f0' } },
				createElement( 'h4', { style: { color: '#ef4444', margin: '0 0 6px' } }, '🧹 Data Retention & Maintenance' ),
				createElement( 'p', { style: { fontSize: 13, color: '#64748b', margin: '0 0 12px' } }, 'Permanently wipe all recorded search history, click tracking logs, and performance events.' ),
				createElement( 'button', {
					type: 'button',
					className: 'button button-link-delete',
					style: { color: '#ef4444', border: '1px solid #fca5a5', padding: '6px 14px', borderRadius: 6, fontWeight: 600 },
					onClick: flushTracking
				}, '🗑️ Flush All Analytics Data' )
			)
		);
	}

	/* Tab: Cache Drivers & TTL (Cache Page) */
	function CacheDriversTab( props ) {
		var s = props.settings || {};
		var u = props.update;
		var [ redisStatus, setRedisStatus ] = useState( '' );
		var [ redisStatusType, setRedisStatusType ] = useState( '' );
		var [ testingRedis, setTestingRedis ] = useState( false );
		var [ mcStatus, setMcStatus ] = useState( '' );
		var [ mcStatusType, setMcStatusType ] = useState( '' );
		var [ testingMc, setTestingMc ] = useState( false );

		var flushCache = function () {
			api( 'flush-cache', 'POST' ).then( function ( res ) {
				if ( res && res.success ) props.showToast( res.message );
			} );
		};

		var testRedis = function () {
			setTestingRedis( true );
			setRedisStatus( 'Testing Redis connection…' );
			setRedisStatusType( 'info' );
			api( 'test-connection', 'POST', Object.assign( { target: 'redis' }, s ) )
				.then( function ( res ) {
					setTestingRedis( false );
					if ( res && res.success ) {
						setRedisStatus( '✅ ' + res.message );
						setRedisStatusType( 'success' );
					} else {
						setRedisStatus( '❌ ' + ( ( res && res.message ) || 'Connection failed.' ) );
						setRedisStatusType( 'error' );
					}
				} )
				.catch( function ( err ) {
					setTestingRedis( false );
					var msg = ( err && ( err.message || err.error ) ) || 'Connection test request failed.';
					setRedisStatus( '❌ ' + msg );
					setRedisStatusType( 'error' );
				} );
		};

		var testMemcached = function () {
			setTestingMc( true );
			setMcStatus( 'Testing Memcached connection…' );
			setMcStatusType( 'info' );
			api( 'test-connection', 'POST', Object.assign( { target: 'memcached' }, s ) )
				.then( function ( res ) {
					setTestingMc( false );
					if ( res && res.success ) {
						setMcStatus( '✅ ' + res.message );
						setMcStatusType( 'success' );
					} else {
						setMcStatus( '❌ ' + ( ( res && res.message ) || 'Connection failed.' ) );
						setMcStatusType( 'error' );
					}
				} )
				.catch( function ( err ) {
					setTestingMc( false );
					var msg = ( err && ( err.message || err.error ) ) || 'Connection test request failed.';
					setMcStatus( '❌ ' + msg );
					setMcStatusType( 'error' );
				} );
		};

		var handleDriverChange = function ( e ) {
			var d = e.target.value;
			var patch = { cache_driver: d };
			if ( d === 'redis' ) {
				if ( ! s.redis_host ) patch.redis_host = '127.0.0.1';
				if ( ! s.redis_port ) patch.redis_port = 6379;
				if ( s.redis_db === undefined ) patch.redis_db = 0;
			} else if ( d === 'memcached' ) {
				if ( ! s.memcached_host ) patch.memcached_host = '127.0.0.1';
				if ( ! s.memcached_port ) patch.memcached_port = 11211;
			}
			u( patch );
		};

		return createElement( 'div', { className: 'wpts-app-panel' },
			createElement( 'h3', { className: 'wpts-app-panel__title' }, '⚡ Result Cache Drivers & TTL' ),

			createElement( 'div', { className: 'wpts-form-group' },
				createElement( 'label', { className: 'wpts-form-label' }, 'Cache Driver' ),
				createElement( 'select', {
					className: 'wpts-form-select',
					value: s.cache_driver || 'auto',
					onChange: handleDriverChange
				},
					createElement( 'option', { value: 'auto' }, 'Auto (Redis → Memcached → WP Cache → Transient)' ),
					createElement( 'option', { value: 'redis' }, 'Redis' ),
					createElement( 'option', { value: 'memcached' }, 'Memcached' ),
					createElement( 'option', { value: 'wp_cache' }, 'WP Object Cache' ),
					createElement( 'option', { value: 'transient' }, 'WordPress Transients (Database)' ),
					createElement( 'option', { value: 'none' }, 'Disabled' )
				)
			),

			// Redis Sub-Options
			s.cache_driver === 'redis' && createElement( 'div', { style: { marginTop: 16, marginBottom: 20, padding: 16, background: '#f8fafc', borderRadius: 8, border: '1px solid #e2e8f0' } },
				createElement( 'h4', { style: { margin: '0 0 12px', fontSize: 14 } }, '🔴 Redis Server Connection' ),
				createElement( 'div', { className: 'wpts-form-group' },
					createElement( 'label', { className: 'wpts-form-label' }, 'Redis Host' ),
					createElement( 'input', { className: 'wpts-form-input', value: s.redis_host || '127.0.0.1', onChange: function ( e ) { u( 'redis_host', e.target.value ); } } )
				),
				createElement( 'div', { className: 'wpts-form-group' },
					createElement( 'label', { className: 'wpts-form-label' }, 'Redis Port' ),
					createElement( 'input', { className: 'wpts-form-input', type: 'number', value: s.redis_port || 6379, onChange: function ( e ) { u( 'redis_port', parseInt( e.target.value, 10 ) || 6379 ); } } )
				),
				createElement( 'div', { className: 'wpts-form-group' },
					createElement( 'label', { className: 'wpts-form-label' }, 'Redis Password' ),
					createElement( 'input', { type: 'password', className: 'wpts-form-input', placeholder: 'Optional (leave blank if no AUTH)', value: s.redis_password || '', onChange: function ( e ) { u( 'redis_password', e.target.value ); } } )
				),
				createElement( 'div', { className: 'wpts-form-group' },
					createElement( 'label', { className: 'wpts-form-label' }, 'Redis Database Index' ),
					createElement( 'input', { type: 'number', className: 'wpts-form-input', value: s.redis_db || 0, min: 0, max: 15, onChange: function ( e ) { u( 'redis_db', parseInt( e.target.value, 10 ) || 0 ); } } )
				),
				createElement( 'div', { style: { display: 'flex', alignItems: 'center', flexWrap: 'wrap', gap: 12, marginTop: 16 } },
					createElement( 'button', { type: 'button', className: 'button', disabled: testingRedis, onClick: testRedis }, testingRedis ? '⏳ Testing…' : '🔌 Test Redis Connection' ),
					redisStatus ? createElement( 'span', {
						style: {
							fontSize: 13,
							fontWeight: 600,
							padding: '4px 10px',
							borderRadius: 6,
							background: redisStatusType === 'success' ? '#dcfce7' : ( redisStatusType === 'error' ? '#fee2e2' : '#f1f5f9' ),
							color: redisStatusType === 'success' ? '#15803d' : ( redisStatusType === 'error' ? '#b91c1c' : '#475569' ),
							border: '1px solid ' + ( redisStatusType === 'success' ? '#86efac' : ( redisStatusType === 'error' ? '#fca5a5' : '#cbd5e1' ) )
						}
					}, redisStatus ) : null
				)
			),

			// Memcached Sub-Options
			s.cache_driver === 'memcached' && createElement( 'div', { style: { marginTop: 16, marginBottom: 20, padding: 16, background: '#f8fafc', borderRadius: 8, border: '1px solid #e2e8f0' } },
				createElement( 'h4', { style: { margin: '0 0 12px', fontSize: 14 } }, '🔵 Memcached Server Connection' ),
				createElement( 'div', { className: 'wpts-form-group' },
					createElement( 'label', { className: 'wpts-form-label' }, 'Memcached Host' ),
					createElement( 'input', { className: 'wpts-form-input', value: s.memcached_host || '127.0.0.1', onChange: function ( e ) { u( 'memcached_host', e.target.value ); } } )
				),
				createElement( 'div', { className: 'wpts-form-group' },
					createElement( 'label', { className: 'wpts-form-label' }, 'Memcached Port' ),
					createElement( 'input', { className: 'wpts-form-input', type: 'number', value: s.memcached_port || 11211, onChange: function ( e ) { u( 'memcached_port', parseInt( e.target.value, 10 ) || 11211 ); } } )
				),
				createElement( 'div', { style: { display: 'flex', alignItems: 'center', flexWrap: 'wrap', gap: 12, marginTop: 16 } },
					createElement( 'button', { type: 'button', className: 'button', disabled: testingMc, onClick: testMemcached }, testingMc ? '⏳ Testing…' : '🔌 Test Memcached Connection' ),
					mcStatus ? createElement( 'span', {
						style: {
							fontSize: 13,
							fontWeight: 600,
							padding: '4px 10px',
							borderRadius: 6,
							background: mcStatusType === 'success' ? '#dcfce7' : ( mcStatusType === 'error' ? '#fee2e2' : '#f1f5f9' ),
							color: mcStatusType === 'success' ? '#15803d' : ( mcStatusType === 'error' ? '#b91c1c' : '#475569' ),
							border: '1px solid ' + ( mcStatusType === 'success' ? '#86efac' : ( mcStatusType === 'error' ? '#fca5a5' : '#cbd5e1' ) )
						}
					}, mcStatus ) : null
				)
			),

			createElement( 'div', { className: 'wpts-form-group' },
				createElement( 'label', { className: 'wpts-form-label' }, 'Cache TTL (seconds)' ),
				createElement( 'input', {
					type: 'number',
					className: 'wpts-form-input',
					value: s.cache_ttl || 300,
					onChange: function ( e ) { u( 'cache_ttl', parseInt( e.target.value, 10 ) ); }
				} ),
				createElement( 'div', { style: { display: 'flex', gap: 6, marginTop: 6 } },
					[ [ 60, '1m' ], [ 300, '5m' ], [ 3600, '1h' ], [ 86400, '24h' ] ].map( function ( p ) {
						return createElement( 'button', {
							key: p[0],
							type: 'button',
							className: 'button button-small',
							onClick: function () { u( 'cache_ttl', p[0] ); }
						}, p[1] );
					} )
				)
			),

			createElement( 'div', { style: { marginTop: 20 } },
				createElement( 'button', { type: 'button', className: 'button button-secondary', onClick: flushCache }, '🧹 Flush Cache Now' )
			),
			createElement( SettingsSaveRow, props )
		);
	}

	/* Tab: Redis (Cache Page) */
	function RedisConfigTab( props ) {
		var s = props.settings || {};
		var u = props.update;
		var [ status, setStatus ] = useState( '' );
		var [ statusType, setStatusType ] = useState( '' );
		var [ testing, setTesting ] = useState( false );

		var testRedis = function () {
			setTesting( true );
			setStatus( 'Testing Redis connection…' );
			setStatusType( 'info' );
			api( 'test-connection', 'POST', Object.assign( { target: 'redis' }, s ) )
				.then( function ( res ) {
					setTesting( false );
					if ( res && res.success ) {
						setStatus( '✅ ' + res.message );
						setStatusType( 'success' );
					} else {
						setStatus( '❌ ' + ( ( res && res.message ) || 'Connection failed.' ) );
						setStatusType( 'error' );
					}
				} )
				.catch( function ( err ) {
					setTesting( false );
					var msg = ( err && ( err.message || err.error ) ) || 'Connection test request failed.';
					setStatus( '❌ ' + msg );
					setStatusType( 'error' );
				} );
		};

		return createElement( 'div', { className: 'wpts-app-panel' },
			createElement( 'h3', { className: 'wpts-app-panel__title' }, '🔴 Redis Configuration' ),
			createElement( 'p', { className: 'wpts-app-panel__desc' },
				'Connect to a local or remote Redis instance for ultra-fast, sub-millisecond search result caching.'
			),
			createElement( 'div', { className: 'wpts-form-group' },
				createElement( 'label', { className: 'wpts-form-label' }, 'Redis Host' ),
				createElement( 'input', { className: 'wpts-form-input', value: s.redis_host || '127.0.0.1', onChange: function ( e ) { u( 'redis_host', e.target.value ); } } )
			),
			createElement( 'div', { className: 'wpts-form-group' },
				createElement( 'label', { className: 'wpts-form-label' }, 'Redis Port' ),
				createElement( 'input', { className: 'wpts-form-input', value: s.redis_port || 6379, onChange: function ( e ) { u( 'redis_port', parseInt( e.target.value, 10 ) || 6379 ); } } )
			),
			createElement( 'div', { className: 'wpts-form-group' },
				createElement( 'label', { className: 'wpts-form-label' }, 'Redis Password' ),
				createElement( 'input', { type: 'password', className: 'wpts-form-input', placeholder: 'Optional (leave blank if no AUTH)', value: s.redis_password || '', onChange: function ( e ) { u( 'redis_password', e.target.value ); } } )
			),
			createElement( 'div', { className: 'wpts-form-group' },
				createElement( 'label', { className: 'wpts-form-label' }, 'Redis Database Index' ),
				createElement( 'input', { type: 'number', className: 'wpts-form-input', value: s.redis_db || 0, min: 0, max: 15, onChange: function ( e ) { u( 'redis_db', parseInt( e.target.value, 10 ) || 0 ); } } )
			),
			createElement( 'div', { style: { display: 'flex', alignItems: 'center', flexWrap: 'wrap', gap: 12, marginTop: 16 } },
				createElement( 'button', { type: 'button', className: 'button', disabled: testing, onClick: testRedis }, testing ? '⏳ Testing…' : '🔌 Test Redis Connection' ),
				status ? createElement( 'span', {
					style: {
						fontSize: 13,
						fontWeight: 600,
						padding: '4px 10px',
						borderRadius: 6,
						background: statusType === 'success' ? '#dcfce7' : ( statusType === 'error' ? '#fee2e2' : '#f1f5f9' ),
						color: statusType === 'success' ? '#15803d' : ( statusType === 'error' ? '#b91c1c' : '#475569' ),
						border: '1px solid ' + ( statusType === 'success' ? '#86efac' : ( statusType === 'error' ? '#fca5a5' : '#cbd5e1' ) )
					}
				}, status ) : null
			),
			createElement( SettingsSaveRow, props )
		);
	}

	/* Tab: Memcached (Cache Page) */
	function MemcachedConfigTab( props ) {
		var s = props.settings || {};
		var u = props.update;
		var [ status, setStatus ] = useState( '' );
		var [ statusType, setStatusType ] = useState( '' );
		var [ testing, setTesting ] = useState( false );

		var testMemcached = function () {
			setTesting( true );
			setStatus( 'Testing Memcached connection…' );
			setStatusType( 'info' );
			api( 'test-connection', 'POST', Object.assign( { target: 'memcached' }, s ) )
				.then( function ( res ) {
					setTesting( false );
					if ( res && res.success ) {
						setStatus( '✅ ' + res.message );
						setStatusType( 'success' );
					} else {
						setStatus( '❌ ' + ( ( res && res.message ) || 'Connection failed.' ) );
						setStatusType( 'error' );
					}
				} )
				.catch( function ( err ) {
					setTesting( false );
					var msg = ( err && ( err.message || err.error ) ) || 'Connection test request failed.';
					setStatus( '❌ ' + msg );
					setStatusType( 'error' );
				} );
		};

		return createElement( 'div', { className: 'wpts-app-panel' },
			createElement( 'h3', { className: 'wpts-app-panel__title' }, '🔵 Memcached Configuration' ),
			createElement( 'p', { className: 'wpts-app-panel__desc' },
				'Connect to a high-throughput Memcached daemon to cache search queries across your site.'
			),
			createElement( 'div', { className: 'wpts-form-group' },
				createElement( 'label', { className: 'wpts-form-label' }, 'Memcached Host' ),
				createElement( 'input', { className: 'wpts-form-input', value: s.memcached_host || '127.0.0.1', onChange: function ( e ) { u( 'memcached_host', e.target.value ); } } )
			),
			createElement( 'div', { className: 'wpts-form-group' },
				createElement( 'label', { className: 'wpts-form-label' }, 'Memcached Port' ),
				createElement( 'input', { className: 'wpts-form-input', value: s.memcached_port || 11211, onChange: function ( e ) { u( 'memcached_port', parseInt( e.target.value, 10 ) || 11211 ); } } )
			),
			createElement( 'div', { style: { display: 'flex', alignItems: 'center', flexWrap: 'wrap', gap: 12, marginTop: 16 } },
				createElement( 'button', { type: 'button', className: 'button', disabled: testing, onClick: testMemcached }, testing ? '⏳ Testing…' : '🔌 Test Memcached Connection' ),
				status ? createElement( 'span', {
					style: {
						fontSize: 13,
						fontWeight: 600,
						padding: '4px 10px',
						borderRadius: 6,
						background: statusType === 'success' ? '#dcfce7' : ( statusType === 'error' ? '#fee2e2' : '#f1f5f9' ),
						color: statusType === 'success' ? '#15803d' : ( statusType === 'error' ? '#b91c1c' : '#475569' ),
						border: '1px solid ' + ( statusType === 'success' ? '#86efac' : ( statusType === 'error' ? '#fca5a5' : '#cbd5e1' ) )
					}
				}, status ) : null
			),
			createElement( SettingsSaveRow, props )
		);
	}

	/* Tab: CDN Headers (Cache Page) */
	function CdnHeadersTab( props ) {
		var s = props.settings || {};
		var u = props.update;

		return createElement( 'div', { className: 'wpts-app-panel' },
			createElement( 'h3', { className: 'wpts-app-panel__title' }, '🌐 Edge & CDN Caching Headers' ),
			createElement( 'label', { className: 'wpts-toggle-wrap' },
				createElement( 'div', { className: 'wpts-switch ' + ( s.cdn_cache_headers ? 'is-checked' : '' ), onClick: function () { u( 'cdn_cache_headers', ! s.cdn_cache_headers ); } },
					createElement( 'div', { className: 'wpts-switch-thumb' } )
				),
				createElement( 'span', null, 'Generate CDN Caching Headers (Cache-Control: public, s-maxage, ETag, Vary)' )
			),
			createElement( 'div', { className: 'wpts-form-group', style: { marginTop: 16 } },
				createElement( 'label', { className: 'wpts-form-label' }, 'CDN Edge Max-Age / TTL (seconds)' ),
				createElement( 'input', { type: 'number', className: 'wpts-form-input', value: s.cdn_cache_ttl !== undefined ? s.cdn_cache_ttl : 300, onChange: function ( e ) { u( 'cdn_cache_ttl', parseInt( e.target.value, 10 ) || 300 ); } } )
			),
			createElement( SettingsSaveRow, props )
		);
	}

	/* Tab: Flush Cache (Cache Page) */
	function FlushCacheTab( props ) {
		var flushCache = function () {
			api( 'flush-cache', 'POST' ).then( function ( res ) {
				if ( res && res.success ) props.showToast( res.message );
			} );
		};

		var flushEngine = function () {
			if ( ! confirm( 'Are you sure you want to completely wipe all indexed documents and search caches from the search engine? (Your WordPress posts and pages will NOT be affected).' ) ) return;
			api( 'flush-engine', 'POST' ).then( function ( res ) {
				if ( res && res.success ) {
					props.showToast( res.message );
					if ( props.loadConfig ) props.loadConfig();
				}
			} );
		};

		return createElement( 'div', { className: 'wpts-app-panel' },
			createElement( 'h3', { className: 'wpts-app-panel__title' }, '🧹 Cache & Engine Index Management' ),
			createElement( 'p', { className: 'wpts-app-panel__desc' }, 'Manage stored query caches and engine index data.' ),
			createElement( 'div', { style: { display: 'flex', gap: 12, flexWrap: 'wrap', marginTop: 16 } },
				createElement( 'button', { type: 'button', className: 'wpts-btn-primary', onClick: flushCache }, '🧹 Purge All Search Query Cache' ),
				createElement( 'button', { type: 'button', className: 'button button-link-delete', style: { color: '#ef4444', border: '1px solid #fca5a5', padding: '6px 14px', borderRadius: 6, fontWeight: 600 }, onClick: flushEngine }, '🗑️ Wipe All Engine Index Data' )
			)
		);
	}

	/* Helper: Settings Save Button Row */
	function SettingsSaveRow( props ) {
		if ( ! props.save ) return null;
		return createElement( 'div', { style: { marginTop: 24, paddingTop: 16, borderTop: '1px solid #e2e8f0', display: 'flex', alignItems: 'center', justifyContent: 'space-between' } },
			createElement( 'button', {
				type: 'button',
				className: 'wpts-btn-primary',
				disabled: props.saving,
				onClick: props.save
			}, props.saving ? 'Saving Changes…' : '💾 Save Settings' ),
			props.isDirty
				? createElement( 'span', { style: { color: '#ca8a04', fontSize: 13, fontWeight: 600 } }, '● Unsaved changes' )
				: createElement( 'span', { style: { color: '#16a34a', fontSize: 13 } }, '✓ All settings saved' )
		);
	}

	/* Tab: General (Settings Page) */
	function GeneralTab( props ) {
		var s = props.settings || {};
		var c = props.config || {};
		var u = props.update;

		var togglePt = function ( name ) {
			var current = Array.isArray( s.post_types ) ? s.post_types.slice() : [];
			var idx = current.indexOf( name );
			if ( idx !== -1 ) current.splice( idx, 1 );
			else current.push( name );
			u( 'post_types', current );
		};

		return createElement( 'div', { className: 'wpts-app-panel' },
			createElement( 'h3', { className: 'wpts-app-panel__title' }, '⚙️ General Search Settings' ),

			createElement( 'div', { className: 'wpts-form-group' },
				createElement( 'label', { className: 'wpts-toggle-wrap' },
					createElement( 'div', { className: 'wpts-switch ' + ( s.enable_frontend_search ? 'is-checked' : '' ), onClick: function () { u( 'enable_frontend_search', ! s.enable_frontend_search ); } },
						createElement( 'div', { className: 'wpts-switch-thumb' } )
					),
					createElement( 'span', null, 'Enable Frontend Instant Search' )
				)
			),

			createElement( 'div', { className: 'wpts-form-group' },
				createElement( 'label', { className: 'wpts-form-label' }, 'Post Types to Include in Search Index' ),
				createElement( 'div', { style: { display: 'flex', flexWrap: 'wrap', gap: 12, marginTop: 8 } },
					( c.post_types || [] ).map( function ( pt ) {
						var checked = Array.isArray( s.post_types ) && s.post_types.indexOf( pt.name ) !== -1;
						return createElement( 'label', { key: pt.name, style: { display: 'flex', alignItems: 'center', gap: 6, fontSize: 13.5, cursor: 'pointer' } },
							createElement( 'input', {
								type: 'checkbox',
								checked: checked,
								onChange: function () { togglePt( pt.name ); }
							} ),
							createElement( 'span', null, pt.label + ' (' + pt.name + ')' )
						);
					} )
				)
			),

			createElement( 'div', { className: 'wpts-form-group' },
				createElement( 'label', { className: 'wpts-form-label' }, 'Debounce Delay (ms)' ),
				createElement( 'input', {
					type: 'number',
					className: 'wpts-form-input',
					value: s.debounce_ms || 200,
					min: 0,
					max: 2000,
					onChange: function ( e ) { u( 'debounce_ms', parseInt( e.target.value, 10 ) || 0 ); }
				} ),
				createElement( 'div', { className: 'wpts-form-help' }, 'Time to wait after user stops typing before search executes.' )
			),

			createElement( 'div', { className: 'wpts-form-group' },
				createElement( 'label', { className: 'wpts-form-label' }, 'Results Per Page / Dropdown Max Items' ),
				createElement( 'input', {
					type: 'number',
					className: 'wpts-form-input',
					value: s.results_per_page || 10,
					min: 1,
					max: 100,
					onChange: function ( e ) { u( 'results_per_page', parseInt( e.target.value, 10 ) || 10 ); }
				} )
			),

			createElement( 'div', { className: 'wpts-form-group' },
				createElement( 'label', { className: 'wpts-toggle-wrap' },
					createElement( 'div', { className: 'wpts-switch ' + ( s.highlight_results ? 'is-checked' : '' ), onClick: function () { u( 'highlight_results', ! s.highlight_results ); } },
						createElement( 'div', { className: 'wpts-switch-thumb' } )
					),
					createElement( 'span', null, 'Highlight Search Keyword Matches in Results' )
				)
			),

			createElement( 'div', { className: 'wpts-form-group' },
				createElement( 'label', { className: 'wpts-form-label' }, '⏱️ Admin Dashboard Live Auto-Refresh' ),
				createElement( 'select', {
					className: 'wpts-form-select',
					style: { maxWidth: 300 },
					value: s.admin_auto_refresh !== undefined ? s.admin_auto_refresh : 30,
					onChange: function ( e ) {
						var val = parseInt( e.target.value, 10 );
						u( 'admin_auto_refresh', val );
						try { localStorage.setItem( 'wpts_auto_refresh_rate', val ); } catch ( err ) {}
					}
				},
					createElement( 'option', { value: 0 }, 'Auto-refresh: Off (Manual only)' ),
					createElement( 'option', { value: 10 }, 'Every 10 Seconds' ),
					createElement( 'option', { value: 30 }, 'Every 30 Seconds (Default)' ),
					createElement( 'option', { value: 60 }, 'Every 60 Seconds' )
				),
				createElement( 'div', { className: 'wpts-form-help' }, 'Controls how often the Dashboard and Tracking Analytics pages update in real-time in the background without refreshing the browser.' )
			),

			createElement( SettingsSaveRow, props )
		);
	}

	/* Tab: Weights (Settings Page) */
	function WeightsTab( props ) {
		var s = props.settings;
		var u = props.update;

		var sliders = [
			{ key: 'weight_title',      name: 'Post Title',        min: 1, max: 30 },
			{ key: 'weight_excerpt',    name: 'Excerpt & Summary', min: 1, max: 20 },
			{ key: 'weight_content',    name: 'Content Body',      min: 1, max: 10 },
			{ key: 'weight_taxonomies', name: 'Tags & Categories', min: 1, max: 20 },
			{ key: 'weight_meta',       name: 'Custom Fields/ACF', min: 1, max: 10 },
			{ key: 'weight_comments',   name: 'Comments',          min: 1, max: 10 },
		];

		return createElement( 'div', { className: 'wpts-app-panel' },
			createElement( 'h3', { className: 'wpts-app-panel__title' }, '⚖️ Relevance & Field Weights' ),
			createElement( 'p', { className: 'wpts-app-panel__desc' }, 'Adjust the relevance multiplier for matches found in specific fields. Higher multipliers rank matching documents higher.' ),

			sliders.map( function ( item ) {
				var val = s[item.key] || 1;
				return createElement( 'div', { key: item.key, className: 'wpts-slider-row' },
					createElement( 'span', { className: 'wpts-slider-name' }, item.name ),
					createElement( 'input', {
						type: 'range',
						className: 'wpts-slider-range',
						min: item.min,
						max: item.max,
						value: val,
						onChange: function ( e ) { u( item.key, parseInt( e.target.value, 10 ) ); }
					} ),
					createElement( 'span', { className: 'wpts-slider-val' }, val + 'x' )
				);
			} ),

			createElement( 'h4', { style: { margin: '24px 0 12px', fontSize: 14 } }, 'Search Quality & Query Expansion' ),

			createElement( 'label', { className: 'wpts-toggle-wrap' },
				createElement( 'div', { className: 'wpts-switch ' + ( s.enable_spelling_suggestions ? 'is-checked' : '' ), onClick: function () { u( 'enable_spelling_suggestions', ! s.enable_spelling_suggestions ); } },
					createElement( 'div', { className: 'wpts-switch-thumb' } )
				),
				createElement( 'span', null, 'Enable "Did you mean...?" spelling correction on low/zero hits' )
			),

			createElement( 'label', { className: 'wpts-toggle-wrap' },
				createElement( 'div', { className: 'wpts-switch ' + ( s.enable_synonyms ? 'is-checked' : '' ), onClick: function () { u( 'enable_synonyms', ! s.enable_synonyms ); } },
					createElement( 'div', { className: 'wpts-switch-thumb' } )
				),
				createElement( 'span', null, 'Enable automatic synonym expansion' )
			),

			createElement( 'label', { className: 'wpts-toggle-wrap' },
				createElement( 'div', { className: 'wpts-switch ' + ( s.enable_boolean_search ? 'is-checked' : '' ), onClick: function () { u( 'enable_boolean_search', ! s.enable_boolean_search ); } },
					createElement( 'div', { className: 'wpts-switch-thumb' } )
				),
				createElement( 'span', null, 'Enable boolean search operators (+term, -term, AND, OR, NOT)' )
			),

			createElement( 'label', { className: 'wpts-toggle-wrap' },
				createElement( 'div', { className: 'wpts-switch ' + ( s.enable_phrase_search ? 'is-checked' : '' ), onClick: function () { u( 'enable_phrase_search', ! s.enable_phrase_search ); } },
					createElement( 'div', { className: 'wpts-switch-thumb' } )
				),
				createElement( 'span', null, 'Enable exact phrase matching ("exact phrase")' )
			),

			createElement( SettingsSaveRow, props )
		);
	}

	/* Tab: Synonyms (Settings Page) */
	function SynonymsTab( props ) {
		var [ input, setInput ] = useState( '' );
		var [ adding, setAdding ] = useState( false );

		var addSynonym = function () {
			var w = input.trim();
			if ( ! w ) return;
			setAdding( true );
			api( 'synonyms', 'POST', { words: w } )
				.then( function ( res ) {
					setAdding( false );
					if ( res && res.success ) {
						props.setSynonyms( res.synonyms || [] );
						setInput( '' );
						props.showToast( 'Synonym group added!' );
					}
				} )
				.catch( function () { setAdding( false ); } );
		};

		var deleteSynonym = function ( id ) {
			if ( ! confirm( 'Delete this synonym group?' ) ) return;
			api( 'synonyms/' + id, 'DELETE' )
				.then( function ( res ) {
					if ( res && res.success ) {
						props.setSynonyms( res.synonyms || [] );
						props.showToast( 'Synonym group removed.' );
					}
				} );
		};

		return createElement( 'div', { className: 'wpts-app-panel' },
			createElement( 'h3', { className: 'wpts-app-panel__title' }, '📖 Custom Synonym Dictionary' ),
			createElement( 'p', { className: 'wpts-app-panel__desc' }, 'Add comma-separated synonym groups (e.g. "phone, mobile, smartphone"). Searching for any word will return documents matching the whole group.' ),

			createElement( 'div', { style: { display: 'flex', gap: 10, marginBottom: 20 } },
				createElement( 'input', {
					type: 'text',
					className: 'wpts-form-input',
					placeholder: 'e.g. jacket, coat, outerwear',
					value: input,
					onChange: function ( e ) { setInput( e.target.value ); },
					onKeyDown: function ( e ) { if ( e.key === 'Enter' ) addSynonym(); }
				} ),
				createElement( 'button', {
					type: 'button',
					className: 'wpts-btn-primary',
					disabled: adding || ! input.trim(),
					onClick: addSynonym
				}, adding ? 'Adding…' : '➕ Add Synonym' )
			),

			createElement( 'div', { className: 'wpts-synonym-chips' },
				( props.synonyms || [] ).map( function ( item ) {
					return createElement( 'div', { key: item.id, className: 'wpts-synonym-chip' },
						createElement( 'span', null, item.words ),
						createElement( 'button', {
							type: 'button',
							className: 'wpts-chip-del',
							title: 'Delete group',
							onClick: function () { deleteSynonym( item.id ); }
						}, '×' )
					);
				} )
			),

			( ! props.synonyms || ! props.synonyms.length ) && createElement( 'p', { style: { color: '#64748b', fontStyle: 'italic' } }, 'No custom synonyms added yet. 20+ built-in sets are active by default.' )
		);
	}

	/* Tab: Content Coverage (Settings Page) */
	function CoverageTab( props ) {
		var s = props.settings;
		var u = props.update;

		return createElement( 'div', { className: 'wpts-app-panel' },
			createElement( 'h3', { className: 'wpts-app-panel__title' }, '🗂️ Content Coverage & Attachments' ),
			createElement( 'p', { className: 'wpts-app-panel__desc' }, 'Select additional content types to extract and index into the search engine.' ),

			createElement( 'label', { className: 'wpts-toggle-wrap' },
				createElement( 'div', { className: 'wpts-switch ' + ( s.index_woocommerce ? 'is-checked' : '' ), onClick: function () { u( 'index_woocommerce', ! s.index_woocommerce ); } },
					createElement( 'div', { className: 'wpts-switch-thumb' } )
				),
				createElement( 'span', null, 'WooCommerce Products: Index SKU, price, and stock status' )
			),

			createElement( 'label', { className: 'wpts-toggle-wrap' },
				createElement( 'div', { className: 'wpts-switch ' + ( s.index_attachments ? 'is-checked' : '' ), onClick: function () { u( 'index_attachments', ! s.index_attachments ); } },
					createElement( 'div', { className: 'wpts-switch-thumb' } )
				),
				createElement( 'span', null, 'PDF & Attached Documents: Extract and search text from PDF, DOCX, and TXT files' )
			),

			createElement( 'label', { className: 'wpts-toggle-wrap' },
				createElement( 'div', { className: 'wpts-switch ' + ( s.index_thumbnails ? 'is-checked' : '' ), onClick: function () { u( 'index_thumbnails', ! s.index_thumbnails ); } },
					createElement( 'div', { className: 'wpts-switch-thumb' } )
				),
				createElement( 'span', null, 'Featured Images: Index and display post thumbnails in search results' )
			),

			createElement( 'label', { className: 'wpts-toggle-wrap' },
				createElement( 'div', { className: 'wpts-switch ' + ( s.index_comments ? 'is-checked' : '' ), onClick: function () { u( 'index_comments', ! s.index_comments ); } },
					createElement( 'div', { className: 'wpts-switch-thumb' } )
				),
				createElement( 'span', null, 'Comments: Include approved post comments and author names in index' )
			),

			createElement( 'label', { className: 'wpts-toggle-wrap' },
				createElement( 'div', { className: 'wpts-switch ' + ( s.search_in_meta ? 'is-checked' : '' ), onClick: function () { u( 'search_in_meta', ! s.search_in_meta ); } },
					createElement( 'div', { className: 'wpts-switch-thumb' } )
				),
				createElement( 'span', null, 'Custom Fields / ACF: Include post meta in search index' )
			),

			createElement( SettingsSaveRow, props )
		);
	}

	/* Tab: Search Engines (Settings Page) */
	function EnginesTab( props ) {
		var s = props.settings || {};
		var u = props.update;
		var [ tsStatus, setTsStatus ] = useState( '' );
		var [ esStatus, setEsStatus ] = useState( '' );
		var [ testing, setTesting ]   = useState( false );

		var testEngine = function ( target ) {
			setTesting( true );
			if ( target === 'typesense' ) setTsStatus( 'Testing Typesense…' );
			else setEsStatus( 'Testing Elasticsearch…' );

			api( 'test-connection', 'POST', Object.assign( { target: target }, s ) )
				.then( function ( res ) {
					setTesting( false );
					var msg = ( res && res.message ) || ( res && res.success ? 'Connected successfully.' : 'Connection failed.' );
					if ( target === 'typesense' ) setTsStatus( res && res.success ? '✅ ' + msg : '❌ ' + msg );
					else setEsStatus( res && res.success ? '✅ ' + msg : '❌ ' + msg );
				} )
				.catch( function ( err ) {
					setTesting( false );
					var msg = ( err && ( err.message || err.error ) ) || 'Request failed. Check endpoint and credentials.';
					if ( target === 'typesense' ) setTsStatus( '❌ ' + msg );
					else setEsStatus( '❌ ' + msg );
				} );
		};

		return createElement( 'div', { className: 'wpts-app-panel' },
			createElement( 'h3', { className: 'wpts-app-panel__title' }, '🚀 Search Engines' ),

			createElement( 'div', { className: 'wpts-form-group' },
				createElement( 'label', { className: 'wpts-form-label' }, 'Primary Search Engine Driver' ),
				createElement( 'select', {
					className: 'wpts-form-select',
					value: s.search_engine || 'mysql',
					onChange: function ( e ) {
						var val = e.target.value;
						var patch = { search_engine: val };
						if ( val === 'typesense' ) {
							if ( ! s.typesense_host ) patch.typesense_host = '127.0.0.1';
							if ( ! s.typesense_port ) patch.typesense_port = '8108';
							if ( ! s.typesense_protocol ) patch.typesense_protocol = 'http';
							if ( ! s.typesense_collection ) patch.typesense_collection = 'wpts_posts';
						} else if ( val === 'elasticsearch' ) {
							if ( ! s.elasticsearch_host ) patch.elasticsearch_host = '127.0.0.1';
							if ( ! s.elasticsearch_port ) patch.elasticsearch_port = '9200';
							if ( ! s.elasticsearch_protocol ) patch.elasticsearch_protocol = 'http';
							if ( ! s.elasticsearch_index ) patch.elasticsearch_index = 'wpts_posts';
						}
						u( patch );
					}
				},
					createElement( 'option', { value: 'mysql' }, 'MySQL FULLTEXT (Local - Zero Setup)' ),
					createElement( 'option', { value: 'typesense' }, 'Typesense Server' ),
					createElement( 'option', { value: 'elasticsearch' }, 'Elasticsearch / OpenSearch Cluster' )
				)
			),

			// Typesense Config
			s.search_engine === 'typesense' && createElement( 'div', { style: { marginTop: 20, padding: 16, background: '#f8fafc', borderRadius: 8, border: '1px solid #e2e8f0' } },
				createElement( 'h4', { style: { margin: '0 0 12px' } }, 'Typesense Connection' ),
				createElement( 'div', { className: 'wpts-form-group' },
					createElement( 'label', { className: 'wpts-form-label' }, 'Host' ),
					createElement( 'input', { className: 'wpts-form-input', placeholder: '127.0.0.1 or cluster url', value: s.typesense_host || '', onChange: function ( e ) { u( 'typesense_host', e.target.value ); } } )
				),
				createElement( 'div', { className: 'wpts-form-group' },
					createElement( 'label', { className: 'wpts-form-label' }, 'Port' ),
					createElement( 'input', { className: 'wpts-form-input', placeholder: '8108', value: s.typesense_port || '8108', onChange: function ( e ) { u( 'typesense_port', e.target.value ); } } )
				),
				createElement( 'div', { className: 'wpts-form-group' },
					createElement( 'label', { className: 'wpts-form-label' }, 'Protocol' ),
					createElement( 'select', { className: 'wpts-form-select', style: { maxWidth: 200 }, value: s.typesense_protocol || 'http', onChange: function ( e ) { u( 'typesense_protocol', e.target.value ); } },
						createElement( 'option', { value: 'http' }, 'HTTP' ),
						createElement( 'option', { value: 'https' }, 'HTTPS' )
					)
				),
				createElement( 'div', { className: 'wpts-form-group' },
					createElement( 'label', { className: 'wpts-form-label' }, 'Collection Name' ),
					createElement( 'input', { className: 'wpts-form-input', placeholder: 'wpts_posts', value: s.typesense_collection || 'wpts_posts', onChange: function ( e ) { u( 'typesense_collection', e.target.value ); } } )
				),
				createElement( 'div', { className: 'wpts-form-group' },
					createElement( 'label', { className: 'wpts-form-label' }, 'API Key' ),
					createElement( 'input', { type: 'password', className: 'wpts-form-input', placeholder: 'Search / Admin API Key', value: s.typesense_api_key || '', onChange: function ( e ) { u( 'typesense_api_key', e.target.value ); } } )
				),
				createElement( 'div', { style: { display: 'flex', alignItems: 'center', gap: 12, marginTop: 16 } },
					createElement( 'button', { type: 'button', className: 'button', disabled: testing, onClick: function () { testEngine( 'typesense' ); } }, testing ? '⏳ Testing…' : '🔌 Test Typesense Connection' ),
					tsStatus && createElement( 'span', { style: { fontSize: 13, fontWeight: 600 } }, tsStatus )
				)
			),

			// Elasticsearch Config
			s.search_engine === 'elasticsearch' && createElement( 'div', { style: { marginTop: 20, padding: 16, background: '#f8fafc', borderRadius: 8, border: '1px solid #e2e8f0' } },
				createElement( 'h4', { style: { margin: '0 0 12px' } }, 'Elasticsearch / OpenSearch Connection' ),
				createElement( 'div', { className: 'wpts-form-group' },
					createElement( 'label', { className: 'wpts-form-label' }, 'Host' ),
					createElement( 'input', { className: 'wpts-form-input', placeholder: '127.0.0.1 or cluster host', value: s.elasticsearch_host || '', onChange: function ( e ) { u( 'elasticsearch_host', e.target.value ); } } )
				),
				createElement( 'div', { className: 'wpts-form-group' },
					createElement( 'label', { className: 'wpts-form-label' }, 'Port' ),
					createElement( 'input', { className: 'wpts-form-input', placeholder: '9200', value: s.elasticsearch_port || '9200', onChange: function ( e ) { u( 'elasticsearch_port', e.target.value ); } } )
				),
				createElement( 'div', { className: 'wpts-form-group' },
					createElement( 'label', { className: 'wpts-form-label' }, 'Protocol' ),
					createElement( 'select', { className: 'wpts-form-select', style: { maxWidth: 200 }, value: s.elasticsearch_protocol || 'http', onChange: function ( e ) { u( 'elasticsearch_protocol', e.target.value ); } },
						createElement( 'option', { value: 'http' }, 'HTTP' ),
						createElement( 'option', { value: 'https' }, 'HTTPS' )
					)
				),
				createElement( 'div', { className: 'wpts-form-group' },
					createElement( 'label', { className: 'wpts-form-label' }, 'Index Name' ),
					createElement( 'input', { className: 'wpts-form-input', placeholder: 'wpts_posts', value: s.elasticsearch_index || 'wpts_posts', onChange: function ( e ) { u( 'elasticsearch_index', e.target.value ); } } )
				),
				createElement( 'div', { className: 'wpts-form-group' },
					createElement( 'label', { className: 'wpts-form-label' }, 'API Key (Token Auth)' ),
					createElement( 'input', { type: 'password', className: 'wpts-form-input', placeholder: 'Optional if using Basic Auth', value: s.elasticsearch_api_key || '', onChange: function ( e ) { u( 'elasticsearch_api_key', e.target.value ); } } )
				),
				createElement( 'div', { className: 'wpts-form-group' },
					createElement( 'label', { className: 'wpts-form-label' }, 'Username (Basic Auth)' ),
					createElement( 'input', { className: 'wpts-form-input', placeholder: 'Optional (e.g. elastic)', value: s.elasticsearch_username || '', onChange: function ( e ) { u( 'elasticsearch_username', e.target.value ); } } )
				),
				createElement( 'div', { className: 'wpts-form-group' },
					createElement( 'label', { className: 'wpts-form-label' }, 'Password (Basic Auth)' ),
					createElement( 'input', { type: 'password', className: 'wpts-form-input', placeholder: 'Optional password', value: s.elasticsearch_password || '', onChange: function ( e ) { u( 'elasticsearch_password', e.target.value ); } } )
				),
				createElement( 'div', { style: { display: 'flex', alignItems: 'center', gap: 12, marginTop: 16 } },
					createElement( 'button', { type: 'button', className: 'button', disabled: testing, onClick: function () { testEngine( 'elasticsearch' ); } }, testing ? '⏳ Testing…' : '🔌 Test Elasticsearch Connection' ),
					esStatus && createElement( 'span', { style: { fontSize: 13, fontWeight: 600 } }, esStatus )
				)
			),

			createElement( SettingsSaveRow, props )
		);
	}

	/* Tab: AI Vector Search (Settings Page) */
	function VectorSearchTab( props ) {
		var s = props.settings || {};
		var u = props.update;

		return createElement( 'div', { className: 'wpts-app-panel' },
			createElement( 'h3', { className: 'wpts-app-panel__title' }, '🧠 Hybrid Semantic & AI Vector Search' ),
			createElement( 'p', { className: 'wpts-app-panel__desc' }, 'Combine traditional fulltext keyword matching with deep semantic AI embeddings for conceptual search.' ),

			createElement( 'label', { className: 'wpts-toggle-wrap' },
				createElement( 'div', { className: 'wpts-switch ' + ( s.enable_vector_search ? 'is-checked' : '' ), onClick: function () { u( 'enable_vector_search', ! s.enable_vector_search ); } },
					createElement( 'div', { className: 'wpts-switch-thumb' } )
				),
				createElement( 'span', null, 'Enable Hybrid Semantic Vector Search' )
			),

			s.enable_vector_search && createElement( 'div', { style: { marginTop: 20, padding: 16, background: '#f8fafc', borderRadius: 8, border: '1px solid #e2e8f0' } },
				createElement( 'div', { className: 'wpts-form-group' },
					createElement( 'label', { className: 'wpts-form-label' }, 'Vector Provider' ),
					createElement( 'select', {
						className: 'wpts-form-select',
						value: s.vector_provider || 'openai',
						onChange: function ( e ) {
							var val = e.target.value;
							var patch = { vector_provider: val };
							if ( val === 'ollama' ) {
								if ( ! s.vector_endpoint ) patch.vector_endpoint = 'http://localhost:11434/api/embeddings';
								if ( ! s.vector_model ) patch.vector_model = 'nomic-embed-text';
							} else if ( val === 'openai' ) {
								if ( ! s.vector_model ) patch.vector_model = 'text-embedding-3-small';
							}
							u( patch );
						}
					},
						createElement( 'option', { value: 'openai' }, 'OpenAI (text-embedding-3-small / ada-002)' ),
						createElement( 'option', { value: 'ollama' }, 'Local Ollama (nomic-embed-text / all-minilm)' ),
						createElement( 'option', { value: 'custom' }, 'Custom HTTP Embedding API Endpoint' )
					)
				),

				s.vector_provider === 'openai' && createElement( 'div', { className: 'wpts-form-group' },
					createElement( 'label', { className: 'wpts-form-label' }, 'OpenAI API Key' ),
					createElement( 'input', {
						type: 'password',
						className: 'wpts-form-input',
						placeholder: 'sk-...',
						value: s.vector_api_key || '',
						onChange: function ( e ) { u( 'vector_api_key', e.target.value ); }
					} )
				),

				( s.vector_provider === 'ollama' || s.vector_provider === 'custom' ) && createElement( 'div', { className: 'wpts-form-group' },
					createElement( 'label', { className: 'wpts-form-label' }, 'Endpoint URL' ),
					createElement( 'input', {
						className: 'wpts-form-input',
						placeholder: s.vector_provider === 'ollama' ? 'http://localhost:11434/api/embeddings' : 'https://api.myembeddings.com/v1',
						value: s.vector_endpoint || '',
						onChange: function ( e ) { u( 'vector_endpoint', e.target.value ); }
					} )
				),

				createElement( 'div', { className: 'wpts-form-group' },
					createElement( 'label', { className: 'wpts-form-label' }, 'Model Name' ),
					createElement( 'input', {
						className: 'wpts-form-input',
						placeholder: s.vector_provider === 'openai' ? 'text-embedding-3-small' : 'nomic-embed-text',
						value: s.vector_model || '',
						onChange: function ( e ) { u( 'vector_model', e.target.value ); }
					} )
				),

				createElement( 'div', { className: 'wpts-form-group' },
					createElement( 'label', { className: 'wpts-form-label' }, 'Vector Semantic Weight (0.0 to 1.0)' ),
					createElement( 'input', {
						type: 'number',
						step: '0.05',
						min: '0.0',
						max: '1.0',
						className: 'wpts-form-input',
						value: s.vector_weight !== undefined ? s.vector_weight : 0.3,
						onChange: function ( e ) { u( 'vector_weight', parseFloat( e.target.value ) ); }
					} ),
					createElement( 'small', { style: { color: '#64748b', display: 'block', marginTop: 4 } }, '0.3 = 70% Keyword fulltext relevance + 30% AI semantic vector similarity.' )
				)
			),

			createElement( SettingsSaveRow, props )
		);
	}

	/* Tab: Frontend (Settings Page) */
	function FrontendTab( props ) {
		var s = props.settings || {};
		var u = props.update;

		return createElement( 'div', { className: 'wpts-app-panel' },
			createElement( 'h3', { className: 'wpts-app-panel__title' }, '🎨 Frontend & UX Settings' ),

			createElement( 'label', { className: 'wpts-toggle-wrap' },
				createElement( 'div', { className: 'wpts-switch ' + ( s.enable_command_k_modal ? 'is-checked' : '' ), onClick: function () { u( 'enable_command_k_modal', ! s.enable_command_k_modal ); } },
					createElement( 'div', { className: 'wpts-switch-thumb' } )
				),
				createElement( 'span', null, 'Enable Command + K / Ctrl + K Spotlight Quick Palette Modal' )
			),

			createElement( 'label', { className: 'wpts-toggle-wrap' },
				createElement( 'div', { className: 'wpts-switch ' + ( s.enable_category_tabs_dropdown ? 'is-checked' : '' ), onClick: function () { u( 'enable_category_tabs_dropdown', ! s.enable_category_tabs_dropdown ); } },
					createElement( 'div', { className: 'wpts-switch-thumb' } )
				),
				createElement( 'span', null, 'Enable Multi-Tab Categorized Dropdown Headers (All, Products, Posts, Docs)' )
			),

			createElement( 'label', { className: 'wpts-toggle-wrap' },
				createElement( 'div', { className: 'wpts-switch ' + ( s.woocommerce_quick_add_to_cart ? 'is-checked' : '' ), onClick: function () { u( 'woocommerce_quick_add_to_cart', ! s.woocommerce_quick_add_to_cart ); } },
					createElement( 'div', { className: 'wpts-switch-thumb' } )
				),
				createElement( 'span', null, 'Enable WooCommerce Instant 1-Click "Add to Cart" & Live Stock Badges' )
			),

			createElement( 'label', { className: 'wpts-toggle-wrap' },
				createElement( 'div', { className: 'wpts-switch ' + ( s.enable_voice_search ? 'is-checked' : '' ), onClick: function () { u( 'enable_voice_search', ! s.enable_voice_search ); } },
					createElement( 'div', { className: 'wpts-switch-thumb' } )
				),
				createElement( 'span', null, 'Enable Web Speech API Voice Search Input (Works with HTTPS connection only)' )
			),

			createElement( 'div', { className: 'wpts-form-group', style: { marginTop: 16 } },
				createElement( 'label', { className: 'wpts-form-label' }, 'Default Results Layout' ),
				createElement( 'select', {
					className: 'wpts-form-select',
					value: s.results_layout || 'list',
					onChange: function ( e ) { u( 'results_layout', e.target.value ); }
				},
					createElement( 'option', { value: 'list' }, '📄 List View (Default)' ),
					createElement( 'option', { value: 'grid' }, '⊞ Grid Cards' ),
					createElement( 'option', { value: 'card' }, '🗂️ Compact Card' )
				),
				createElement( 'div', { className: 'wpts-form-help' }, 'Choose the global default layout for live search dropdowns across all shortcodes, blocks, and widgets.' )
			),

			createElement( 'label', { className: 'wpts-toggle-wrap' },
				createElement( 'div', { className: 'wpts-switch ' + ( s.enable_user_history ? 'is-checked' : '' ), onClick: function () { u( 'enable_user_history', ! s.enable_user_history ); } },
					createElement( 'div', { className: 'wpts-switch-thumb' } )
				),
				createElement( 'span', null, 'Save recent search history for logged-in users' )
			),

			createElement( 'label', { className: 'wpts-toggle-wrap' },
				createElement( 'div', { className: 'wpts-switch ' + ( s.enable_user_favorites ? 'is-checked' : '' ), onClick: function () { u( 'enable_user_favorites', ! s.enable_user_favorites ); } },
					createElement( 'div', { className: 'wpts-switch-thumb' } )
				),
				createElement( 'span', null, 'Allow logged-in users to bookmark favorite queries' )
			),

			createElement( 'label', { className: 'wpts-toggle-wrap' },
				createElement( 'div', { className: 'wpts-switch ' + ( s.enable_archive_live_filter ? 'is-checked' : '' ), onClick: function () { u( 'enable_archive_live_filter', ! s.enable_archive_live_filter ); } },
					createElement( 'div', { className: 'wpts-switch-thumb' } )
				),
				createElement( 'span', null, 'Enable real-time category/archive live DOM filter as you type' )
			),

			createElement( SettingsSaveRow, props )
		);
	}

	/* Tab: Security (Settings Page) */
	function SecurityTab( props ) {
		var s = props.settings;
		var c = props.config || {};
		var u = props.update;
		var roles = c.roles || [];
		var pts = c.post_types || [];
		var restrictions = s.role_restrictions || {};

		var toggleRolePt = function ( roleSlug, ptName ) {
			var copy = Object.assign( {}, restrictions );
			var list = Array.isArray( copy[roleSlug] ) ? copy[roleSlug].slice() : [];
			var idx = list.indexOf( ptName );
			if ( idx !== -1 ) list.splice( idx, 1 );
			else list.push( ptName );
			copy[roleSlug] = list;
			u( 'role_restrictions', copy );
		};

		return createElement( 'div', { className: 'wpts-app-panel' },
			createElement( 'h3', { className: 'wpts-app-panel__title' }, '🔐 Security, Bot Protection & GDPR' ),

			createElement( 'label', { className: 'wpts-toggle-wrap' },
				createElement( 'div', { className: 'wpts-switch ' + ( s.enable_honeypot ? 'is-checked' : '' ), onClick: function () { u( 'enable_honeypot', ! s.enable_honeypot ); } },
					createElement( 'div', { className: 'wpts-switch-thumb' } )
				),
				createElement( 'span', null, 'Honeypot Bot Defense: Trap automated scrapers & rate limit rapid bursts' )
			),

			createElement( 'label', { className: 'wpts-toggle-wrap', style: { marginTop: 12 } },
				createElement( 'div', { className: 'wpts-switch ' + ( s.tracking_enabled ? 'is-checked' : '' ), onClick: function () { u( 'tracking_enabled', ! s.tracking_enabled ); } },
					createElement( 'div', { className: 'wpts-switch-thumb' } )
				),
				createElement( 'span', null, 'Search Analytics & CTR Tracking: Record user searches and click positions' )
			),

			createElement( 'div', { className: 'wpts-form-group', style: { marginTop: 16 } },
				createElement( 'label', { className: 'wpts-form-label' }, 'Analytics Data Retention (days)' ),
				createElement( 'input', {
					type: 'number',
					className: 'wpts-form-input',
					value: s.tracking_retention_days || 90,
					onChange: function ( e ) { u( 'tracking_retention_days', parseInt( e.target.value, 10 ) ); }
				} )
			),

			createElement( 'div', { className: 'wpts-form-group', style: { marginTop: 16 } },
				createElement( 'label', { className: 'wpts-form-label' }, 'GDPR IP Anonymization & Retention (days)' ),
				createElement( 'input', {
					type: 'number',
					className: 'wpts-form-input',
					value: s.gdpr_anonymize_days || 30,
					onChange: function ( e ) { u( 'gdpr_anonymize_days', parseInt( e.target.value, 10 ) ); }
				} )
			),

			createElement( 'h4', { style: { margin: '24px 0 12px', fontSize: 14 } }, 'Per-Role Post Type Restrictions (Hidden from Search)' ),
			createElement( 'table', { className: 'widefat striped', style: { maxWidth: 700 } },
				createElement( 'thead', null,
					createElement( 'tr', null, createElement( 'th', null, 'Role' ), createElement( 'th', null, 'Hidden Post Types' ) )
				),
				createElement( 'tbody', null,
					roles.filter( function ( r ) { return r.slug !== 'administrator'; } ).map( function ( r ) {
						var hidden = restrictions[r.slug] || [];
						return createElement( 'tr', { key: r.slug },
							createElement( 'td', null, createElement( 'strong', null, r.name ) ),
							createElement( 'td', null,
								createElement( 'div', { style: { display: 'flex', gap: 10, flexWrap: 'wrap' } },
									pts.map( function ( pt ) {
										var isHidden = hidden.indexOf( pt.name ) !== -1;
										return createElement( 'label', { key: pt.name, style: { display: 'flex', alignItems: 'center', gap: 4, cursor: 'pointer' } },
											createElement( 'input', {
												type: 'checkbox',
												checked: isHidden,
												onChange: function () { toggleRolePt( r.slug, pt.name ); }
											} ),
											createElement( 'span', null, pt.label )
										);
									} )
								)
							)
						);
					} )
				)
			),

			createElement( SettingsSaveRow, props )
		);
	}

	/* Tab: Index Manager */
	function IndexerTab( props ) {
		var c = props.config || {};
		var p = props.prog;
		var singleIdState = useState( '' );
		var singleId = singleIdState[0];
		var setSingleId = singleIdState[1];
		var singleLoadingState = useState( false );
		var singleLoading = singleLoadingState[0];
		var setSingleLoading = singleLoadingState[1];
		var singleResultState = useState( null );
		var singleResult = singleResultState[0];
		var setSingleResult = singleResultState[1];

		var startReindex = function () {
			if ( ! confirm( 'Re-index all published posts? This runs in safe batches.' ) ) return;
			props.setIndexing( true );
			props.setProg( { page: 1, max_pages: 1, total: 0, pct: 0, done: false, status: 'Starting batch…' } );
			runChunk( 1 );
		};

		var runChunk = function ( page ) {
			api( 'reindex-chunk', 'POST', { page: page, batch: 100 } )
				.then( function ( res ) {
					if ( ! res ) return;
					var pct = res.max_pages > 0 ? Math.min( 100, Math.round( ( res.page / res.max_pages ) * 100 ) ) : 100;
					props.setProg( {
						page: res.page,
						max_pages: res.max_pages,
						total: res.total,
						pct: pct,
						done: res.done,
						status: res.done ? 'Done!' : 'Processing page ' + res.page + ' of ' + res.max_pages + '…'
					} );

					if ( res.done ) {
						props.setIndexing( false );
						props.loadConfig();
					} else {
						runChunk( page + 1 );
					}
				} )
				.catch( function () {
					props.setIndexing( false );
					props.setProg( Object.assign( {}, props.prog, { status: 'Re-indexing interrupted.' } ) );
				} );
		};

		var flushEngine = function () {
			if ( ! confirm( 'Are you sure you want to completely wipe all indexed documents and search caches from the search engine? (Your WordPress posts and pages will NOT be affected).' ) ) return;
			api( 'flush-engine', 'POST' ).then( function ( res ) {
				if ( res && res.success ) {
					props.showToast( res.message );
					if ( props.loadConfig ) props.loadConfig();
				}
			} );
		};

		var indexSinglePost = function () {
			var idNum = parseInt( singleId, 10 );
			if ( ! idNum || idNum <= 0 ) {
				alert( 'Please enter a valid numeric Post or Document ID.' );
				return;
			}
			setSingleLoading( true );
			setSingleResult( null );
			api( 'reindex-post', 'POST', { post_id: idNum } )
				.then( function ( res ) {
					setSingleLoading( false );
					if ( res && res.success ) {
						setSingleResult( res );
						props.showToast( res.message );
						if ( props.loadConfig ) props.loadConfig();
					} else {
						alert( ( res && res.message ) || 'Failed to index item.' );
					}
				} )
				.catch( function ( err ) {
					setSingleLoading( false );
					var msg = ( err && ( err.message || ( err.data && err.data.message ) ) ) || 'Error indexing post.';
					alert( msg );
				} );
		};

		return createElement( 'div', { className: 'wpts-app-panel' },
			createElement( 'h3', { className: 'wpts-app-panel__title' }, '🔄 Index Manager' ),

			createElement( 'div', { style: { maxWidth: 500, marginBottom: 20 } },
				createElement( 'div', { style: { display: 'flex', justifyContent: 'space-between', marginBottom: 6, fontWeight: 600 } },
					createElement( 'span', null, 'Index Coverage' ),
					createElement( 'span', null, ( c.coverage_pct || 0 ) + '%' )
				),
				createElement( 'div', { className: 'wpts-progress-track' },
					createElement( 'div', { className: 'wpts-progress-fill', style: { width: ( c.coverage_pct || 0 ) + '%' } } )
				),
				createElement( 'small', { style: { color: '#64748b' } },
					( c.indexed_count > c.total_posts )
						? ( ( c.indexed_count || 0 ) ).toLocaleString() + ' documents in index (' + ( ( c.total_posts || 0 ) ).toLocaleString() + ' eligible posts configured)'
						: ( ( c.indexed_count || 0 ) ).toLocaleString() + ' of ' + ( ( c.total_posts || 0 ) ).toLocaleString() + ' posts currently indexed.'
				)
			),

			// Reindex progress bar during active indexing
			props.indexing && createElement( 'div', { style: { maxWidth: 500, marginBottom: 20 } },
				createElement( 'div', { className: 'wpts-progress-track' },
					createElement( 'div', { className: 'wpts-progress-fill', style: { width: p.pct + '%' } } ),
					createElement( 'span', { className: 'wpts-progress-label' }, p.pct + '%' )
				),
				createElement( 'p', { style: { margin: '6px 0 0', fontSize: 13, color: '#2563eb', fontWeight: 600 } }, p.status )
			),

			createElement( 'div', { style: { display: 'flex', gap: 12, flexWrap: 'wrap' } },
				createElement( 'button', {
					type: 'button',
					className: 'wpts-btn-primary',
					disabled: props.indexing,
					onClick: startReindex
				}, props.indexing ? 'Re-indexing in progress…' : '↺ Re-index All Posts Now' ),
				createElement( 'button', {
					type: 'button',
					className: 'button button-link-delete',
					disabled: props.indexing,
					style: { color: '#ef4444', border: '1px solid #fca5a5', padding: '6px 14px', borderRadius: 6, fontWeight: 600 },
					onClick: flushEngine
				}, '🗑️ Wipe All Engine Index Data' )
			),

			// Single Post / Document Quick Indexer
			createElement( 'div', { style: { marginTop: 32, paddingTop: 24, borderTop: '1px solid #e2e8f0' } },
				createElement( 'h4', { style: { margin: '0 0 8px', fontSize: 15, fontWeight: 700 } }, '🎯 Single Post / Document Quick Indexer' ),
				createElement( 'p', { style: { fontSize: 13, color: '#64748b', margin: '0 0 16px' } }, 'Re-index or update a single specific post, page, product, or media attachment (PDF, DOCX) by entering its WordPress ID.' ),

				createElement( 'div', { style: { display: 'flex', gap: 10, alignItems: 'center', maxWidth: 450, flexWrap: 'wrap' } },
					createElement( 'input', {
						type: 'number',
						className: 'wpts-form-input',
						style: { width: 180 },
						placeholder: 'Post / Doc ID (e.g. 17)',
						value: singleId,
						onChange: function ( e ) { setSingleId( e.target.value ); },
						onKeyDown: function ( e ) { if ( e.key === 'Enter' ) indexSinglePost(); }
					} ),
					createElement( 'button', {
						type: 'button',
						className: 'wpts-btn-primary',
						disabled: singleLoading || ! singleId,
						onClick: indexSinglePost
					}, singleLoading ? 'Indexing Item…' : '⚡ Index Single Item' )
				),

				singleResult && createElement( 'div', {
					style: {
						marginTop: 16,
						padding: '12px 16px',
						background: singleResult.fallback ? '#fffbeb' : '#f0fdf4',
						border: '1px solid ' + ( singleResult.fallback ? '#fde68a' : '#bbf7d0' ),
						borderRadius: 8,
						maxWidth: 550
					}
				},
					createElement( 'div', { style: { display: 'flex', alignItems: 'center', gap: 8, fontWeight: 700, color: singleResult.fallback ? '#b45309' : '#166534', marginBottom: 4 } },
						( singleResult.fallback ? '⚠️ ' : '✅ ' ) + 'Item #' + singleResult.post_id + ' ("' + singleResult.post_title + '") Indexed!'
					),
					singleResult.fallback && createElement( 'div', { style: { fontSize: 12, color: '#92400e', marginBottom: 6, lineHeight: 1.4 } },
						singleResult.message
					),
					createElement( 'div', { style: { fontSize: 12.5, color: singleResult.fallback ? '#b45309' : '#15803d', display: 'flex', gap: 16, flexWrap: 'wrap' } },
						createElement( 'span', null, 'Type: ', createElement( 'strong', null, singleResult.post_type ) ),
						createElement( 'span', null, 'Status: ', createElement( 'strong', null, singleResult.post_status ) ),
						singleResult.doc_length > 0 && createElement( 'span', null, '📄 Extracted Document: ', createElement( 'strong', null, singleResult.doc_length + ' bytes' ) )
					)
				)
			),

			// Automated Background Scheduled Sync (WP-Cron)
			createElement( 'div', { style: { marginTop: 32, paddingTop: 24, borderTop: '1px solid #e2e8f0' } },
				createElement( 'h4', { style: { margin: '0 0 8px', fontSize: 15, fontWeight: 700 } }, '🤖 Automated Background Sync (WP-Cron)' ),
				createElement( 'p', { style: { fontSize: 13, color: '#64748b', margin: '0 0 16px' } }, 'Schedule automated background synchronization so your search index always stays 100% fresh without any manual effort.' ),

				createElement( 'label', { className: 'wpts-toggle-wrap' },
					createElement( 'div', {
						className: 'wpts-switch ' + ( props.settings && props.settings.scheduled_reindex_enabled ? 'is-checked' : '' ),
						onClick: function () {
							if ( props.update ) props.update( 'scheduled_reindex_enabled', ! ( props.settings && props.settings.scheduled_reindex_enabled ) );
						}
					},
						createElement( 'div', { className: 'wpts-switch-thumb' } )
					),
					createElement( 'span', null, 'Enable Scheduled Background Auto Re-indexing' )
				),

				props.settings && props.settings.scheduled_reindex_enabled && createElement( 'div', { style: { display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16, marginTop: 16, maxWidth: 600 } },
					createElement( 'div', { className: 'wpts-form-group' },
						createElement( 'label', { className: 'wpts-form-label' }, 'Sync Frequency' ),
						createElement( 'select', {
							className: 'wpts-form-select',
							style: { width: '100%', maxWidth: '100%' },
							value: props.settings.scheduled_reindex_interval || 'daily',
							onChange: function ( e ) { if ( props.update ) props.update( 'scheduled_reindex_interval', e.target.value ); }
						},
							createElement( 'option', { value: 'hourly' }, 'Every Hour' ),
							createElement( 'option', { value: 'twicedaily' }, 'Twice Daily (Every 12h)' ),
							createElement( 'option', { value: 'daily' }, 'Once Daily (Midnight)' ),
							createElement( 'option', { value: 'weekly' }, 'Once Weekly' )
						)
					),
					createElement( 'div', { className: 'wpts-form-group' },
						createElement( 'label', { className: 'wpts-form-label' }, 'Sync Mode' ),
						createElement( 'select', {
							className: 'wpts-form-select',
							style: { width: '100%', maxWidth: '100%' },
							value: props.settings.scheduled_reindex_mode || 'incremental',
							onChange: function ( e ) { if ( props.update ) props.update( 'scheduled_reindex_mode', e.target.value ); }
						},
							createElement( 'option', { value: 'incremental' }, 'Incremental (Smart - only modified posts)' ),
							createElement( 'option', { value: 'full' }, 'Full Re-index (Complete rebuild)' )
						)
					)
				),

				createElement( SettingsSaveRow, props )
			)
		);
	}

	/* Tab: Backup & Migration */
	function BackupTab( props ) {
		return createElement( 'div', { className: 'wpts-app-panel' },
			createElement( 'h3', { className: 'wpts-app-panel__title' }, '📦 Staging / Production Backup & Migration' ),
			createElement( 'div', { style: { display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 32, marginTop: 20 } },
				createElement( 'div', null,
					createElement( 'h4', null, 'Export Configuration' ),
					createElement( 'p', { style: { fontSize: 13, color: '#64748b' } }, 'Download a JSON file containing all settings, weights, role restrictions, and custom synonyms.' ),
					createElement( 'form', { method: 'post', action: cfg.admin_post_url },
						createElement( 'input', { type: 'hidden', name: 'action', value: 'wpts_export_settings' } ),
						createElement( 'input', { type: 'hidden', name: '_wpnonce', value: cfg.export_nonce } ),
						createElement( 'button', { type: 'submit', className: 'wpts-btn-primary' }, '⬇ Export Settings (JSON)' )
					)
				),
				createElement( 'div', null,
					createElement( 'h4', null, 'Import Configuration' ),
					createElement( 'p', { style: { fontSize: 13, color: '#64748b' } }, 'Restore configuration from a previously exported Turbo Search JSON file.' ),
					createElement( 'form', { method: 'post', action: cfg.admin_post_url, encType: 'multipart/form-data' },
						createElement( 'input', { type: 'hidden', name: 'action', value: 'wpts_import_settings' } ),
						createElement( 'input', { type: 'hidden', name: 'wpts_import_nonce', value: cfg.import_nonce } ),
						createElement( 'input', { type: 'file', name: 'import_file', accept: '.json', required: true, style: { display: 'block', marginBottom: 12 } } ),
						createElement( 'button', { type: 'submit', className: 'button' }, '⬆ Upload & Restore' )
					)
				)
			),

			createElement( 'div', { style: { marginTop: 32, paddingTop: 20, borderTop: '1px solid #e2e8f0' } },
				createElement( 'h4', { style: { color: '#ef4444' } }, '⚠️ Reset to Factory Defaults' ),
				createElement( 'p', { style: { fontSize: 13, color: '#64748b' } }, 'Restores all search options, field weights, frontend toggles, and security settings back to their default out-of-the-box configuration.' ),
				createElement( 'button', {
					type: 'button',
					className: 'button button-link-delete',
					style: { color: '#ef4444', border: '1px solid #fca5a5', padding: '6px 14px', borderRadius: 6, fontWeight: 600 },
					onClick: function () {
						if ( ! confirm( 'Are you sure you want to reset all plugin settings to factory defaults?' ) ) return;
						api( 'reset-settings', 'POST' ).then( function ( res ) {
							if ( res && res.success ) {
								props.showToast( res.message );
								if ( props.loadConfig ) props.loadConfig();
							}
						} );
					}
				}, '🔄 Restore Factory Defaults' )
			)
		);
	}

	/* Tab: Developer Hooks */
	function HooksTab( props ) {
		var [ search, setSearch ]     = useState( '' );
		var [ category, setCategory ] = useState( 'all' );
		var [ copied, setCopied ]     = useState( null );
		var hooks = props.hooks || [];

		var categories = useMemo( function () {
			var map = { all: 'All Hooks' };
			hooks.forEach( function ( h ) {
				if ( h.category ) map[h.category] = h.category;
			} );
			return Object.keys( map ).map( function ( k ) { return { id: k, label: map[k] }; } );
		}, [ hooks ] );

		var filtered = useMemo( function () {
			var q = search.trim().toLowerCase();
			return hooks.filter( function ( h ) {
				var matchesCat = category === 'all' || h.category === category;
				if ( ! matchesCat ) return false;
				if ( ! q ) return true;
				return ( h.name && h.name.toLowerCase().indexOf( q ) !== -1 ) ||
							( h.desc && h.desc.toLowerCase().indexOf( q ) !== -1 ) ||
							( h.file && h.file.toLowerCase().indexOf( q ) !== -1 ) ||
							( h.category && h.category.toLowerCase().indexOf( q ) !== -1 );
			} );
		}, [ hooks, search, category ] );

		var copySnippet = function ( text, idx ) {
			copyTextToClipboard( text, function () {
				setCopied( idx );
				setTimeout( function () { setCopied( null ); }, 2500 );
			} );
		};

		return createElement( 'div', { className: 'wpts-app-panel' },
			createElement( 'div', { style: { display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16, flexWrap: 'wrap', gap: 10 } },
				createElement( 'div', null,
					createElement( 'h3', { className: 'wpts-app-panel__title', style: { margin: 0 } }, '🔌 Complete Developer Hooks & Filters Reference' ),
					createElement( 'p', { className: 'wpts-app-panel__desc', style: { margin: '4px 0 0' } }, 'Every verified public filter and action hook available in Turbo Search.' )
				),
				createElement( 'span', { className: 'wpts-pill wpts-pill--blue' }, filtered.length + ' Hooks Found' )
			),

			// Search Bar
			createElement( 'input', {
				type: 'search',
				className: 'wpts-form-input',
				placeholder: 'Search hooks by name, parameter, description, or file…',
				value: search,
				onChange: function ( e ) { setSearch( e.target.value ); },
				style: { marginBottom: 14, width: '100%', maxWidth: '100%' }
			} ),

			// Category Pill Filters
			createElement( 'div', { style: { display: 'flex', flexWrap: 'wrap', gap: 6, marginBottom: 20 } },
				categories.map( function ( c ) {
					return createElement( 'button', {
						key: c.id,
						type: 'button',
						className: 'button ' + ( category === c.id ? 'button-primary' : 'button-secondary' ),
						style: { borderRadius: 99, fontSize: 12, padding: '2px 12px' },
						onClick: function () { setCategory( c.id ); }
					}, c.label );
				} )
			),

			filtered.map( function ( h, idx ) {
				return createElement( 'div', { key: idx, style: { border: '1px solid #e2e8f0', borderRadius: 8, marginBottom: 14, overflow: 'hidden' } },
					createElement( 'div', { style: { padding: '12px 16px', background: '#f8fafc', display: 'flex', alignItems: 'center', justifyContent: 'space-between', flexWrap: 'wrap', gap: 10 } },
						createElement( 'div', { style: { display: 'flex', alignItems: 'center', gap: 10 } },
							createElement( 'span', { className: 'wpts-pill ' + ( h.type === 'filter' ? 'wpts-pill--green' : 'wpts-pill--blue' ) }, h.type.toUpperCase() ),
							createElement( 'code', { style: { fontWeight: 700, fontSize: 13.5, color: '#1e293b' } }, h.name ),
							h.category && createElement( 'span', { style: { fontSize: 11.5, color: '#64748b', background: '#e2e8f0', padding: '2px 8px', borderRadius: 4 } }, h.category )
						),
						createElement( 'button', {
							type: 'button',
							className: 'button button-small',
							onClick: function () { copySnippet( h.example, idx ); }
						}, copied === idx ? '✅ Copied!' : '📋 Copy Snippet' )
					),
					createElement( 'div', { style: { padding: 14 } },
						createElement( 'p', { style: { margin: '0 0 8px', fontSize: 13.5, color: '#334155' } }, h.desc ),
						h.params && createElement( 'div', { style: { fontSize: 12, color: '#64748b', marginBottom: 6 } },
							createElement( 'strong', null, 'Parameters: ' ),
							createElement( 'code', null, h.params )
						),
						createElement( 'small', { style: { color: '#94a3b8' } }, 'Fires in: ' + h.file ),
						createElement( 'details', { style: { marginTop: 10 } },
							createElement( 'summary', { style: { cursor: 'pointer', color: '#2563eb', fontWeight: 600, fontSize: 13 } }, 'View Example Code Snippet' ),
							createElement( CodeWindow, { code: h.example, lang: 'php' } )
						)
					)
				);
			} )
		);
	}

	/* Universal Clipboard Copy Helper */
	function copyTextToClipboard( text, callback ) {
		if ( ! text ) return;

		var doSuccess = function () {
			if ( typeof callback === 'function' ) {
				callback();
			}
		};

		// 1. Try modern Async Clipboard API first if in secure context
		if ( window.isSecureContext && navigator.clipboard && typeof navigator.clipboard.writeText === 'function' ) {
			navigator.clipboard.writeText( text )
				.then( function () {
					doSuccess();
				} )
				.catch( function () {
					fallbackClipboardCopy( text, doSuccess );
				} );
			return;
		}

		// 2. Fallback to textarea + document.execCommand('copy')
		fallbackClipboardCopy( text, doSuccess );
	}

	function fallbackClipboardCopy( text, callback ) {
		try {
			var textArea = document.createElement( 'textarea' );
			textArea.value = text;
			textArea.style.position = 'fixed';
			textArea.style.top = '0';
			textArea.style.left = '0';
			textArea.style.width = '2em';
			textArea.style.height = '2em';
			textArea.style.padding = '0';
			textArea.style.border = 'none';
			textArea.style.outline = 'none';
			textArea.style.boxShadow = 'none';
			textArea.style.background = 'transparent';
			textArea.setAttribute( 'readonly', '' );
			document.body.appendChild( textArea );
			textArea.focus();
			textArea.select();
			textArea.setSelectionRange( 0, textArea.value.length );

			var successful = document.execCommand( 'copy' );
			document.body.removeChild( textArea );

			if ( successful && typeof callback === 'function' ) {
				callback();
			}
		} catch ( err ) {
			// ignore
		}
	}

	/* Helper: macOS-Style Code Window & Syntax Highlight */
	function escapeHtml( str ) {
		return ( str || '' ).replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' );
	}

	function highlightCodeSyntax( code, lang ) {
		if ( ! code ) return '';
		var l = ( lang || '' ).toLowerCase();
		var keywords = new Set( [
			'function', 'return', 'add_action', 'add_filter', 'do_action', 'apply_filters',
			'const', 'var', 'let', 'class', 'public', 'static', 'private', 'protected',
			'if', 'else', 'foreach', 'as', 'new', 'true', 'false', 'null', 'array',
			'bool', 'int', 'string', 'float', 'void', 'import', 'export', 'from', 'wp',
			'wp_schedule_event', 'wp_next_scheduled', 'wp_remote_post', 'wp_json_encode',
			'get_option', 'update_option', 'get_post', 'get_post_meta', 'sanitize_text_field'
		] );

		var lines = code.split( '\n' );
		var highlightedLines = [];

		for ( var i = 0; i < lines.length; i++ ) {
			var line = lines[i];
			var lineOut = '';

			// Check for comment // or #
			var commentIdx = -1;
			var slashSlash = line.indexOf( '//' );
			var hash = line.indexOf( '#' );
			if ( slashSlash !== -1 && ( hash === -1 || slashSlash < hash ) ) commentIdx = slashSlash;
			else if ( hash !== -1 && l !== 'php' ) commentIdx = hash;

			var codePart = commentIdx !== -1 ? line.substring( 0, commentIdx ) : line;
			var commentPart = commentIdx !== -1 ? line.substring( commentIdx ) : '';

			var tokenRegex = /('([^'\\]|\\.)*'|"([^"\\]|\\.)*"|\$[a-zA-Z0-9_]+|[a-zA-Z0-9_]+|[^\s\w])/g;
			var match;
			var lastIndex = 0;

			while ( ( match = tokenRegex.exec( codePart ) ) !== null ) {
				if ( match.index > lastIndex ) {
					lineOut += escapeHtml( codePart.substring( lastIndex, match.index ) );
				}
				var token = match[0];
				if ( ( token.startsWith( "'" ) && token.endsWith( "'" ) ) || ( token.startsWith( '"' ) && token.endsWith( '"' ) ) ) {
					lineOut += '<span class="wpts-syn-string">' + escapeHtml( token ) + '</span>';
				} else if ( token.startsWith( '$' ) ) {
					lineOut += '<span class="wpts-syn-var">' + escapeHtml( token ) + '</span>';
				} else if ( keywords.has( token ) ) {
					lineOut += '<span class="wpts-syn-kw">' + escapeHtml( token ) + '</span>';
				} else if ( ( l === 'cli' || l === 'bash' || l === 'sh' || l === 'terminal' ) && ( token === 'wp' || token === 'curl' || token === 'npm' ) && match.index === 0 ) {
					lineOut += '<span class="wpts-syn-prompt">' + escapeHtml( token ) + '</span>';
				} else {
					lineOut += escapeHtml( token );
				}
				lastIndex = tokenRegex.lastIndex;
			}

			if ( lastIndex < codePart.length ) {
				lineOut += escapeHtml( codePart.substring( lastIndex ) );
			}

			if ( commentPart ) {
				lineOut += '<span class="wpts-syn-comment">' + escapeHtml( commentPart ) + '</span>';
			}

			highlightedLines.push( lineOut );
		}

		return highlightedLines.join( '\n' );
	}

	function CodeWindow( props ) {
		var [ copied, setCopied ] = useState( false );
		var code = props.code || '';
		var lang = props.lang || 'code';
		var highlighted = useMemo( function () {
			return highlightCodeSyntax( code, lang );
		}, [ code, lang ] );

		var onCopy = function () {
			copyTextToClipboard( code, function () {
				setCopied( true );
				setTimeout( function () { setCopied( false ); }, 2500 );
			} );
		};

		var title = lang.toUpperCase();
		if ( lang === 'cli' || lang === 'bash' || lang === 'sh' ) title = 'TERMINAL / WP-CLI';
		else if ( lang === 'php' ) title = 'PHP (WORDPRESS)';
		else if ( lang === 'json' ) title = 'JSON RESPONSE';
		else if ( lang === 'text' || lang === 'shortcode' ) title = 'SHORTCODE';

		return createElement( 'div', { className: 'wpts-code-window' },
			createElement( 'div', { className: 'wpts-code-window__header' },
				createElement( 'div', { className: 'wpts-code-window__traffic-lights' },
					createElement( 'span', { className: 'wpts-code-window__dot wpts-code-window__dot--red' } ),
					createElement( 'span', { className: 'wpts-code-window__dot wpts-code-window__dot--yellow' } ),
					createElement( 'span', { className: 'wpts-code-window__dot wpts-code-window__dot--green' } )
				),
				createElement( 'span', { className: 'wpts-code-window__title' }, title ),
				createElement( 'button', {
					type: 'button',
					className: 'wpts-code-window__copy',
					onClick: onCopy
				}, copied ? '✅ Copied!' : '📋 Copy Code' )
			),
			createElement( 'pre', {
				className: 'wpts-code-window__pre',
				dangerouslySetInnerHTML: { __html: highlighted }
			} )
		);
	}

	/* Tab: Documentation Reader */
	var DOC_LINKS_MAP = {
		'readme.md': 'readme',
		'01-installation-and-setup.md': 'setup',
		'02-search-features-and-shortcodes.md': 'features',
		'03-indexing-and-caching.md': 'indexing',
		'04-ai-vector-search.md': 'vector',
		'05-developer-hooks-api.md': 'hooks',
		'06-rest-api-reference.md': 'rest',
		'07-multisite-multilingual.md': 'multisite',
		'08-cli-commands.md': 'cli',
		'09-complete-settings-reference.md': 'settings'
	};

	function slugifyDocText( text ) {
		return ( text || '' )
			.toLowerCase()
			.replace( /<[^>]+>/g, '' ) // strip any HTML tags
			.replace( /[^a-z0-9]+/g, '-' )
			.replace( /^-+|-+$/g, '' );
	}

	function scrollToDocAnchor( anchorId ) {
		if ( ! anchorId ) return;
		var cleanId = anchorId.replace( /^#/, '' ).trim().toLowerCase();
		if ( ! cleanId ) return;

		var targetEl = document.getElementById( cleanId );

		// Fallback 1: Query selector by ID or name attribute
		if ( ! targetEl ) {
			try {
				targetEl = document.querySelector( '[id="' + cleanId + '"], [name="' + cleanId + '"]' );
			} catch ( e ) {}
		}

		// Fallback 2: Check all headings for matching ID or partial ID
		if ( ! targetEl ) {
			var headings = document.querySelectorAll( '.wpts-doc-h1, .wpts-doc-h2, .wpts-doc-h3, .wpts-doc-h4, h1, h2, h3, h4, h5, h6' );
			for ( var h = 0; h < headings.length; h++ ) {
				var el = headings[h];
				var elId = ( el.id || '' ).toLowerCase();
				if ( elId === cleanId || elId.indexOf( cleanId ) !== -1 || cleanId.indexOf( elId ) !== -1 ) {
					targetEl = el;
					break;
				}
			}
		}

		// Fallback 3: Check headings by text content (matching slugified innerText)
		if ( ! targetEl ) {
			var allHeadings = document.querySelectorAll( '.wpts-doc-h1, .wpts-doc-h2, .wpts-doc-h3, .wpts-doc-h4, h1, h2, h3, h4, h5, h6' );
			for ( var k = 0; k < allHeadings.length; k++ ) {
				var headingEl = allHeadings[k];
				var headingSlug = slugifyDocText( headingEl.textContent || headingEl.innerText || '' );
				if ( headingSlug === cleanId || headingSlug.indexOf( cleanId ) !== -1 || cleanId.indexOf( headingSlug ) !== -1 ) {
					targetEl = headingEl;
					break;
				}
			}
		}

		if ( targetEl ) {
			var rect = targetEl.getBoundingClientRect();
			var scrollTop = window.pageYOffset || document.documentElement.scrollTop;
			var targetY = rect.top + scrollTop - 80; // 80px offset for WordPress admin bar and sticky navigation
			window.scrollTo( { top: Math.max( 0, targetY ), behavior: 'smooth' } );

			targetEl.classList.add( 'wpts-heading-anchor-target' );
			setTimeout( function () {
				targetEl.classList.remove( 'wpts-heading-anchor-target' );
			}, 2000 );
		}
	}

	function parseInlineTokens( text, onNavigate, keyPrefix ) {
		if ( ! text ) return null;
		var p = keyPrefix || 'tok';
		var regex = /(\*\*\[[^\n\]]+\]\([^\n\)]+\)\*\*|\[[^\n\]]+\]\([^\n\)]+\)|\*\*[^\n\*]+\*\*|\*[^\n\*]+\*|\`[^\n\`]+\`|<mark[^>]*>.*?<\/mark>|<kbd>.*?<\/kbd>|\$\$[^\n\$]+\$\$|\$[^\n\$]+\$)/g;
		var parts = text.split( regex );
		var nodes = [];

		for ( var i = 0; i < parts.length; i++ ) {
			var part = parts[i];
			if ( ! part ) continue;

			// Bold link: **[text](url)**
			if ( part.indexOf( '**[' ) === 0 && part.lastIndexOf( ')**' ) === part.length - 3 && part.indexOf( '](' ) !== -1 ) {
				var inner = part.substring( 2, part.length - 2 );
				var closeBracket = inner.indexOf( '](' );
				var linkText = inner.substring( 1, closeBracket );
				var linkUrl = inner.substring( closeBracket + 2, inner.length - 1 );
				var targetDoc = DOC_LINKS_MAP[ linkUrl.toLowerCase().replace( /^\.\//, '' ) ];
				if ( targetDoc && onNavigate ) {
					( function ( docId ) {
						nodes.push(
							createElement( 'strong', { key: p + '-bl-' + i },
								createElement( 'a', {
									href: '#',
									className: 'wpts-doc-link',
									onClick: function ( e ) { e.preventDefault(); onNavigate( docId ); }
								}, linkText )
							)
						);
					} )( targetDoc );
				} else if ( linkUrl.indexOf( '#' ) === 0 ) {
					( function ( anchorId ) {
						nodes.push(
							createElement( 'strong', { key: p + '-bl-' + i },
								createElement( 'a', {
									href: linkUrl,
									className: 'wpts-doc-link',
									onClick: function ( e ) {
										e.preventDefault();
										scrollToDocAnchor( anchorId );
									}
								}, linkText )
							)
						);
					} )( linkUrl );
				} else {
					nodes.push(
						createElement( 'strong', { key: p + '-bl-' + i },
							createElement( 'a', { href: linkUrl, target: '_blank', rel: 'noopener noreferrer', className: 'wpts-doc-link' }, linkText )
						)
					);
				}
				continue;
			}
			// Link: [text](url)
			if ( part.indexOf( '[' ) === 0 && part.lastIndexOf( ')' ) === part.length - 1 && part.indexOf( '](' ) !== -1 ) {
				var closeBracket = part.indexOf( '](' );
				var linkText = part.substring( 1, closeBracket );
				var linkUrl = part.substring( closeBracket + 2, part.length - 1 );
				var targetDoc = DOC_LINKS_MAP[ linkUrl.toLowerCase().replace( /^\.\//, '' ) ];
				if ( targetDoc && onNavigate ) {
					( function ( docId ) {
						nodes.push(
							createElement( 'a', {
								key: p + '-l-' + i,
								href: '#',
								className: 'wpts-doc-link',
								onClick: function ( e ) { e.preventDefault(); onNavigate( docId ); }
							}, linkText )
						);
					} )( targetDoc );
				} else if ( linkUrl.indexOf( '#' ) === 0 ) {
					( function ( anchorId ) {
						nodes.push(
							createElement( 'a', {
								key: p + '-l-' + i,
								href: linkUrl,
								className: 'wpts-doc-link',
								onClick: function ( e ) {
									e.preventDefault();
									scrollToDocAnchor( anchorId );
								}
							}, linkText )
						);
					} )( linkUrl );
				} else {
					nodes.push(
						createElement( 'a', {
							key: p + '-l-' + i,
							href: linkUrl,
							target: '_blank',
							rel: 'noopener noreferrer',
							className: 'wpts-doc-link'
						}, linkText )
					);
				}
				continue;
			}

			// Bold text: **text**
			if ( part.indexOf( '**' ) === 0 && part.lastIndexOf( '**' ) === part.length - 2 && part.length >= 4 ) {
				var boldContent = part.substring( 2, part.length - 2 );
				nodes.push(
					createElement( 'strong', { key: p + '-b-' + i, style: { fontWeight: 700, color: '#0f172a' } },
						parseInlineTokens( boldContent, onNavigate, p + '-bi-' + i )
					)
				);
				continue;
			}

			if ( part.indexOf( '*' ) === 0 && part.lastIndexOf( '*' ) === part.length - 1 && part.length >= 2 ) {
				var italicContent = part.substring( 1, part.length - 1 );
				nodes.push(
					createElement( 'em', { key: p + '-it-' + i, style: { fontStyle: 'italic', color: '#64748b' } },
						parseInlineTokens( italicContent, onNavigate, p + '-iti-' + i )
					)
				);
				continue;
			}

			// Inline Code: `code`
			if ( part.indexOf( '`' ) === 0 && part.lastIndexOf( '`' ) === part.length - 1 && part.length >= 2 ) {
				var codeContent = part.substring( 1, part.length - 1 );
				nodes.push(
					createElement( 'code', { key: p + '-c-' + i, className: 'wpts-doc-inline-code' }, codeContent )
				);
				continue;
			}

			// Mark tag: <mark...>text</mark>
			if ( part.indexOf( '<mark' ) === 0 && part.indexOf( '</mark>' ) !== -1 ) {
				var markText = part.replace( /<mark[^>]*>/, '' ).replace( /<\/mark>/, '' );
				nodes.push(
					createElement( 'mark', { key: p + '-m-' + i, className: 'wpts-doc-mark' }, markText )
				);
				continue;
			}

			// Kbd tag: <kbd>text</kbd>
			if ( part.indexOf( '<kbd>' ) === 0 && part.indexOf( '</kbd>' ) !== -1 ) {
				var kbdText = part.replace( '<kbd>', '' ).replace( '</kbd>', '' );
				nodes.push(
					createElement( 'kbd', { key: p + '-k-' + i, className: 'wpts-doc-kbd' }, kbdText )
				);
				continue;
			}

			// Math inline: $$...$$ or $...$
			if ( ( part.indexOf( '$$' ) === 0 && part.lastIndexOf( '$$' ) === part.length - 2 && part.length >= 4 ) ||
					( part.indexOf( '$' ) === 0 && part.lastIndexOf( '$' ) === part.length - 1 && part.length >= 2 ) ) {
				var mathText = part.replace( /^\$\$?|\$\$?$/g, '' );
				nodes.push(
					createElement( 'code', { key: p + '-mt-' + i, className: 'wpts-doc-math' }, mathText )
				);
				continue;
			}

			// Plain text
			nodes.push( part );
		}

		return nodes;
	}

	function DocsTab( props ) {
		var [ docs, setDocs ] = useState( [] );
		var [ activeId, setActiveId ] = useState( 'readme' );
		var [ search, setSearch ] = useState( '' );
		var [ loading, setLoading ] = useState( true );

		useEffect( function () {
			api( 'docs' ).then( function ( res ) {
				if ( res && res.docs ) {
					setDocs( res.docs );
				}
				setLoading( false );
			} ).catch( function () {
				setLoading( false );
			} );
		}, [] );

		var currentDoc = docs.find( function ( d ) { return d.id === activeId; } ) || docs[0] || {};
		var currentIndex = docs.findIndex( function ( d ) { return d.id === ( currentDoc.id || activeId ); } );

		var navigateToDoc = function ( docId ) {
			setActiveId( docId );
			window.scrollTo( { top: 0, behavior: 'smooth' } );
		};

		var slugify = function ( text ) {
			return ( text || '' ).toLowerCase().replace( /[^a-z0-9]+/g, '-' ).replace( /^-+|-+$/g, '' );
		};

		var renderMarkdown = function ( content ) {
			if ( ! content ) return null;
			var lines = content.split( '\n' );
			var elements = [];
			var inCodeBlock = false;
			var codeBuffer = [];
			var codeLang = '';
			var inTable = false;
			var tableRows = [];
			var blockIdx = 0;

			for ( var i = 0; i < lines.length; i++ ) {
				var line = lines[i];

				// Code block toggle (supports both root and indented ```)
				if ( line.trim().indexOf( '```' ) === 0 ) {
					if ( inCodeBlock ) {
						// Remove common leading indentation from code lines
						var minIndent = Infinity;
						for ( var b = 0; b < codeBuffer.length; b++ ) {
							var cLine = codeBuffer[b];
							if ( cLine.trim().length > 0 ) {
								var spMatch = cLine.match( /^\s*/ );
								var spCount = spMatch ? spMatch[0].length : 0;
								if ( spCount < minIndent ) minIndent = spCount;
							}
						}
						if ( minIndent === Infinity ) minIndent = 0;
						var cleanCode = codeBuffer.map( function ( cLine ) {
							return cLine.length >= minIndent ? cLine.substring( minIndent ) : cLine;
						} ).join( '\n' );

						( function ( codeText, lName, bId ) {
							elements.push(
								createElement( CodeWindow, { key: 'code-' + bId, code: codeText, lang: lName } )
							);
						} )( cleanCode, codeLang, blockIdx++ );
						inCodeBlock = false;
						codeBuffer = [];
						codeLang = '';
					} else {
						inCodeBlock = true;
						codeLang = line.trim().replace( '```', '' ).trim();
						codeBuffer = [];
					}
					continue;
				}

				if ( inCodeBlock ) {
					codeBuffer.push( line );
					continue;
				}

				// Table row parsing
				if ( line.trim().indexOf( '|' ) === 0 && line.trim().lastIndexOf( '|' ) === line.trim().length - 1 ) {
					var isTableSep = /^\|[\s:\-\|]+\|$/.test( line.trim() );
					if ( isTableSep ) {
						continue;
					}
					var cells = line.split( '|' ).slice( 1, -1 ).map( function ( c ) { return c.trim(); } );
					tableRows.push( cells );
					inTable = true;
					continue;
				} else if ( inTable ) {
					( function ( rows, tId ) {
						var header = rows[0] || [];
						var bodyRows = rows.slice( 1 );
						elements.push(
							createElement( 'div', { key: 'table-' + tId, style: { overflowX: 'auto', margin: '16px 0' } },
								createElement( 'table', { className: 'widefat striped wpts-doc-table' },
									createElement( 'thead', null,
										createElement( 'tr', null, header.map( function ( h, idx ) {
											return createElement( 'th', { key: idx }, parseInlineTokens( h, navigateToDoc, 'th-' + tId + '-' + idx ) );
										} ) )
									),
									createElement( 'tbody', null,
										bodyRows.map( function ( r, rIdx ) {
											return createElement( 'tr', { key: rIdx }, r.map( function ( c, cIdx ) {
												return createElement( 'td', { key: cIdx }, parseInlineTokens( c, navigateToDoc, 'td-' + tId + '-' + rIdx + '-' + cIdx ) );
											} ) );
										} )
									)
								)
							)
						);
					} )( tableRows, blockIdx++ );
					inTable = false;
					tableRows = [];
				}

				// Standalone Math equation block: $$...$$
				if ( line.trim().indexOf( '$$' ) === 0 && line.trim().lastIndexOf( '$$' ) === line.trim().length - 2 && line.trim().length > 4 ) {
					var mathCode = line.trim().substring( 2, line.trim().length - 2 );
					elements.push(
						createElement( 'div', { key: 'mathb-' + i, className: 'wpts-doc-math-block' }, mathCode )
					);
					continue;
				}

				// Headings & dividers with anchor slugs
				if ( line.indexOf( '# ' ) === 0 ) {
					var h1Text = line.substring( 2 );
					elements.push( createElement( 'h2', { key: 'h1-' + i, id: slugifyDocText( h1Text ), className: 'wpts-doc-h1' }, parseInlineTokens( h1Text, navigateToDoc, 'h1-' + i ) ) );
					continue;
				} else if ( line.indexOf( '## ' ) === 0 ) {
					var h2Text = line.substring( 3 );
					elements.push( createElement( 'h3', { key: 'h2-' + i, id: slugifyDocText( h2Text ), className: 'wpts-doc-h2' }, parseInlineTokens( h2Text, navigateToDoc, 'h2-' + i ) ) );
					continue;
				} else if ( line.indexOf( '### ' ) === 0 ) {
					var h3Text = line.substring( 4 );
					elements.push( createElement( 'h4', { key: 'h3-' + i, id: slugifyDocText( h3Text ), className: 'wpts-doc-h3' }, parseInlineTokens( h3Text, navigateToDoc, 'h3-' + i ) ) );
					continue;
				} else if ( line.indexOf( '#### ' ) === 0 ) {
					var h4Text = line.substring( 5 );
					elements.push( createElement( 'h5', { key: 'h4-' + i, id: slugifyDocText( h4Text ), className: 'wpts-doc-h4' }, parseInlineTokens( h4Text, navigateToDoc, 'h4-' + i ) ) );
					continue;
				} else if ( line.indexOf( '---' ) === 0 ) {
					elements.push( createElement( 'hr', { key: 'hr-' + i, style: { border: 'none', borderTop: '1px solid #e2e8f0', margin: '24px 0' } } ) );
					continue;
				} else if ( line.indexOf( '> ' ) === 0 ) {
					elements.push( createElement( 'blockquote', { key: 'bq-' + i, className: 'wpts-doc-blockquote' }, parseInlineTokens( line.substring( 2 ), navigateToDoc, 'bq-' + i ) ) );
					continue;
				}

				// Numbered Step Cards: e.g. "1. Step description..."
				var stepMatch = line.match( /^(\d+)\.\s+(.*)/ );
				if ( stepMatch ) {
					var stepNum = stepMatch[1];
					var stepBody = stepMatch[2];
					elements.push(
						createElement( 'div', { key: 'step-' + i, className: 'wpts-doc-step' },
							createElement( 'div', { className: 'wpts-doc-step__num' }, stepNum ),
							createElement( 'div', { className: 'wpts-doc-step__content' }, parseInlineTokens( stepBody, navigateToDoc, 'step-' + i ) )
						)
					);
					continue;
				}

				// Bullet Items: e.g. "* " or "- " with clean custom pill bullet
				var bulletMatch = line.match( /^(\s*)([-*])\s+(.*)/ );
				if ( bulletMatch ) {
					var indent = bulletMatch[1].length;
					var bulletContent = bulletMatch[3];
					elements.push(
						createElement( 'div', {
							key: 'li-' + i,
							className: 'wpts-doc-li' + ( indent > 0 ? ' wpts-doc-li--nested' : '' )
						},
							createElement( 'span', { className: 'wpts-doc-li__bullet' } ),
							createElement( 'div', { className: 'wpts-doc-li__content' }, parseInlineTokens( bulletContent, navigateToDoc, 'li-' + i ) )
						)
					);
					continue;
				}

				if ( line.trim().length > 0 ) {
					elements.push( createElement( 'p', { key: 'p-' + i, className: 'wpts-doc-p' }, parseInlineTokens( line, navigateToDoc, 'p-' + i ) ) );
				}
			}

			if ( inTable && tableRows.length > 0 ) {
				var header = tableRows[0];
				var bodyRows = tableRows.slice( 1 );
				elements.push(
					createElement( 'div', { key: 'table-end', style: { overflowX: 'auto', margin: '16px 0' } },
						createElement( 'table', { className: 'widefat striped wpts-doc-table' },
							createElement( 'thead', null,
								createElement( 'tr', null, header.map( function ( h, idx ) {
									return createElement( 'th', { key: idx }, parseInlineTokens( h, navigateToDoc, 'the-' + idx ) );
								} ) )
							),
							createElement( 'tbody', null,
								bodyRows.map( function ( r, rIdx ) {
									return createElement( 'tr', { key: rIdx }, r.map( function ( c, cIdx ) {
										return createElement( 'td', { key: cIdx }, parseInlineTokens( c, navigateToDoc, 'tde-' + rIdx + '-' + cIdx ) );
									} ) );
								} )
							)
						)
					)
				);
			}

			return elements;
		};

		return createElement( 'div', { className: 'wpts-settings-layout', style: { marginTop: 10 } },
			// Left Sidebar: Chapter Navigation
			createElement( 'aside', { className: 'wpts-settings-sidebar', style: { minWidth: 260 } },
				createElement( 'div', { style: { padding: '0 0 10px' } },
					createElement( 'input', {
						type: 'search',
						className: 'wpts-form-input',
						placeholder: 'Search documentation…',
						value: search,
						onChange: function ( e ) { setSearch( e.target.value ); },
						style: { width: '100%', boxSizing: 'border-box' }
					} )
				),
				createElement( 'nav', { className: 'wpts-app-nav' },
					docs.filter( function ( d ) {
						if ( ! search ) return true;
						var q = search.toLowerCase();
						return d.title.toLowerCase().indexOf( q ) !== -1 || ( d.content && d.content.toLowerCase().indexOf( q ) !== -1 );
					} ).map( function ( d ) {
						return createElement( 'button', {
							key: d.id,
							type: 'button',
							className: 'wpts-nav-btn ' + ( activeId === d.id ? 'is-active' : '' ),
							onClick: function () { navigateToDoc( d.id ); }
						}, d.title );
					} )
				)
			),

			// Right Main View: Documentation Reader Panel
			createElement( 'div', { className: 'wpts-settings-content' },
				createElement( 'div', { className: 'wpts-app-panel', style: { minHeight: 600, padding: 28 } },
					loading ? createElement( 'div', { style: { padding: 40, textAlign: 'center', color: '#64748b' } }, 'Loading documentation…' ) :
					createElement( 'div', null,
						( currentDoc && currentDoc.content && currentDoc.content.trim().length > 0 ) ?
							renderMarkdown( currentDoc.content ) :
							createElement( 'div', { className: 'wpts-doc-empty-state', style: { padding: '40px 24px', textAlign: 'center', background: '#f8fafc', border: '1px dashed #cbd5e1', borderRadius: '8px', margin: '20px 0' } },
								createElement( 'div', { style: { fontSize: '36px', marginBottom: '12px' } }, '📖' ),
								createElement( 'h3', { style: { margin: '0 0 8px', color: '#1e293b', fontSize: '18px', fontWeight: 600 } }, 'Documentation content unavailable' ),
								createElement( 'p', { style: { color: '#64748b', maxWidth: '520px', margin: '0 auto', lineHeight: 1.5 } },
									'The file "' + ( currentDoc && currentDoc.filename ? currentDoc.filename : 'documentation file' ) + '" could not be loaded from your server. Please verify that the "docs/" folder exists in your plugin directory.'
								)
							),

						// Next / Previous Navigation Footer
						createElement( 'div', { style: { display: 'flex', justifyContent: 'space-between', marginTop: 40, paddingTop: 20, borderTop: '1px solid #e2e8f0' } },
							currentIndex > 0 ? createElement( 'button', {
								type: 'button',
								className: 'button',
								onClick: function () { navigateToDoc( docs[currentIndex - 1].id ); }
							}, '← ' + docs[currentIndex - 1].title ) : createElement( 'span' ),

							currentIndex < docs.length - 1 ? createElement( 'button', {
								type: 'button',
								className: 'button button-primary',
								onClick: function () { navigateToDoc( docs[currentIndex + 1].id ); }
							}, docs[currentIndex + 1].title + ' →' ) : createElement( 'span' )
						)
					)
				)
			)
		);
	}

	// Mount React Root (Supports React 18 createRoot & React 17 render)
	var mounted = false;
	function mountReactApp() {
		if ( mounted ) return;
		var rootEl = document.getElementById( 'wpts-admin-root' );
		if ( ! rootEl ) return;
		mounted = true;

		if ( wpEl.createRoot ) {
			wpEl.createRoot( rootEl ).render( createElement( AdminApp ) );
		} else if ( wpEl.render ) {
			wpEl.render( createElement( AdminApp ), rootEl );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', mountReactApp );
	} else {
		mountReactApp();
	}
} )();
