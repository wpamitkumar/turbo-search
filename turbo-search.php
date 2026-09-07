<?php
/**
 * Plugin Name:       Turbo Search
 * Plugin URI:        https://github.com/wpamitkumar/turbo-search
 * Description:       Ultra-fast live search engine for WordPress & WooCommerce with MySQL FULLTEXT, Typesense, Elasticsearch, PDF search, AI vector search, and analytics.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Tested up to:      6.7
 * Requires PHP:      7.4
 * Author:            Amitkumar Dudhat
 * Author URI:        https://profiles.wordpress.org/wpamitkumar
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       turbo-search
 * Domain Path:       /languages
 * Network:           true
 */

defined( 'ABSPATH' ) || exit;


// Constants
define( 'WPTS_VERSION',  '1.0.0' );
define( 'WPTS_FILE',     __FILE__ );
define( 'WPTS_DIR',      plugin_dir_path( __FILE__ ) );
define( 'WPTS_URL',      plugin_dir_url( __FILE__ ) );
define( 'WPTS_BASENAME', plugin_basename( __FILE__ ) );
define( 'WPTS_MIN_PHP',  '7.4' );
define( 'WPTS_MIN_WP',   '6.0' );

// PHP version gate
if ( version_compare( PHP_VERSION, WPTS_MIN_PHP, '<' ) ) {
	add_action( 'admin_notices', function () {
		echo '<div class="notice notice-error"><p>' .
			 sprintf(
				 esc_html__( 'Turbo Search requires PHP %s or higher.', 'turbo-search' ),
				 WPTS_MIN_PHP
			 ) .
			 '</p></div>';
	} );
	return;
}

// Autoloader
spl_autoload_register( function ( string $class ): void {
	$prefix = 'WPTS\\';
	if ( strncmp( $prefix, $class, strlen( $prefix ) ) !== 0 ) return;

	$map = [
		'WPTS\\Core'                       => 'includes/class-core.php',
		'WPTS\\Block'                      => 'includes/class-block.php',
		'WPTS\\Installer'                  => 'includes/class-installer.php',
		'WPTS\\Tracker'                    => 'includes/class-tracker.php',
		'WPTS\\Universal\\Utils'           => 'includes/universal/class-utils.php',
		'WPTS\\Search\\EngineInterface'    => 'includes/search/interface-engine.php',
		'WPTS\\Search\\QueryParser'        => 'includes/search/class-query-parser.php',
		'WPTS\\Search\\Synonyms'           => 'includes/search/class-synonyms.php',
		'WPTS\\Search\\Spelling'           => 'includes/search/class-spelling.php',
		'WPTS\\Search\\Highlighter'        => 'includes/search/class-highlighter.php',
		'WPTS\\Search\\Facets'             => 'includes/search/class-facets.php',
		'WPTS\\Search\\ContentExtractor'   => 'includes/search/class-content-extractor.php',
		'WPTS\\Search\\UserHistory'        => 'includes/search/class-user-history.php',
		'WPTS\\Search\\VectorSearch'       => 'includes/search/class-vector-search.php',
		'WPTS\\Search\\WPCoreFallback'     => 'includes/search/class-wp-core-fallback.php',
		'WPTS\\CPT\\Manager'               => 'includes/cpt/class-cpt-manager.php',
		'WPTS\\Admin\\Settings'            => 'includes/admin/class-settings.php',
		'WPTS\\Admin\\Page'                => 'includes/admin/class-admin-page.php',
		'WPTS\\Admin\\SettingsExporter'    => 'includes/admin/class-settings-exporter.php',
		'WPTS\\Admin\\SiteHealth'          => 'includes/admin/class-site-health.php',
		'WPTS\\Cache\\Typesense'           => 'includes/cache/class-typesense.php',
		'WPTS\\Cache\\Elasticsearch'       => 'includes/cache/class-elasticsearch.php',
		'WPTS\\Cache\\MySQL'               => 'includes/cache/class-mysql.php',
		'WPTS\\Cache\\CacheManager'        => 'includes/cache/class-cache-manager.php',
		'WPTS\\Analytics\\AnalyticsReport' => 'includes/analytics/class-analytics-report.php',
		'WPTS\\Analytics\\Trending'        => 'includes/analytics/class-trending.php',
		'WPTS\\Analytics\\ABTesting'       => 'includes/analytics/class-ab-testing.php',
		'WPTS\\Analytics\\Export'          => 'includes/analytics/class-export.php',
		'WPTS\\Indexer\\ScheduledSync'     => 'includes/indexer/class-scheduled-sync.php',
		'WPTS\\Indexer\\IncrementalSync'   => 'includes/indexer/class-incremental-sync.php',
		'WPTS\\Security\\RoleRestrictions' => 'includes/security/class-role-restrictions.php',
		'WPTS\\Security\\BotProtection'    => 'includes/security/class-bot-protection.php',
		'WPTS\\Security\\GDPR'             => 'includes/security/class-gdpr.php',
		'WPTS\\API\\RestSearch'            => 'includes/api/class-rest-search.php',
		'WPTS\\API\\RestAdmin'             => 'includes/api/class-rest-admin.php',
		'WPTS\\I18n\\Loader'               => 'includes/i18n/class-i18n-loader.php',
		'WPTS\\Multisite\\Network'         => 'includes/multisite/class-network.php',
		'WPTS\\Hooks\\Manager'             => 'includes/hooks/class-hooks-manager.php',
		'WPTS\\CLI\\Commands'              => 'includes/cli/class-cli-commands.php',
	];

	if ( isset( $map[ $class ] ) && file_exists( WPTS_DIR . $map[ $class ] ) ) {
		require_once WPTS_DIR . $map[ $class ];
	}
} );

