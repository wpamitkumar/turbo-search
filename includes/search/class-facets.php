<?php
namespace WPTS\Search;

defined( 'ABSPATH' ) || exit;

/**
 * Computes faceted filter counts (categories, tags, custom taxonomies, post types, dates, WooCommerce stock/price).
 */
class Facets {

	/**
	 * Build facet aggregations for a list of matched post IDs.
	 *
	 * @param int[] $post_ids Matched post IDs.
	 * @return array<string, array<string, mixed>>
	 */
	public static function build_facets( array $post_ids ): array {
		if ( empty( $post_ids ) ) {
			return [
				'post_types' => [],
				'taxonomies' => [],
				'dates'      => [],
			];
		}

		global $wpdb;
		$id_list = implode( ',', array_map( 'absint', $post_ids ) );

		// 1. Post Type facets
		$post_types_rows = $wpdb->get_results( // phpcs:ignore
			"SELECT post_type, COUNT(*) as count
			 FROM {$wpdb->posts}
			 WHERE ID IN ({$id_list})
			 GROUP BY post_type
			 ORDER BY count DESC",
			ARRAY_A
		);

		$post_types = [];
		foreach ( (array) $post_types_rows as $row ) {
			$pt_obj = get_post_type_object( $row['post_type'] );
			$post_types[] = [
				'name'  => $row['post_type'],
				'label' => $pt_obj ? $pt_obj->labels->singular_name : ucfirst( $row['post_type'] ),
				'count' => (int) $row['count'],
			];
		}

		// 2. Taxonomy facets (Categories, Tags, Custom Taxonomies, Product Categories)
		$tax_rows = $wpdb->get_results( // phpcs:ignore
			"SELECT tt.taxonomy, t.term_id, t.name, t.slug, COUNT(tr.object_id) as count
			 FROM {$wpdb->term_relationships} tr
			 INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
			 INNER JOIN {$wpdb->terms} t ON tt.term_id = t.term_id
			 WHERE tr.object_id IN ({$id_list})
			 GROUP BY tt.taxonomy, t.term_id
			 ORDER BY tt.taxonomy ASC, count DESC
			 LIMIT 100",
			ARRAY_A
		);

		$taxonomies = [];
		foreach ( (array) $tax_rows as $row ) {
			$tax_slug = $row['taxonomy'];
			$tax_obj  = get_taxonomy( $tax_slug );

			if ( ! isset( $taxonomies[ $tax_slug ] ) ) {
				$taxonomies[ $tax_slug ] = [
					'taxonomy' => $tax_slug,
					'label'    => $tax_obj ? $tax_obj->labels->name : ucfirst( $tax_slug ),
					'terms'    => [],
				];
			}

			$taxonomies[ $tax_slug ]['terms'][] = [
				'id'    => (int) $row['term_id'],
				'name'  => $row['name'],
				'slug'  => $row['slug'],
				'count' => (int) $row['count'],
			];
		}

		// 3. Date facets
		$now = is_numeric( current_time( 'timestamp' ) ) ? (int) current_time( 'timestamp' ) : time();
		$d_24h   = $now - DAY_IN_SECONDS;
		$d_7d    = $now - 7 * DAY_IN_SECONDS;
		$d_30d   = $now - 30 * DAY_IN_SECONDS;
		$d_year  = $now - 365 * DAY_IN_SECONDS;

		$date_rows = $wpdb->get_results( // phpcs:ignore
			"SELECT UNIX_TIMESTAMP(post_date) as post_ts
			 FROM {$wpdb->posts}
			 WHERE ID IN ({$id_list})",
			ARRAY_A
		);

		$dates = [
			'24h'   => [ 'label' => __( 'Last 24 hours', 'turbo-search' ), 'count' => 0 ],
			'7d'    => [ 'label' => __( 'Past week', 'turbo-search' ),      'count' => 0 ],
			'30d'   => [ 'label' => __( 'Past month', 'turbo-search' ),     'count' => 0 ],
			'1y'    => [ 'label' => __( 'Past year', 'turbo-search' ),      'count' => 0 ],
			'older' => [ 'label' => __( 'Older', 'turbo-search' ),          'count' => 0 ],
		];

		foreach ( (array) $date_rows as $dr ) {
			$ts = (int) $dr['post_ts'];
			if ( $ts >= $d_24h ) {
				$dates['24h']['count']++;
			} elseif ( $ts >= $d_7d ) {
				$dates['7d']['count']++;
			} elseif ( $ts >= $d_30d ) {
				$dates['30d']['count']++;
			} elseif ( $ts >= $d_year ) {
				$dates['1y']['count']++;
			} else {
				$dates['older']['count']++;
			}
		}

		return [
			'post_types' => $post_types,
			'taxonomies' => array_values( $taxonomies ),
			'dates'      => $dates,
		];
	}
}

