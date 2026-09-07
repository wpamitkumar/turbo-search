<?php
/**
 * AJAX handlers + shortcode/widget loader.
 */

use WPTS\Search\Synonyms;

defined( 'ABSPATH' ) || exit;

// Shortcode & Widget
require_once WPTS_DIR . 'templates/shortcode.php';
require_once WPTS_DIR . 'templates/search-results.php';

// AJAX fallback search
add_action( 'wp_ajax_wpts_search',        'wpts_ajax_search_handler' );
add_action( 'wp_ajax_nopriv_wpts_search', 'wpts_ajax_search_handler' );

function wpts_ajax_search_handler(): void {
	if ( ! check_ajax_referer( 'wpts_search', 'nonce', false ) ) {
		wp_send_json( [ 'hits' => [], 'found' => 0, 'error' => 'nonce' ], 403 );
		return;
	}

	$query    = trim( sanitize_text_field( wp_unslash( $_REQUEST['q'] ?? '' ) ) );
	$per_page = min( 100, max( 1, absint( wp_unslash( $_REQUEST['per_page'] ?? 10 ) ) ) );
	$page     = max( 1, absint( wp_unslash( $_REQUEST['page'] ?? 1 ) ) );

	if ( '' === $query ) {
		wp_send_json( [ 'hits' => [], 'found' => 0, 'page' => $page ] );
		return;
	}

	$filters   = [];
	$post_type = sanitize_text_field( wp_unslash( $_REQUEST['post_type'] ?? '' ) );
	$lang      = sanitize_text_field( wp_unslash( $_REQUEST['lang'] ?? '' ) );

	if ( '' !== $post_type ) {
		$filters['post_type'] = explode( ',', $post_type );
	}

	if ( '' !== $lang && ( defined( 'ICL_LANGUAGE_CODE' ) || function_exists( 'pll_current_language' ) ) ) {
		$filters['lang'] = $lang;
	}

	if ( is_multisite() ) {
		$filters['site_id'] = get_current_blog_id();
	}

	$engine  = \WPTS\Core::instance()->get_engine();
	$results = $engine->search( $query, $filters, $per_page, $page );
	$results = wp_parse_args( $results, [ 'hits' => [], 'found' => 0, 'page' => $page ] );

	wp_send_json( $results );
}

// AJAX: WooCommerce 1-Click Quick Add-to-Cart
add_action( 'wp_ajax_wpts_add_to_cart',        'wpts_ajax_add_to_cart_handler' );
add_action( 'wp_ajax_nopriv_wpts_add_to_cart', 'wpts_ajax_add_to_cart_handler' );

function wpts_ajax_add_to_cart_handler(): void {
	if ( ! check_ajax_referer( 'wpts_search', 'nonce', false ) ) {
		wp_send_json_error( [ 'message' => __( 'Security check failed.', 'turbo-search' ) ], 403 );
		return;
	}

	$product_id = absint( wp_unslash( $_REQUEST['product_id'] ?? 0 ) );
	$quantity   = max( 1, absint( wp_unslash( $_REQUEST['quantity'] ?? 1 ) ) );

	if ( ! $product_id ) {
		wp_send_json_error( [ 'message' => __( 'Invalid product ID.', 'turbo-search' ) ], 400 );
		return;
	}

	if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
		wp_send_json_error( [ 'message' => __( 'WooCommerce is not active.', 'turbo-search' ) ], 400 );
		return;
	}

	$passed_validation = apply_filters( 'woocommerce_add_to_cart_validation', true, $product_id, $quantity );
	$cart_item_key     = false;

	if ( $passed_validation ) {
		$cart_item_key = WC()->cart->add_to_cart( $product_id, $quantity );
	}

	if ( $cart_item_key ) {
		do_action( 'woocommerce_ajax_added_to_cart', $product_id );

		if ( class_exists( '\WC_AJAX' ) ) {
			\WC_AJAX::get_refreshed_fragments();
		} else {
			wp_send_json_success( [
				'added'      => true,
				'product_id' => $product_id,
				'quantity'   => $quantity,
				'count'      => WC()->cart->get_cart_contents_count(),
			] );
		}
	} else {
		wp_send_json_error( [
			'error'       => true,
			'message'     => __( 'Could not add product to cart.', 'turbo-search' ),
			'product_url' => get_permalink( $product_id ),
		], 400 );
	}
	wp_die();
}

