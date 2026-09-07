<?php
namespace WPTS\Analytics;

defined( 'ABSPATH' ) || exit;

/**
 * Generates Search Console-style analytics: CTR per result position, top queries, zero-result searches, and no-click queries.
 */
class AnalyticsReport {

	/**
	 * Compute Click-Through Rate (CTR) grouped by result position (1st, 2nd, 3rd, 4th, 5th, etc.).
	 *
	 * @return array<int, array{position: int, clicks: int, impressions: int, ctr: float}>
	 */
	public static function get_ctr_by_position( int $days = 30 ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'wpts_search_log';
		$since = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( ! $exists ) {
			return [];
		}

		// Fetch clicks with position
		$rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore
			"SELECT clicked_position, COUNT(*) as clicks
			 FROM {$table}
			 WHERE searched_at >= %s AND clicked_position > 0 AND site_id = %d
			 GROUP BY clicked_position
			 ORDER BY clicked_position ASC
			 LIMIT 10",
			$since, get_current_blog_id()
		), ARRAY_A );

		$total_searches = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore
			"SELECT COUNT(*) FROM {$table} WHERE searched_at >= %s AND results > 0 AND site_id = %d",
			$since, get_current_blog_id()
		) );

		$out = [];
		$keywords_by_pos = [];

		if ( ! empty( $rows ) ) {
			$all_keywords = (array) $wpdb->get_results( $wpdb->prepare( // phpcs:ignore
				"SELECT clicked_position, query, MAX(post_type) as post_type, COUNT(*) as keyword_clicks, MAX(searched_at) as last_clicked
				 FROM {$table}
				 WHERE searched_at >= %s AND clicked_position > 0 AND site_id = %d
				 GROUP BY clicked_position, query_hash
				 ORDER BY clicked_position ASC, keyword_clicks DESC",
				$since, get_current_blog_id()
			), ARRAY_A );

			foreach ( $all_keywords as $kw ) {
				$p = (int) $kw['clicked_position'];
				if ( ! isset( $keywords_by_pos[ $p ] ) ) {
					$keywords_by_pos[ $p ] = [];
				}
				if ( count( $keywords_by_pos[ $p ] ) < 5 ) {
					$keywords_by_pos[ $p ][] = [
						'query'          => $kw['query'],
						'post_type'      => (string) ( $kw['post_type'] ?? '' ),
						'keyword_clicks' => (int) $kw['keyword_clicks'],
						'last_clicked'   => $kw['last_clicked'],
					];
				}
			}
		}

		foreach ( (array) $rows as $r ) {
			$pos    = (int) $r['clicked_position'];
			$clicks = (int) $r['clicks'];
			$ctr    = $total_searches > 0 ? round( ( $clicks / $total_searches ) * 100, 2 ) : 0.0;

			$out[] = [
				'position'     => $pos,
				'clicks'       => $clicks,
				'impressions'  => $total_searches,
				'ctr'          => $ctr,
				'top_keywords' => $keywords_by_pos[ $pos ] ?? [],
			];
		}

		return $out;
	}

	/**
	 * Retrieve full list of search keywords with their clicked rank positions and click volume.
	 */
	public static function get_keyword_click_breakdown( int $limit = 25, int $days = 30 ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'wpts_search_log';
		$since = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( ! $exists ) {
			return [];
		}

		return (array) $wpdb->get_results( $wpdb->prepare( // phpcs:ignore
			"SELECT query, clicked_position, MAX(post_type) as post_type, COUNT(*) as clicks, MAX(searched_at) as last_clicked
			 FROM {$table}
			 WHERE searched_at >= %s AND clicked_position > 0 AND site_id = %d
			 GROUP BY query_hash, clicked_position
			 ORDER BY clicks DESC, last_clicked DESC
			 LIMIT %d",
			$since, get_current_blog_id(), $limit
		), ARRAY_A );
	}

	/**
	 * Retrieve queries that yielded results but received zero clicks (content exists, but low relevance).
	 */
	public static function get_no_click_queries( int $limit = 20, int $days = 30 ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'wpts_search_log';
		$since = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( ! $exists ) {
			return [];
		}

		return (array) $wpdb->get_results( $wpdb->prepare( // phpcs:ignore
			"SELECT query, COUNT(*) as search_count, AVG(results) as avg_results, MAX(searched_at) as last_searched
			 FROM {$table}
			 WHERE searched_at >= %s AND results > 0 AND (clicked_position IS NULL OR clicked_position = 0) AND site_id = %d
			 GROUP BY query_hash
			 ORDER BY search_count DESC
			 LIMIT %d",
			$since, get_current_blog_id(), $limit
		), ARRAY_A );
	}

	/**
	 * Overall Summary Metrics.
	 */
	public static function get_summary( int $days = 30 ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'wpts_search_log';
		$since = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( ! $exists ) {
			return [
				'total_searches' => 0,
				'total_clicks'   => 0,
				'overall_ctr'    => 0.0,
				'zero_results'   => 0,
				'cache_hit_rate' => 0.0,
			];
		}

		$row = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore
			"SELECT
				COUNT(*) AS total,
				SUM( CASE WHEN clicked_position > 0 THEN 1 ELSE 0 END ) AS clicks,
				SUM( CASE WHEN results = 0 THEN 1 ELSE 0 END ) AS zero_results,
				SUM( from_cache = 1 ) AS cache_hits
			 FROM {$table}
			 WHERE searched_at >= %s AND site_id = %d",
			$since, get_current_blog_id()
		), ARRAY_A );

		$total   = (int) ( $row['total'] ?? 0 );
		$clicks  = (int) ( $row['clicks'] ?? 0 );
		$zero    = (int) ( $row['zero_results'] ?? 0 );
		$hits    = (int) ( $row['cache_hits'] ?? 0 );
		$misses  = max( 0, $total - $hits );

		$ctr      = $total > 0 ? round( ( $clicks / $total ) * 100, 1 ) : 0.0;
		$hit_rate = $total > 0 ? round( ( $hits / $total ) * 100, 1 ) : 0.0;

		return [
			'total_searches' => $total,
			'total_clicks'   => $clicks,
			'overall_ctr'    => $ctr,
			'zero_results'   => $zero,
			'cache_hit_rate' => $hit_rate,
			'cache_hits'     => $hits,
			'cache_misses'   => $misses,
		];
	}
}

