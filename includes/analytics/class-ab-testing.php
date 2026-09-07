<?php
namespace WPTS\Analytics;

defined( 'ABSPATH' ) || exit;

/**
 * A/B Testing Framework - compares search performance (cache hit rate, searches, CTR)
 * between two different debounce and theme configurations.
 */
class ABTesting {

	/**
	 * Determine active variant for current request (cookie or random 50/50).
	 */
	public static function get_current_variant(): string {
		if ( isset( $_COOKIE['wpts_ab_variant'] ) && in_array( $_COOKIE['wpts_ab_variant'], [ 'A', 'B' ], true ) ) {
			return $_COOKIE['wpts_ab_variant'];
		}

		$variant = ( mt_rand( 0, 1 ) === 0 ) ? 'A' : 'B';
		if ( ! headers_sent() ) {
			setcookie( 'wpts_ab_variant', $variant, time() + 30 * DAY_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN );
		}
		return $variant;
	}

	/**
	 * Retrieve comparative performance statistics between Variant A and Variant B.
	 *
	 * @return array{
	 *   enabled: bool,
	 *   variant_a: array{searches: int, cache_hits: int, cache_hit_rate: float, clicks: int, ctr: float},
	 *   variant_b: array{searches: int, cache_hits: int, cache_hit_rate: float, clicks: int, ctr: float}
	 * }
	 */
	public static function get_comparison(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'wpts_search_log';

		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( ! $exists ) {
			return [
				'enabled'   => false,
				'variant_a' => [ 'searches' => 0, 'cache_hits' => 0, 'cache_hit_rate' => 0.0, 'clicks' => 0, 'ctr' => 0.0 ],
				'variant_b' => [ 'searches' => 0, 'cache_hits' => 0, 'cache_hit_rate' => 0.0, 'clicks' => 0, 'ctr' => 0.0 ],
			];
		}

		$rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore
			"SELECT
				variant,
				COUNT(*) AS total,
				SUM(from_cache = 1) AS cache_hits,
				SUM(CASE WHEN clicked_position > 0 THEN 1 ELSE 0 END) AS clicks
			 FROM `{$table}`
			 WHERE variant IN ('A', 'B') AND site_id = %d
			 GROUP BY variant",
			get_current_blog_id()
		), ARRAY_A );

		$data = [
			'A' => [ 'searches' => 0, 'cache_hits' => 0, 'cache_hit_rate' => 0.0, 'clicks' => 0, 'ctr' => 0.0 ],
			'B' => [ 'searches' => 0, 'cache_hits' => 0, 'cache_hit_rate' => 0.0, 'clicks' => 0, 'ctr' => 0.0 ],
		];

		foreach ( (array) $rows as $r ) {
			$v      = $r['variant'];
			$total  = (int) $r['total'];
			$hits   = (int) $r['cache_hits'];
			$clicks = (int) $r['clicks'];

			$data[ $v ] = [
				'searches'       => $total,
				'cache_hits'     => $hits,
				'cache_hit_rate' => $total > 0 ? round( ( $hits / $total ) * 100, 1 ) : 0.0,
				'clicks'         => $clicks,
				'ctr'            => $total > 0 ? round( ( $clicks / $total ) * 100, 1 ) : 0.0,
			];
		}

		return [
			'enabled'   => (bool) \WPTS\Admin\Settings::get( 'ab_testing_enabled', false ),
			'variant_a' => $data['A'],
			'variant_b' => $data['B'],
		];
	}
}