// AJAX: Custom Synonyms CRUD
add_action( 'wp_ajax_wpts_save_synonym', function (): void {
	check_ajax_referer( 'wpts_admin', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( __( 'Forbidden', 'turbo-search' ) );
	}

	$words = sanitize_text_field( wp_unslash( $_POST['words'] ?? '' ) );
	$id    = absint( wp_unslash( $_POST['id'] ?? 0 ) );

	if ( '' === $words ) {
		wp_send_json_error( __( 'Synonym words cannot be empty.', 'turbo-search' ) );
	}

	$ok = Synonyms::save_custom_synonym( $words, $id );
	if ( $ok ) {
		wp_send_json_success( [ 'message' => __( 'Synonym saved!', 'turbo-search' ), 'synonyms' => Synonyms::get_custom_synonyms() ] );
	} else {
		wp_send_json_error( __( 'Could not save synonym.', 'turbo-search' ) );
	}
} );

add_action( 'wp_ajax_wpts_delete_synonym', function (): void {
	check_ajax_referer( 'wpts_admin', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( __( 'Forbidden', 'turbo-search' ) );
	}

	$id = absint( wp_unslash( $_POST['id'] ?? 0 ) );
	if ( $id <= 0 ) {
		wp_send_json_error( __( 'Invalid ID.', 'turbo-search' ) );
	}

	Synonyms::delete_custom_synonym( $id );
	wp_send_json_success( [ 'message' => __( 'Synonym deleted.', 'turbo-search' ) ] );
} );

// AJAX: Live dashboard stats
add_action( 'wp_ajax_wpts_live_stats', function (): void {
	check_ajax_referer( 'wpts_admin', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( __( 'Forbidden', 'turbo-search' ) );
	}

	global $wpdb;
	$indexed = \WPTS\Core::instance()->get_engine()->get_indexed_count();

	$log_table  = $wpdb->prefix . 'wpts_search_log';
	$log_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $log_table ) );

	$total_searches = $cache_hits = $cache_misses = $zero_result_searches = 0;
	if ( $log_exists ) {
		$row = $wpdb->get_row( // phpcs:ignore
			"SELECT
				COUNT(*)                           AS total_searches,
				SUM( from_cache = 1 )              AS cache_hits,
				SUM( from_cache = 0 )              AS cache_misses,
				SUM( results = 0 )                 AS zero_result_searches
			 FROM `{$log_table}`",
			ARRAY_A
		);
		if ( $row ) {
			$total_searches       = (int) $row['total_searches'];
			$cache_hits           = (int) $row['cache_hits'];
			$cache_misses         = (int) $row['cache_misses'];
			$zero_result_searches = (int) $row['zero_result_searches'];
		}
	}

	$total    = $cache_hits + $cache_misses;
	$hit_rate = $total > 0 ? round( $cache_hits / $total * 100, 1 ) : 0;

	$evt_table  = $wpdb->prefix . 'wpts_events';
	$evt_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $evt_table ) );

	$index_upserts = $index_deletes = $cache_flushes = 0;
	if ( $evt_exists ) {
		$evts = $wpdb->get_results( // phpcs:ignore
			"SELECT event_type, COUNT(*) AS cnt
			 FROM `{$evt_table}`
			 WHERE event_type IN ('index_upsert','index_delete','cache_flush_all')
			 GROUP BY event_type",
			ARRAY_A
		);
		foreach ( (array) $evts as $evt ) {
			switch ( $evt['event_type'] ) {
				case 'index_upsert': $index_upserts = (int) $evt['cnt']; break;
				case 'index_delete': $index_deletes = (int) $evt['cnt']; break;
				case 'cache_flush_all': $cache_flushes = (int) $evt['cnt']; break;
			}
		}
	}

	wp_send_json_success( [
		'indexed_posts'        => $indexed,
		'total_searches'       => $total_searches,
		'cache_hits'           => $cache_hits,
		'cache_misses'         => $cache_misses,
		'cache_hit_rate'       => $hit_rate,
		'zero_result_searches' => $zero_result_searches,
		'index_upserts'        => $index_upserts,
		'index_deletes'        => $index_deletes,
		'cache_flushes'        => $cache_flushes,
	] );
} );