// Ensure foundational interfaces & utils are loaded
require_once WPTS_DIR . 'includes/search/interface-engine.php';
require_once WPTS_DIR . 'includes/universal/class-utils.php';

// Step 1: Register admin_post_* handlers EARLY on init (priority 1)
add_action( 'init', 'wpts_register_post_handlers', 1 );

function wpts_register_post_handlers(): void {
	if ( ! isset( $_REQUEST['action'] ) ) return;
	$action = sanitize_key( wp_unslash( $_REQUEST['action'] ?? '' ) );
	if ( strncmp( 'wpts_', $action, 5 ) !== 0 ) return;

	// Re-index all posts
	add_action( 'admin_post_wpts_reindex', function (): void {
		check_admin_referer( 'wpts_reindex' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'turbo-search' ) );
		}
		WPTS\Core::instance()->get_engine()->reindex_all();
		wp_safe_redirect( admin_url( 'admin.php?page=wpts-index&reindexed=1' ) );
		exit;
	} );

	// Flush cache
	add_action( 'admin_post_wpts_flush_cache', function (): void {
		check_admin_referer( 'wpts_flush_cache' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'turbo-search' ) );
		}
		WPTS\Core::instance()->get_engine()->flush_all();
		wp_safe_redirect( admin_url( 'admin.php?page=wpts-cache&tab=flush&flushed=1' ) );
		exit;
	} );

	// Save settings
	add_action( 'admin_post_wpts_save_settings', function (): void {
		check_admin_referer( 'wpts_save_settings', 'wpts_nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'turbo-search' ) );
		}
		WPTS\Admin\Settings::save( $_POST );
		$tab = sanitize_key( wp_unslash( $_POST['_tab'] ?? 'general' ) );
		wp_safe_redirect( admin_url( "admin.php?page=wpts-settings&tab={$tab}&saved=1" ) );
		exit;
	} );

	// Export settings JSON
	add_action( 'admin_post_wpts_export_settings', function (): void {
		check_admin_referer( 'wpts_export_settings' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'turbo-search' ) );
		}
		WPTS\Admin\SettingsExporter::export();
	} );

	// Import settings JSON
	add_action( 'admin_post_wpts_import_settings', function (): void {
		check_admin_referer( 'wpts_import_settings', 'wpts_import_nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'turbo-search' ) );
		}
		if ( ! empty( $_FILES['import_file'] ) ) {
			$result = WPTS\Admin\SettingsExporter::import( $_FILES['import_file'] );
			$status = $result['success'] ? 'imported=1' : 'import_error=' . urlencode( $result['message'] );
			wp_safe_redirect( admin_url( "admin.php?page=wpts-settings&tab=backup&{$status}" ) );
			exit;
		}
	} );

	// Save cache settings
	add_action( 'admin_post_wpts_save_cache_settings', function (): void {
		check_admin_referer( 'wpts_save_cache_settings', 'wpts_cache_nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'turbo-search' ) );
		}
		WPTS\Admin\Settings::save( $_POST );
		$tab = sanitize_key( wp_unslash( $_POST['_tab'] ?? 'general' ) );
		wp_safe_redirect( admin_url( "admin.php?page=wpts-cache&tab={$tab}&saved=1" ) );
		exit;
	} );

	// Reset stats
	add_action( 'admin_post_wpts_reset_stats', function (): void {
		check_admin_referer( 'wpts_reset_stats' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'turbo-search' ) );
		}
		delete_option( 'wpts_cache_stats_data' );
		delete_option( 'wpts_cache_stats_since' );
		wp_safe_redirect( admin_url( 'admin.php?page=wpts-cache&tab=flush&reset=1' ) );
		exit;
	} );

	// Install / recreate tables manually
	add_action( 'admin_post_wpts_install_tables', function (): void {
		check_admin_referer( 'wpts_install_tables' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'turbo-search' ) );
		}
		WPTS\Installer::run_for_site();
		wp_safe_redirect( admin_url( 'admin.php?page=wpts-index&tables_created=1' ) );
		exit;
	} );

	// Prune tracking
	add_action( 'admin_post_wpts_prune_tracking', function (): void {
		check_admin_referer( 'wpts_prune_tracking' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'turbo-search' ) );
		}
		$days = absint( WPTS\Admin\Settings::get( 'tracking_retention_days', 90 ) );
		WPTS\Tracker::instance()->prune( $days );
		wp_safe_redirect( admin_url( 'admin.php?page=wpts-tracking&tab=settings&pruned=1' ) );
		exit;
	} );

	// Reset tracking
	add_action( 'admin_post_wpts_reset_tracking', function (): void {
		check_admin_referer( 'wpts_reset_tracking' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'turbo-search' ) );
		}
		WPTS\Tracker::instance()->reset_counters();
		wp_safe_redirect( admin_url( 'admin.php?page=wpts-tracking&tab=settings&reset=1' ) );
		exit;
	} );

	// CSV exports
	add_action( 'admin_post_wpts_export_searches', function (): void {
		$nonce = sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ?? '' ) );
		if ( ! wp_verify_nonce( $nonce, 'wpts_export_searches' ) && ! wp_verify_nonce( $nonce, 'wpts_export_settings' ) ) {
			wp_nonce_ays( 'wpts_export_searches' );
			exit;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'turbo-search' ) );
		}
		$days = absint( wp_unslash( $_GET['days'] ?? 30 ) );
		$mode = sanitize_key( wp_unslash( $_GET['mode'] ?? 'searches' ) );
		WPTS\Analytics\Export::stream_csv( $days, $mode );
	} );
}

