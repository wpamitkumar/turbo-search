<?php
namespace WPTS\Search;

use WPTS\Search\Highlighter;
use WPTS\Search\Facets;
use WPTS\Universal\Utils;

defined( 'ABSPATH' ) || exit;

/**
 * Fallback Search Engine using native WordPress Core WP_Query.
 * Ensures search functionality never breaks even if external search engines
 * (Typesense, Elasticsearch), caching layers (Redis, Memcached), or the custom
 * MySQL index table are unavailable, unindexed, or encounter runtime errors.
 */
class WPCoreFallback {

	/**
	 * Execute search using WordPress Core native WP_Query.
	 *
	 * @param string $query    Raw search string.
	 * @param array  $filters  Search filters (post_type, lang, etc.).
	 * @param int    $per_page Number of hits per page.
	 * @param int    $page     Page number.
	 * @return array Standard search result array with hits, found count, and facets.
	 */
	public static function search(
		string $query,
		array  $filters  = [],
		int    $per_page = 10,
		int    $page     = 1
	): array {
		$raw_query = trim( $query );
		if ( '' === $raw_query ) {
			return [
				'hits'         => [],
				'found'        => 0,
				'page'         => $page,
				'facets'       => [ 'post_types' => [], 'taxonomies' => [], 'dates' => [] ],
				'did_you_mean' => null,
				'engine'       => 'wp_core',
			];
		}

		$settings = class_exists( '\WPTS\Admin\Settings' ) ? \WPTS\Admin\Settings::get_all() : [];

		// 1. Resolve Post Types
		$post_types = [];
		if ( ! empty( $filters['post_type'] ) ) {
			$post_types = is_array( $filters['post_type'] )
				? $filters['post_type']
				: explode( ',', (string) $filters['post_type'] );
		} else {
			$post_types = (array) ( $settings['post_types'] ?? [ 'post', 'page' ] );
		}

		$post_types = array_values( array_filter( array_map( 'sanitize_key', $post_types ) ) );
		if ( empty( $post_types ) ) {
			$post_types = [ 'post', 'page' ];
		}

		// 2. Build WP_Query arguments
		$has_attachment = in_array( 'attachment', $post_types, true );
		$post_statuses  = $has_attachment ? [ 'publish', 'inherit' ] : [ 'publish' ];

		$query_args = [
			's'                   => $raw_query,
			'post_type'           => $post_types,
			'post_status'         => $post_statuses,
			'posts_per_page'      => $per_page,
			'paged'               => $page,
			'ignore_sticky_posts' => true,
			'no_found_rows'       => false,
		];

		// Language filter (WPML / Polylang)
		if ( ! empty( $filters['lang'] ) ) {
			$query_args['lang'] = sanitize_text_field( $filters['lang'] );
		}

		$query_args = apply_filters( 'wpts_core_fallback_query_args', $query_args, $raw_query, $filters );

		$hits     = [];
		$post_ids = [];
		$found    = 0;

		try {
			$wp_query = new \WP_Query( $query_args );
			$found    = (int) $wp_query->found_posts;

			if ( $wp_query->have_posts() ) {
				$posts = $wp_query->posts;

				foreach ( $posts as $post ) {
					if ( ! ( $post instanceof \WP_Post ) ) {
						continue;
					}

					$post_id    = (int) $post->ID;
					$post_ids[] = $post_id;
					$raw_title  = get_the_title( $post );
					$raw_exc    = get_the_excerpt( $post );
					$raw_cont   = (string) $post->post_content;

					if ( ! empty( $settings['highlight_results'] ) && class_exists( '\WPTS\Search\Highlighter' ) ) {
						$title   = Highlighter::highlight( $raw_title, $raw_query );
						$excerpt = Highlighter::highlight_snippet( $raw_cont, $raw_exc, $raw_query );
					} else {
						$title   = esc_html( $raw_title );
						$excerpt = esc_html( $raw_exc );
					}

					$thumb_url = function_exists( 'get_the_post_thumbnail_url' )
						? ( (string) ( get_the_post_thumbnail_url( $post_id, 'thumbnail' ) ?: '' ) )
						: '';

					$doc_url = 'attachment' === $post->post_type
						? ( (string) ( wp_get_attachment_url( $post_id ) ?: get_permalink( $post_id ) ) )
						: (string) get_permalink( $post_id );

					// Safe WooCommerce product details (only if WooCommerce is loaded)
					$sku          = '';
					$price        = 0.0;
					$stock_status = 'instock';

					if ( function_exists( 'wc_get_product' ) && in_array( $post->post_type, [ 'product', 'product_variation' ], true ) ) {
						try {
							$product = wc_get_product( $post_id );
							if ( $product ) {
								$sku          = (string) $product->get_sku();
								$price        = (float) $product->get_price();
								$stock_status = (string) $product->get_stock_status();
							}
						} catch ( \Throwable $e ) {
							// Suppress WooCommerce lookup errors
						}
					}

					$post_time = strtotime( $post->post_date_gmt ?: $post->post_date );
					$timestamp = $post_time ? (int) $post_time : time();

					$hit = [
						'post_id'        => $post_id,
						'title'          => $title,
						'excerpt'        => $excerpt,
						'post_type'      => (string) $post->post_type,
						'url'            => $doc_url,
						'thumbnail_url'  => $thumb_url,
						'date'           => $timestamp,
						'date_formatted' => function_exists( 'date_i18n' ) ? date_i18n( get_option( 'date_format' ), $timestamp ) : date( 'Y-m-d', $timestamp ),
						'sku'            => $sku,
						'price'          => $price,
						'stock_status'   => $stock_status,
					];

					$hits[] = apply_filters( 'wpts_result_hit', $hit, $raw_query );
				}
			}
		} catch ( \Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[WPTS] WPCoreFallback query error: ' . $e->getMessage() );
			}
		}

		// Build facets if Facets class exists
		$facets = [ 'post_types' => [], 'taxonomies' => [], 'dates' => [] ];
		if ( ! empty( $post_ids ) && class_exists( '\WPTS\Search\Facets' ) ) {
			try {
				$facets = Facets::build_facets( $post_ids );
			} catch ( \Throwable $e ) {
				// Ignore facets error on fallback
			}
		}

		return [
			'hits'         => $hits,
			'found'        => $found,
			'page'         => $page,
			'facets'       => $facets,
			'did_you_mean' => null,
			'engine'       => 'wp_core',
		];
	}
}