// AJAX: Typesense collection status
add_action( 'wp_ajax_wpts_typesense_status', function (): void {
	check_ajax_referer( 'wpts_admin', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( __( 'Forbidden', 'turbo-search' ) );
	}

	$settings  = \WPTS\Admin\Settings::get_all();
	$typesense = new \WPTS\Cache\Typesense( $settings );
	wp_send_json_success( $typesense->get_status() );
} );

// AJAX: Elasticsearch status check
add_action( 'wp_ajax_wpts_elasticsearch_status', function (): void {
	check_ajax_referer( 'wpts_admin', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( __( 'Forbidden', 'turbo-search' ) );
	}

	$settings = \WPTS\Admin\Settings::get_all();
	$es       = new \WPTS\Cache\Elasticsearch( $settings );
	wp_send_json_success( $es->get_status() );
} );

// AJAX: Flush Typesense
add_action( 'wp_ajax_wpts_typesense_flush', function (): void {
	check_ajax_referer( 'wpts_admin', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( __( 'Forbidden', 'turbo-search' ) );
	}

	$settings  = \WPTS\Admin\Settings::get_all();
	$typesense = new \WPTS\Cache\Typesense( $settings );
	$success   = $typesense->flush_collection();
	\WPTS\Core::instance()->get_engine()->flush_all();

	if ( $success ) {
		wp_send_json_success( [ 'message' => __( 'Typesense index cleared.', 'turbo-search' ) ] );
	} else {
		wp_send_json_error( __( 'Could not flush Typesense.', 'turbo-search' ) );
	}
} );

// AJAX: Chunked reindex
add_action( 'wp_ajax_wpts_reindex_chunk', function (): void {
	check_ajax_referer( 'wpts_admin', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( __( 'Forbidden', 'turbo-search' ) );
	}

	\WPTS\Installer::ensure_tables();
	$page   = max( 1, absint( wp_unslash( $_POST['page'] ?? 1 ) ) );
	$batch  = min( 200, max( 10, absint( wp_unslash( $_POST['batch'] ?? 50 ) ) ) );
	$engine = \WPTS\Core::instance()->get_engine();

	$result = $engine->reindex_chunk( $page, $batch );
	if ( ! empty( $result['done'] ) ) {
		$result['indexed_count'] = $engine->get_indexed_count();
	}

	wp_send_json_success( $result );
} );

// AJAX: Flush cache
add_action( 'wp_ajax_wpts_flush_cache_ajax', function (): void {
	check_ajax_referer( 'wpts_admin', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( __( 'Forbidden', 'turbo-search' ) );
	}

	$engine = \WPTS\Core::instance()->get_engine();
	$driver = method_exists( $engine, 'get_driver' ) ? $engine->get_driver() : 'unknown';
	$engine->flush_all();

	wp_send_json_success( [
		'message' => sprintf(
			__( '✅ Cache flushed (driver: %s).', 'turbo-search' ),
			$driver
		),
	] );
} );