// Step 2: Boot plugin on plugins_loaded
add_action( 'plugins_loaded', function (): void {
	require_once WPTS_DIR . 'includes/class-core.php';
	WPTS\Core::instance()->boot();
} );

// Step 3: Register WP-CLI Commands
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once WPTS_DIR . 'includes/cli/class-cli-commands.php';
	\WP_CLI::add_command( 'turbo-search', 'WPTS\\CLI\\Commands' );
	\WP_CLI::add_command( 'wpts',         'WPTS\\CLI\\Commands' );

	// Register hyphenated aliases for subcommands
	$hyphen_commands = [
		'flush-cache'    => 'flush_cache',
		'flush-index'    => 'flush_index',
		'flush-tracking' => 'flush_tracking',
		'prune-logs'     => 'prune_logs',
	];
	foreach ( $hyphen_commands as $hyphen => $method ) {
		\WP_CLI::add_command( "turbo-search {$hyphen}", [ 'WPTS\\CLI\\Commands', $method ] );
		\WP_CLI::add_command( "wpts {$hyphen}",         [ 'WPTS\\CLI\\Commands', $method ] );
	}
}

// Activation / Deactivation
register_activation_hook(   __FILE__, [ 'WPTS\\Installer', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'WPTS\\Installer', 'deactivate' ] );

