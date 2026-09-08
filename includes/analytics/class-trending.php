<?php
namespace WPTS\Analytics;

defined( 'ABSPATH' ) || exit;

/**
 * Trending searches calculator and shortcode/widget renderer.
 */
class Trending {

	/**
	 * Retrieve trending search queries for the past N days.
	 *
	 * @return array<int, array{query: string, count: int}>
	 */
	public static function get_trending( int $limit = 6, int $days = 7 ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'wpts_search_log';
		$since = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( ! $exists ) {
			return [];
		}

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT query, COUNT(*) as count
			 FROM {$table}
			 WHERE searched_at >= %s AND results > 0 AND site_id = %d
			 GROUP BY query_hash
			 ORDER BY count DESC
			 LIMIT %d",
			$since, get_current_blog_id(), $limit
		), ARRAY_A );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB

		$out = [];
		foreach ( (array) $rows as $r ) {
			$q = trim( (string) $r['query'] );
			if ( '' !== $q && mb_strlen( $q, 'UTF-8' ) >= 2 ) {
				$out[] = [
					'query' => $q,
					'count' => (int) $r['count'],
				];
			}
		}

		return $out;
	}

	/**
	 * Render the trending searches HTML markup.
	 */
	public static function render_html( array $atts = [] ): string {
		$a = shortcode_atts( [
			'limit' => 6,
			'days'  => 7,
			'title' => __( '🔥 Trending:', 'turbo-search' ),
		], $atts, 'wpts_trending' );

		$trending = self::get_trending( absint( $a['limit'] ), absint( $a['days'] ) );
		if ( empty( $trending ) ) {
			return '';
		}

		$items_html = '';
		foreach ( $trending as $item ) {
			$q_esc = esc_attr( $item['query'] );
			$items_html .= sprintf(
				'<button type="button" class="wpts-trending-pill" data-query="%s">%s</button>',
				$q_esc,
				esc_html( $item['query'] )
			);
		}

		return sprintf(
			'<div class="wpts-trending-wrap"><span class="wpts-trending-title">%s</span><div class="wpts-trending-list">%s</div></div>',
			esc_html( $a['title'] ),
			$items_html
		);
	}
}