// AJAX: Test Redis / Memcached
add_action( 'wp_ajax_wpts_test_redis', function (): void {
	check_ajax_referer( 'wpts_admin', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( __( 'Forbidden', 'turbo-search' ) );
	}

	if ( ! extension_loaded( 'redis' ) ) {
		wp_send_json_error( __( 'PHP ext-redis is not installed on this server.', 'turbo-search' ) );
	}

	$host = defined( 'WPTS_REDIS_HOST' ) ? (string) WPTS_REDIS_HOST : sanitize_text_field( wp_unslash( $_POST['host'] ?? '' ) );
	$port = defined( 'WPTS_REDIS_PORT' ) ? (int) WPTS_REDIS_PORT : absint( wp_unslash( $_POST['port'] ?? 6379 ) );
	$pass = defined( 'WPTS_REDIS_PASSWORD' ) ? (string) WPTS_REDIS_PASSWORD : sanitize_text_field( wp_unslash( $_POST['password'] ?? '' ) );
	$db   = defined( 'WPTS_REDIS_DB' ) ? (int) WPTS_REDIS_DB : absint( wp_unslash( $_POST['db'] ?? 0 ) );

	if ( ! $host ) {
		$host = (string) \WPTS\Admin\Settings::get( 'redis_host', '127.0.0.1' );
	}

	try {
		$redis = new \Redis();
		if ( ! $redis->connect( $host, $port, 2.0 ) ) {
			throw new \RuntimeException( "connect() failed for {$host}:{$port}" );
		}
		if ( $pass ) $redis->auth( $pass );
		$redis->select( $db );
		$redis->ping();
		$info    = $redis->info( 'server' );
		$version = $info['redis_version'] ?? '?';
		$redis->close();
		wp_send_json_success( sprintf( __( 'Connected! Redis v%s', 'turbo-search' ), $version ) );
	} catch ( \Exception $e ) {
		wp_send_json_error( $e->getMessage() );
	}
} );

add_action( 'wp_ajax_wpts_test_memcached', function (): void {
	check_ajax_referer( 'wpts_admin', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( __( 'Forbidden', 'turbo-search' ) );
	}

	if ( ! extension_loaded( 'memcached' ) ) {
		wp_send_json_error( __( 'PHP ext-memcached is not installed on this server.', 'turbo-search' ) );
	}

	$host = defined( 'WPTS_MEMCACHED_HOST' ) ? (string) WPTS_MEMCACHED_HOST : sanitize_text_field( wp_unslash( $_POST['host'] ?? '' ) );
	$port = defined( 'WPTS_MEMCACHED_PORT' ) ? (int) WPTS_MEMCACHED_PORT : absint( wp_unslash( $_POST['port'] ?? 11211 ) );

	if ( ! $host ) {
		$host = (string) \WPTS\Admin\Settings::get( 'memcached_host', '127.0.0.1' );
	}

	try {
		$mc = new \Memcached( 'wpts_test_' . uniqid() );
		$mc->addServer( $host, $port );
		$mc->set( 'wpts_ping', 'pong', 5 );
		$val = $mc->get( 'wpts_ping' );
		if ( 'pong' !== $val ) {
			wp_send_json_error( __( 'Round-trip test failed.', 'turbo-search' ) );
		}
		$stats   = $mc->getStats();
		$version = $stats["{$host}:{$port}"]['version'] ?? '?';
		wp_send_json_success( sprintf( __( 'Connected! Memcached v%s', 'turbo-search' ), $version ) );
	} catch ( \Exception $e ) {
		wp_send_json_error( $e->getMessage() );
	}
} );

// AJAX: Dashboard Chart
add_action( 'wp_ajax_wpts_dashboard_chart', function (): void {
	check_ajax_referer( 'wpts_admin', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( __( 'Forbidden', 'turbo-search' ) );
	}

	$days = absint( sanitize_text_field( wp_unslash( $_GET['days'] ?? '14' ) ) );
	$rows = \WPTS\Tracker::instance()->daily_volume( $days );

	$labels = $total = $cached = $zero = [];
	$map    = [];
	foreach ( $rows as $r ) $map[ $r['day'] ] = $r;

	for ( $i = $days - 1; $i >= 0; $i-- ) {
		$day      = gmdate( 'Y-m-d', strtotime( "-{$i} days" ) );
		$r        = $map[ $day ] ?? null;
		$labels[] = gmdate( 'M j', strtotime( $day ) );
		$total[]  = $r ? (int) $r['total']        : 0;
		$cached[] = $r ? (int) $r['cached']       : 0;
		$zero[]   = $r ? (int) $r['zero_results'] : 0;
	}

	wp_send_json_success( compact( 'labels', 'total', 'cached', 'zero' ) );
} );
