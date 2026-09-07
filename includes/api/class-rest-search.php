<?php
namespace WPTS\API;

use WPTS\Security\BotProtection;
use WPTS\Security\RoleRestrictions;
use WPTS\Analytics\Trending;
use WPTS\Search\UserHistory;
use WPTS\Search\Spelling;

defined( 'ABSPATH' ) || exit;

/**
 * REST API endpoints for Turbo Search.
 */
class RestSearch {

	private const NAMESPACE = 'wpts/v1';

	public function register_routes(): void {
		add_filter( 'rest_authentication_errors', [ $this, 'allow_public_access' ], 100 );

		// Search
		register_rest_route( self::NAMESPACE, '/search', [
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'handle_search' ],
				'permission_callback' => '__return_true',
				'args'                => $this->search_args(),
			],
		] );

		// Track Click
		register_rest_route( self::NAMESPACE, '/track-click', [
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'handle_track_click' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'q'        => [ 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ],
					'post_id'  => [ 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ],
					'position' => [ 'type' => 'integer', 'default' => 1, 'sanitize_callback' => 'absint' ],
				],
			],
		] );

		// Trending Searches
		register_rest_route( self::NAMESPACE, '/trending', [
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'handle_trending' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'limit' => [ 'type' => 'integer', 'default' => 6 ],
					'days'  => [ 'type' => 'integer', 'default' => 7 ],
				],
			],
		] );

		// User History & Favorites (logged-in)
		register_rest_route( self::NAMESPACE, '/favorites', [
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'handle_get_favorites' ],
				'permission_callback' => function () {
					return is_user_logged_in();
				},
			],
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'handle_toggle_favorite' ],
				'permission_callback' => function () {
					return is_user_logged_in();
				},
				'args'                => [
					'q' => [ 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ],
				],
			],
		] );

		// Autocomplete / Suggest
		register_rest_route( self::NAMESPACE, '/suggest', [
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'handle_suggest' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'q'    => [ 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ],
					'lang' => [ 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ],
				],
			],
		] );

		// Reindex (admin only)
		register_rest_route( self::NAMESPACE, '/reindex', [
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'handle_reindex' ],
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			],
		] );

		// Health
		register_rest_route( self::NAMESPACE, '/health', [
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'handle_health' ],
				'permission_callback' => '__return_true',
			],
		] );
	}

	public function allow_public_access( $result ) {
		if ( ! is_wp_error( $result ) ) {
			return $result;
		}

		$route = $_SERVER['PATH_INFO'] ?? ( $_SERVER['REQUEST_URI'] ?? '' );
		// Never bypass authentication errors for admin endpoints
		if ( false !== strpos( $route, '/wpts/v1/admin' ) ) {
			return $result;
		}

		if ( false !== strpos( $route, '/wpts/' ) || false !== strpos( $route, 'rest_route=%2Fwpts' ) ) {
			return null;
		}

		return $result;
	}

	public function handle_search( \WP_REST_Request $request ): \WP_REST_Response {
		// Anti-bot & Honeypot protection
		if ( ! BotProtection::is_allowed( $request ) ) {
			return new \WP_REST_Response( [ 'error' => 'forbidden', 'hits' => [], 'found' => 0 ], 403 );
		}

		$query    = trim( sanitize_text_field( (string) ( $request->get_param( 'q' ) ?? '' ) ) );
		$per_page = min( 100, max( 1, (int) ( $request->get_param( 'per_page' ) ?? 10 ) ) );
		$page     = max( 1, (int) ( $request->get_param( 'page' ) ?? 1 ) );

		if ( '' === $query ) {
			return $this->json( [ 'hits' => [], 'found' => 0, 'page' => $page ] );
		}

		// Filters
		$filters   = [];
		$post_type = sanitize_text_field( (string) ( $request->get_param( 'post_type' ) ?? '' ) );
		$lang      = sanitize_text_field( (string) ( $request->get_param( 'lang' ) ?? '' ) );
		$site_id   = (int) ( $request->get_param( 'site_id' ) ?? get_current_blog_id() );
		$stock     = sanitize_key( (string) ( $request->get_param( 'stock_status' ) ?? '' ) );

		if ( '' !== $post_type ) {
			$types = explode( ',', $post_type );
			$allowed = RoleRestrictions::filter_allowed_post_types( $types );
			if ( empty( $allowed ) ) {
				return $this->json( [ 'hits' => [], 'found' => 0, 'page' => $page ] );
			}
			$filters['post_type'] = $allowed;
		} else {
			$configured = (array) \WPTS\Admin\Settings::get( 'post_types', [] );
			if ( empty( $configured ) ) {
				$configured = function_exists( 'get_post_types' )
					? array_values( get_post_types( [ 'public' => true ], 'names' ) )
					: [ 'post', 'page', 'product' ];
			}
			if ( ! empty( \WPTS\Admin\Settings::get( 'index_attachments' ) ) && ! in_array( 'attachment', $configured, true ) ) {
				$configured[] = 'attachment';
			}
			$allowed = RoleRestrictions::filter_allowed_post_types( $configured );
			if ( ! empty( $allowed ) ) {
				$filters['post_type'] = $allowed;
			}
		}

		if ( '' !== $lang && $this->is_multilingual_active() ) {
			$filters['lang'] = $lang;
		}

		if ( is_multisite() ) {
			$filters['site_id'] = max( 1, $site_id );
		}

		if ( '' !== $stock ) {
			$filters['stock_status'] = $stock;
		}

		$query = (string) apply_filters( 'wpts_rest_search_query', $query, $request );
		if ( '' === $query ) {
			return $this->json( [ 'hits' => [], 'found' => 0, 'page' => $page ] );
		}

		$settings = \WPTS\Admin\Settings::get_all();
		$engine   = \WPTS\Core::instance()->get_engine();

		if ( is_multisite() && ! empty( $settings['multisite_cross_search'] ) && class_exists( '\WPTS\Multisite\Network' ) ) {
			$results = \WPTS\Multisite\Network::search_cross_network( $query, $filters, $per_page, $page );
		} else {
			$results = $engine->search( $query, $filters, $per_page, $page );
		}

		// 1. Hybrid Vector Embeddings Reranking
		if ( ! empty( $settings['enable_vector_search'] ) && ! empty( $results['hits'] ) && class_exists( '\WPTS\Search\VectorSearch' ) ) {
			$query_vec = \WPTS\Search\VectorSearch::instance()->get_embedding( $query, true );
			if ( is_array( $query_vec ) ) {
				$weight = (float) ( $settings['vector_weight'] ?? 0.3 );
				$results['hits'] = \WPTS\Search\VectorSearch::instance()->rerank( $results['hits'], $query_vec, $weight );
			}
		}

		// 2. WooCommerce Product Enrichment (only for uncached product items)
		if ( ! empty( $results['hits'] ) ) {
			foreach ( $results['hits'] as &$hit ) {
				$pid = (int) ( $hit['post_id'] ?? 0 );
				$pt  = (string) ( $hit['post_type'] ?? '' );
				if ( 'product' === $pt && function_exists( 'wc_get_product' ) && empty( $hit['price_html'] ) ) {
					$product = wc_get_product( $pid );
					if ( $product ) {
						$hit['is_product']        = true;
						$hit['sku']               = (string) $product->get_sku();
						$hit['price_html']        = (string) $product->get_price_html();
						$hit['in_stock']          = $product->is_in_stock();
						$hit['on_sale']           = $product->is_on_sale();
						$hit['add_to_cart_url']   = esc_url( $product->add_to_cart_url() );
						$hit['add_to_cart_text']  = esc_html( $product->add_to_cart_text() );
						$hit['rating_count']      = (int) $product->get_rating_count();
						$hit['average_rating']    = (float) $product->get_average_rating();
					}
				}
			}
			unset( $hit );
		}

		$results = apply_filters( 'wpts_rest_search_results', $results, $query, $request );
		$results = wp_parse_args( $results, [ 'hits' => [], 'found' => 0, 'page' => $page ] );

		$driver   = method_exists( $engine, 'get_driver' ) ? $engine->get_driver() : 'mysql';
		$settings = \WPTS\Admin\Settings::get_all();

		$etag = '"' . md5( wp_json_encode( $results ) ) . '"';
		$if_none_match = $request->get_header( 'if_none_match' );
		if ( $if_none_match && trim( $if_none_match ) === $etag ) {
			return new \WP_REST_Response( null, 304, [
				'ETag'          => $etag,
				'Cache-Control' => 'public, max-age=60, stale-while-revalidate=30',
				'X-WPTS-Engine' => $driver,
			] );
		}

		// CDN & Browser Cache Headers
		$headers = [
			'X-WPTS-Engine' => $driver,
			'X-WPTS-Found'  => (string) ( $results['found'] ?? 0 ),
			'ETag'          => $etag,
		];

		if ( ! empty( $settings['cdn_cache_headers'] ) && ! is_user_logged_in() ) {
			$cdn_ttl = absint( $settings['cdn_cache_ttl'] ?? 300 );
			$headers['Cache-Control'] = "public, max-age={$cdn_ttl}, stale-while-revalidate=60";
			$headers['Vary']          = 'Accept-Encoding, Origin';
		} else {
			$headers['Cache-Control'] = 'public, max-age=15, stale-while-revalidate=15';
		}

		return $this->json( $results, $headers );
	}

	public function handle_track_click( \WP_REST_Request $request ): \WP_REST_Response {
		$params   = $request->get_json_params() ?: $request->get_params();
		$query    = trim( sanitize_text_field( (string) ( $params['q'] ?? $request->get_param( 'q' ) ?? '' ) ) );
		$post_id  = absint( $params['post_id'] ?? $request->get_param( 'post_id' ) ?? 0 );
		$position = absint( $params['position'] ?? $request->get_param( 'position' ) ?? 1 );

		if ( '' === $query && ! empty( $request->get_body() ) ) {
			$raw_json = json_decode( $request->get_body(), true );
			if ( is_array( $raw_json ) ) {
				$query    = trim( sanitize_text_field( (string) ( $raw_json['q'] ?? '' ) ) );
				$post_id  = absint( $raw_json['post_id'] ?? 0 );
				$position = absint( $raw_json['position'] ?? 1 );
			}
		}

		if ( '' !== $query && $post_id > 0 ) {
			\WPTS\Tracker::instance()->record_click( $query, $post_id, $position );
		}

		return $this->json( [ 'ok' => true ] );
	}

	public function handle_trending( \WP_REST_Request $request ): \WP_REST_Response {
		$limit    = absint( $request->get_param( 'limit' ) ?: 6 );
		$days     = absint( $request->get_param( 'days' ) ?: 7 );
		$trending = Trending::get_trending( $limit, $days );
		return $this->json( [ 'trending' => $trending ] );
	}

	public function handle_get_favorites(): \WP_REST_Response {
		$user_id   = get_current_user_id();
		$favorites = UserHistory::get_favorites( $user_id );
		$history   = UserHistory::get_history( $user_id, 8 );
		return $this->json( [ 'favorites' => $favorites, 'history' => $history ] );
	}

	public function handle_toggle_favorite( \WP_REST_Request $request ): \WP_REST_Response {
		$user_id = get_current_user_id();
		$query   = trim( sanitize_text_field( (string) $request->get_param( 'q' ) ) );
		$is_fav  = UserHistory::toggle_favorite( $user_id, $query );
		return $this->json( [ 'query' => $query, 'favorited' => $is_fav ] );
	}

	public function handle_suggest( \WP_REST_Request $request ): \WP_REST_Response {
		$q = trim( sanitize_text_field( (string) ( $request->get_param( 'q' ) ?? '' ) ) );
		if ( strlen( $q ) < 2 ) {
			return $this->json( [ 'suggestions' => [] ] );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'wpts_index';
		$like  = $wpdb->esc_like( $q ) . '%';

		$rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore
			"SELECT post_id, title, post_type FROM `{$table}` WHERE title LIKE %s ORDER BY title ASC LIMIT 5",
			$like
		), ARRAY_A );

		$suggestions = [];
		foreach ( (array) $rows as $row ) {
			$suggestions[] = [
				'post_id'   => (int) $row['post_id'],
				'title'     => esc_html( $row['title'] ),
				'post_type' => (string) $row['post_type'],
				'url'       => (string) get_permalink( (int) $row['post_id'] ),
			];
		}

		return $this->json( [ 'suggestions' => $suggestions ] );
	}

	public function handle_reindex( \WP_REST_Request $request ): \WP_REST_Response {
		\WPTS\Core::instance()->get_engine()->reindex_all();
		$count = \WPTS\Core::instance()->get_engine()->get_indexed_count();
		return $this->json( [ 'success' => true, 'indexed' => $count ] );
	}

	public function handle_health( \WP_REST_Request $request ): \WP_REST_Response {
		$count    = \WPTS\Core::instance()->get_engine()->get_indexed_count();
		$settings = \WPTS\Admin\Settings::get_all();
		$engine   = \WPTS\Core::instance()->get_engine();
		$status   = method_exists( $engine, 'status' ) ? $engine->status() : [];
		$pt       = \WPTS\Admin\Settings::get( 'post_types', [ 'post', 'page' ] );

		return $this->json( [
			'ok'            => true,
			'rest_url'      => rest_url( 'wpts/v1/search' ),
			'indexed_posts' => $count,
			'post_types'    => $pt,
			'engine'        => $status['driver'] ?? 'mysql',
			'wp_version'    => get_bloginfo( 'version' ),
			'php_version'   => PHP_VERSION,
		] );
	}

	private function is_multilingual_active(): bool {
		return defined( 'ICL_LANGUAGE_CODE' ) || function_exists( 'pll_current_language' );
	}

	private function json( array $data, array $headers = [] ): \WP_REST_Response {
		$response = new \WP_REST_Response( $data, 200 );
		foreach ( $headers as $key => $value ) {
			$response->header( $key, $value );
		}
		return $response;
	}

	private function search_args(): array {
		return [
			'q' => [
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
			],
			'post_type'    => [ 'type' => 'string',  'default' => '' ],
			'lang'         => [ 'type' => 'string',  'default' => '' ],
			'site_id'      => [ 'type' => 'integer', 'default' => 0 ],
			'stock_status' => [ 'type' => 'string',  'default' => '' ],
			'per_page'     => [ 'type' => 'integer', 'default' => 10, 'minimum' => 1, 'maximum' => 100 ],
			'page'         => [ 'type' => 'integer', 'default' => 1,  'minimum' => 1 ],
		];
	}
}
