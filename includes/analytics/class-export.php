<?php
namespace WPTS\Analytics;

defined( 'ABSPATH' ) || exit;

/**
 * Analytics Export - generates CSV exports for searches, clicks, and index statistics.
 */
class Export {

	/**
	 * Stream CSV export directly to browser.
	 */
	public static function stream_csv( int $days = 30, string $mode = 'searches' ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Forbidden', 'turbo-search' ) );
		}

		$filename = sprintf( 'wpts-analytics-%s-%s.csv', $mode, gmdate( 'Y-m-d' ) );

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $filename );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		$output = fopen( 'php://output', 'w' );
		if ( false === $output ) {
			exit;
		}

		// UTF-8 BOM for Excel
		fputs( $output, "\xEF\xBB\xBF" );

		if ( 'top' === $mode ) {
			self::write_top_queries_csv( $output, $days );
		} elseif ( 'zero' === $mode ) {
			self::write_zero_queries_csv( $output, $days );
		} else {
			self::write_searches_csv( $output, $days );
		}

		fclose( $output );
		exit;
	}

	private static function write_searches_csv( $handle, int $days ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'wpts_search_log';
		$since = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

		fputcsv( $handle, [ 'Date/Time', 'Query', 'Results Found', 'From Cache', 'Engine', 'Cache Driver', 'Post Type', 'Lang', 'Duration (ms)', 'Clicked Position', 'Variant' ] );

		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( ! $exists ) {
			return;
		}

		$rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore
			"SELECT searched_at, query, results, from_cache, engine, cache_driver, post_type, lang, duration_ms, clicked_position, variant
			 FROM {$table}
			 WHERE searched_at >= %s AND site_id = %d
			 ORDER BY searched_at DESC
			 LIMIT 10000",
			$since, get_current_blog_id()
		), ARRAY_A );

		foreach ( (array) $rows as $r ) {
			fputcsv( $handle, [
				$r['searched_at'],
				$r['query'],
				$r['results'],
				$r['from_cache'] ? 'Yes' : 'No',
				$r['engine'],
				$r['cache_driver'],
				$r['post_type'] ?: 'all',
				$r['lang'],
				$r['duration_ms'],
				$r['clicked_position'] ?: 'None',
				$r['variant'] ?? 'A',
			] );
		}
	}

	private static function write_top_queries_csv( $handle, int $days ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'wpts_search_log';
		$since = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

		fputcsv( $handle, [ 'Rank', 'Query', 'Search Count', 'Avg Results', 'Avg Speed (ms)', 'Cache Hits' ] );

		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( ! $exists ) {
			return;
		}

		$rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore
			"SELECT query, COUNT(*) as search_count, AVG(results) as avg_results, AVG(duration_ms) as avg_ms, SUM(from_cache) as cache_hits
			 FROM {$table}
			 WHERE searched_at >= %s AND site_id = %d
			 GROUP BY query_hash
			 ORDER BY search_count DESC
			 LIMIT 1000",
			$since, get_current_blog_id()
		), ARRAY_A );

		foreach ( (array) $rows as $i => $r ) {
			fputcsv( $handle, [
				$i + 1,
				$r['query'],
				$r['search_count'],
				round( (float) $r['avg_results'], 1 ),
				round( (float) $r['avg_ms'], 1 ),
				$r['cache_hits'],
			] );
		}
	}

	private static function write_zero_queries_csv( $handle, int $days ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'wpts_search_log';
		$since = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

		fputcsv( $handle, [ 'Query', 'Times Searched', 'Last Searched' ] );

		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( ! $exists ) {
			return;
		}

		$rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore
			"SELECT query, COUNT(*) as search_count, MAX(searched_at) as last_searched
			 FROM {$table}
			 WHERE searched_at >= %s AND results = 0 AND site_id = %d
			 GROUP BY query_hash
			 ORDER BY search_count DESC
			 LIMIT 1000",
			$since, get_current_blog_id()
		), ARRAY_A );

		foreach ( (array) $rows as $r ) {
			fputcsv( $handle, [
				$r['query'],
				$r['search_count'],
				$r['last_searched'],
			] );
		}
	}
}

