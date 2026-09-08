<?php
namespace WPTS\Cache;

use WPTS\Search\EngineInterface;
use WPTS\Search\QueryParser;
use WPTS\Search\Highlighter;
use WPTS\Search\Spelling;
use WPTS\Search\Facets;
use WPTS\Universal\Utils;

defined( 'ABSPATH' ) || exit;

require_once WPTS_DIR . 'includes/search/interface-engine.php';

/**
 * MySQL Search Engine v1.0.0
 * Supports weighted field scoring, boolean clauses (AND/OR), exact quoted phrases ("..."),
 * term exclusions (-term), multi-post_type filtering, facets, and spelling suggestions.
 */
class MySQL implements EngineInterface {

	private string  $table;
	private ?string $last_error = null;

	public function __construct() {
		global $wpdb;
		$this->table = $wpdb->prefix . 'wpts_index';
	}

	public function search(
		string $query,
		array  $filters  = [],
		int    $per_page = 10,
		int    $page     = 1
	): array {
		global $wpdb;

		$raw_query = trim( $query );
		$offset    = max( 0, ( $page - 1 ) * $per_page );

		if ( '' === $raw_query ) {
			return [ 'hits' => [], 'found' => 0, 'page' => $page ];
		}

		// Ensure index table exists
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $this->table ) );
		if ( ! $exists ) {
			if ( class_exists( '\WPTS\Installer' ) ) {
				\WPTS\Installer::ensure_tables();
			}
			if ( class_exists( '\WPTS\Search\WPCoreFallback' ) ) {
				return \WPTS\Search\WPCoreFallback::search( $raw_query, $filters, $per_page, $page );
			}
			return [ 'hits' => [], 'found' => 0, 'page' => $page ];
		}

		// Parse query into phrases, boolean terms, and exclusions
		$parsed   = QueryParser::parse( $raw_query, true );
		$settings = \WPTS\Admin\Settings::get_all();

		// 1. Build WHERE Conditions
		$where_conditions = [];
		$where_vals       = [];

		// Exact phrases: must match exactly
		if ( ! empty( $parsed['phrases'] ) ) {
			foreach ( $parsed['phrases'] as $phrase ) {
				$p_like = '%' . $wpdb->esc_like( $phrase ) . '%';
				$where_conditions[] = '( title LIKE %s OR excerpt LIKE %s OR content LIKE %s )';
				$where_vals[]       = $p_like;
				$where_vals[]       = $p_like;
				$where_vals[]       = $p_like;
			}
		}

		// Positive terms with boolean operator (AND / OR)
		if ( ! empty( $parsed['expanded_terms'] ) ) {
			$term_clauses = [];
			foreach ( $parsed['expanded_terms'] as $term ) {
				$t_like = '%' . $wpdb->esc_like( $term ) . '%';
				$term_clauses[] = '( title LIKE %s OR excerpt LIKE %s OR content LIKE %s )';
				$where_vals[]   = $t_like;
				$where_vals[]   = $t_like;
				$where_vals[]   = $t_like;
			}

			if ( ! empty( $term_clauses ) ) {
				$glue = ( 'OR' === $parsed['boolean_mode'] ) ? ' OR ' : ' AND ';
				$where_conditions[] = '(' . implode( $glue, $term_clauses ) . ')';
			}
		}

		// Excluded terms: NOT LIKE
		if ( ! empty( $parsed['excluded_terms'] ) ) {
			foreach ( $parsed['excluded_terms'] as $ex_term ) {
				$ex_like = '%' . $wpdb->esc_like( $ex_term ) . '%';
				$where_conditions[] = '( title NOT LIKE %s AND excerpt NOT LIKE %s AND content NOT LIKE %s )';
				$where_vals[]       = $ex_like;
				$where_vals[]       = $ex_like;
				$where_vals[]       = $ex_like;
			}
		}

		// Fallback if no specific clauses parsed
		if ( empty( $where_conditions ) ) {
			$like = '%' . $wpdb->esc_like( $raw_query ) . '%';
			$where_conditions[] = '( title LIKE %s OR excerpt LIKE %s OR content LIKE %s )';
			$where_vals[]       = $like;
			$where_vals[]       = $like;
			$where_vals[]       = $like;
		}

		// 2. Filters
		// Post type (supports comma-separated string or array)
		if ( ! empty( $filters['post_type'] ) ) {
			$pts = is_array( $filters['post_type'] )
				? array_map( 'sanitize_key', $filters['post_type'] )
				: array_map( 'sanitize_key', explode( ',', (string) $filters['post_type'] ) );

			$pts = array_values( array_filter( $pts ) );
			if ( ! empty( $pts ) ) {
				$placeholders = implode( ',', array_fill( 0, count( $pts ), '%s' ) );
				$where_conditions[] = "post_type IN ({$placeholders})";
				foreach ( $pts as $pt ) {
					$where_vals[] = $pt;
				}
			}
		}

		if ( ! empty( $filters['lang'] ) ) {
			$where_conditions[] = 'lang = %s';
			$where_vals[]       = sanitize_text_field( $filters['lang'] );
		}

		if ( is_multisite() && ! empty( $filters['site_id'] ) ) {
			$where_conditions[] = 'site_id = %d';
			$where_vals[]       = absint( $filters['site_id'] );
		}

		[ $where_conditions, $where_vals ] = apply_filters(
			'wpts_mysql_where_clauses',
			[ $where_conditions, $where_vals ],
			$raw_query,
			$filters
		);

		$where_sql = implode( ' AND ', $where_conditions );

		// 3. Weighted Relevance Scoring & Fetch
		$w_title = (int) ( $settings['weight_title'] ?? 10 );
		$w_exc   = (int) ( $settings['weight_excerpt'] ?? 5 );
		$w_cont  = (int) ( $settings['weight_content'] ?? 1 );

		$main_like = '%' . $wpdb->esc_like( $raw_query ) . '%';
		$has_ft    = $this->has_fulltext_index();

		$score_clause = "( ( CASE WHEN title LIKE %s THEN 100 ELSE 0 END ) * %d +
						   ( CASE WHEN excerpt LIKE %s THEN 50 ELSE 0 END ) * %d +
						   ( CASE WHEN content LIKE %s THEN 10 ELSE 0 END ) * %d" .
						( $has_ft ? " + IFNULL( MATCH(title,content,excerpt) AGAINST (%s IN NATURAL LANGUAGE MODE) * 50, 0 ) )" : " )" );

		$score_vals = [
			$main_like, $w_title,
			$main_like, $w_exc,
			$main_like, $w_cont,
		];
		if ( $has_ft ) {
			$score_vals[] = $raw_query;
		}

		$all_query_params = array_merge( $score_vals, $where_vals, [ $per_page, $offset ] );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB
		$fetch_query = "SELECT post_id, post_type, title, excerpt, content, meta_json,
					({$score_clause}) AS _score
			 FROM `{$this->table}`
			 WHERE {$where_sql}
			 ORDER BY _score DESC, indexed_at DESC
			 LIMIT %d OFFSET %d";
		$fetch_sql   = $wpdb->prepare( $fetch_query, ...$all_query_params );

		$rows      = (array) $wpdb->get_results( $fetch_sql, ARRAY_A );
		$row_count = count( $rows );

		// If page 1 and fewer results than per_page, we know exact count without secondary query
		if ( 1 === $page && $row_count < $per_page ) {
			$found = $row_count;
		} else {
			$count_query = "SELECT COUNT(*) FROM `{$this->table}` WHERE {$where_sql}";
			$count_sql   = $wpdb->prepare( $count_query, ...$where_vals );
			$found       = (int) $wpdb->get_var( $count_sql );
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB

		if ( 0 === $found ) {
			$direct_res = $this->search_direct_posts( $raw_query, $filters, $per_page, $page );
			if ( ! empty( $direct_res['hits'] ) ) {
				return $direct_res;
			}

			if ( class_exists( '\WPTS\Search\WPCoreFallback' ) ) {
				$core_res = \WPTS\Search\WPCoreFallback::search( $raw_query, $filters, $per_page, $page );
				if ( ! empty( $core_res['hits'] ) ) {
					return $core_res;
				}
			}

			$did_you_mean = Spelling::suggest( $raw_query );
			return [
				'hits'         => [],
				'found'        => 0,
				'page'         => $page,
				'facets'       => [ 'post_types' => [], 'taxonomies' => [], 'dates' => [] ],
				'did_you_mean' => $did_you_mean,
			];
		}

		// 5. Format Hits & Facets
		$hits     = [];
		$post_ids = [];

		foreach ( (array) $rows as $row ) {
			$post_id    = (int) $row['post_id'];
			$post_ids[] = $post_id;
			$raw_title  = (string) ( $row['title'] ?? '' );
			$raw_exc    = (string) ( $row['excerpt'] ?? '' );
			$raw_cont   = (string) ( $row['content'] ?? '' );

			if ( ! empty( $settings['highlight_results'] ) ) {
				$title   = Highlighter::highlight( $raw_title, $raw_query );
				$excerpt = Highlighter::highlight_snippet( $raw_cont, $raw_exc, $raw_query );
			} else {
				$title   = esc_html( $raw_title );
				$excerpt = esc_html( $raw_exc );
			}

			$meta = [];
			if ( ! empty( $row['meta_json'] ) ) {
				$meta = json_decode( (string) $row['meta_json'], true ) ?: [];
			}

			$doc_url = 'attachment' === ( $row['post_type'] ?? '' )
				? ( (string) ( wp_get_attachment_url( $post_id ) ?: get_permalink( $post_id ) ) )
				: (string) get_permalink( $post_id );

			$hits[] = apply_filters( 'wpts_result_hit', [
				'post_id'        => $post_id,
				'title'          => $title,
				'excerpt'        => $excerpt,
				'post_type'      => (string) ( $row['post_type'] ?? 'post' ),
				'url'            => $doc_url,
				'thumbnail_url'  => (string) ( $meta['thumbnail_url'] ?? '' ),
				'date'           => (int) ( $meta['date'] ?? 0 ),
				'date_formatted' => ! empty( $meta['date'] ) ? date_i18n( get_option( 'date_format' ), (int) $meta['date'] ) : '',
				'sku'            => (string) ( $meta['sku'] ?? '' ),
				'price'          => (float) ( $meta['price'] ?? 0.0 ),
				'stock_status'   => (string) ( $meta['stock_status'] ?? 'instock' ),
			], $raw_query );
		}

		// Build facets for matched results
		$facets = Facets::build_facets( $post_ids );

		return [
			'hits'         => $hits,
			'found'        => $found,
			'page'         => $page,
			'facets'       => $facets,
			'did_you_mean' => null,
		];
	}

	public function upsert( array $document ): bool {
		global $wpdb;

		$this->last_error = null;
		$post_id = (int) ( $document['id'] ?? 0 );
		if ( $post_id <= 0 ) {
			$this->last_error = __( 'Invalid post ID provided for MySQL index.', 'turbo-search' );
			return false;
		}

		\WPTS\Installer::ensure_tables();
		$settings = \WPTS\Admin\Settings::get_all();
		$document = apply_filters( 'wpts_before_index_document', $document );

		$extra = [
			'thumbnail_url' => (string) ( $document['thumbnail_url'] ?? '' ),
			'date'          => (int)    ( $document['date']          ?? 0 ),
			'author_id'     => (string) ( $document['author_id']     ?? '' ),
			'sku'           => (string) ( $document['sku']           ?? '' ),
			'price'         => (float)  ( $document['price']         ?? 0.0 ),
			'stock_status'  => (string) ( $document['stock_status']  ?? 'instock' ),
		];

		if ( ! empty( $settings['search_in_meta'] ) ) {
			$extra['_meta'] = get_post_meta( $post_id );
		}

		$meta_json = wp_json_encode( $extra ) ?: null;

		$title = trim( (string) ( $document['title'] ?? '' ) );
		if ( '' === $title ) {
			$title = (string) ( $document['slug'] ?? '' );
			if ( '' === $title ) {
				$title = sprintf(
					/* translators: %d: Post ID */
					__( 'Post #%d', 'turbo-search' ),
					$post_id
				);
			}
		}

		$data = [
			'post_id'    => $post_id,
			'post_type'  => (string) ( $document['post_type'] ?? 'post' ),
			'lang'       => (string) ( $document['lang']      ?? '' ),
			'site_id'    => (int)    ( $document['site_id']   ?? get_current_blog_id() ),
			'title'      => $title,
			'content'    => Utils::clean_text( (string) ( $document['content'] ?? '' ) ),
			'excerpt'    => (string) ( $document['excerpt']   ?? '' ),
			'meta_json'  => $meta_json,
			'indexed_at' => current_time( 'mysql' ),
		];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$result  = $wpdb->replace(
			$this->table,
			$data,
			[ '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s' ]
		);
		$success = false !== $result;

		if ( ! $success ) {
			\WPTS\Installer::ensure_tables();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$result  = $wpdb->replace(
				$this->table,
				$data,
				[ '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s' ]
			);
			$success = false !== $result;
			if ( ! $success ) {
				$this->last_error = $wpdb->last_error ?: __( 'MySQL replace query failed.', 'turbo-search' );
			}
		}

		do_action( 'wpts_after_index_document', $document, $success, 'mysql' );
		return $success;
	}

	public function delete( int $post_id ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return false !== $wpdb->delete(
			$this->table,
			[ 'post_id' => $post_id, 'site_id' => get_current_blog_id() ],
			[ '%d', '%d' ]
		);
	}

	public function reindex_all(): void {
		\WPTS\Installer::ensure_tables();
		$paged = 1;
		$GLOBALS['wpts_reindexing'] = true;

		do {
			$result = $this->reindex_chunk( $paged, 100 );
			$paged++;
		} while ( ! $result['done'] );

		unset( $GLOBALS['wpts_reindexing'] );
		Spelling::flush_vocabulary();

		global $wpdb;
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$this->table}`" ); // phpcs:ignore
		do_action( 'wpts_reindex_complete', $count, 'mysql' );
	}

	public function upsert_bulk( array $documents ): bool {
		global $wpdb;
		if ( empty( $documents ) ) {
			return true;
		}

		\WPTS\Installer::ensure_tables();
		$settings = \WPTS\Admin\Settings::get_all();
		$now      = current_time( 'mysql' );

		$placeholders = [];
		$values       = [];

		foreach ( $documents as $document ) {
			$document = apply_filters( 'wpts_before_index_document', $document );

			$extra = [
				'thumbnail_url' => (string) ( $document['thumbnail_url'] ?? '' ),
				'date'          => (int)    ( $document['date']          ?? 0 ),
				'author_id'     => (string) ( $document['author_id']     ?? '' ),
				'sku'           => (string) ( $document['sku']           ?? '' ),
				'price'         => (float)  ( $document['price']         ?? 0.0 ),
				'stock_status'  => (string) ( $document['stock_status']  ?? 'instock' ),
			];

			if ( ! empty( $settings['search_in_meta'] ) ) {
				$extra['_meta'] = get_post_meta( (int) $document['id'] );
			}

			$meta_json = wp_json_encode( $extra ) ?: null;

			$doc_id = (int) ( $document['id'] ?? 0 );
			if ( $doc_id <= 0 ) {
				continue;
			}

			$title = trim( (string) ( $document['title'] ?? '' ) );
			if ( '' === $title ) {
				$title = (string) ( $document['slug'] ?? '' );
				if ( '' === $title ) {
					$title = sprintf(
						/* translators: %d: Post ID */
						__( 'Post #%d', 'turbo-search' ),
						$doc_id
					);
				}
			}

			$placeholders[] = '(%d, %s, %s, %d, %s, %s, %s, %s, %s)';
			$values[] = $doc_id;
			$values[] = (string) ( $document['post_type'] ?? 'post' );
			$values[] = (string) ( $document['lang']      ?? '' );
			$values[] = (int)    ( $document['site_id']   ?? get_current_blog_id() );
			$values[] = $title;
			$values[] = Utils::clean_text( (string) ( $document['content'] ?? '' ) );
			$values[] = (string) ( $document['excerpt']   ?? '' );
			$values[] = $meta_json;
			$values[] = $now;
		}

		if ( empty( $placeholders ) ) {
			return true;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB
		$sql = "REPLACE INTO `{$this->table}` (`post_id`, `post_type`, `lang`, `site_id`, `title`, `content`, `excerpt`, `meta_json`, `indexed_at`) VALUES " . implode( ', ', $placeholders );
		$prepared = $wpdb->prepare( $sql, ...$values );
		$res      = $wpdb->query( $prepared );
		$success  = false !== $res;

		if ( ! $success ) {
			\WPTS\Installer::ensure_tables();
			$res     = $wpdb->query( $prepared );
			$success = false !== $res;
			if ( ! $success ) {
				$this->last_error = $wpdb->last_error ?: __( 'MySQL bulk replace query failed.', 'turbo-search' );
			}
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB

		do_action( 'wpts_after_index_bulk', $documents, $success, 'mysql' );
		return $success;
	}

	public function reindex_chunk( int $paged = 1, int $batch = 50 ): array {
		\WPTS\Installer::ensure_tables();

		$post_types = \WPTS\Admin\Settings::get( 'post_types', [ 'post', 'page' ] );
		$post_types = ( is_array( $post_types ) && ! empty( $post_types ) )
					  ? array_values( $post_types )
					  : [ 'post', 'page' ];

		if ( ! empty( \WPTS\Admin\Settings::get( 'index_attachments' ) ) && ! in_array( 'attachment', $post_types, true ) ) {
			$post_types[] = 'attachment';
		}

		$core = \WPTS\Core::instance();

		if ( 1 === $paged ) {
			$GLOBALS['wpts_reindexing'] = true;
		}

		$post_statuses = in_array( 'attachment', $post_types, true ) ? [ 'publish', 'inherit' ] : [ 'publish' ];

		$wp_query = new \WP_Query( [
			'post_type'              => $post_types,
			'post_status'            => $post_statuses,
			'posts_per_page'         => $batch,
			'paged'                  => $paged,
			'no_found_rows'          => false,
			'update_post_term_cache' => true,
			'update_post_meta_cache' => true,
			'fields'                 => 'ids',
			'orderby'                => 'ID',
			'order'                  => 'ASC',
		] );

		$max_pages = (int) $wp_query->max_num_pages;
		$total     = (int) $wp_query->found_posts;
		$docs      = [];

		foreach ( $wp_query->posts as $post_id ) {
			$post = get_post( (int) $post_id );
			if ( $post instanceof \WP_Post && in_array( $post->post_status, $post_statuses, true ) ) {
				$doc = $core->build_document( $post );
				if ( $doc ) {
					$docs[] = $doc;
				}
			}
		}

		$processed = count( $docs );
		if ( ! empty( $docs ) ) {
			$this->upsert_bulk( $docs );
		}

		wp_reset_postdata();
		$done = empty( $wp_query->posts ) || $paged >= $max_pages;

		if ( $done ) {
			unset( $GLOBALS['wpts_reindexing'] );
			Spelling::flush_vocabulary();
			global $wpdb;
			$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$this->table}`" ); // phpcs:ignore
			do_action( 'wpts_reindex_complete', $count, 'mysql' );
		}

		return [
			'done'          => $done,
			'processed'     => $processed,
			'total'         => $total,
			'page'          => $paged,
			'max_pages'     => max( 1, $max_pages ),
			'indexed_count' => $this->get_indexed_count(),
		];
	}

	public function get_driver(): string {
		return 'mysql';
	}

	public function get_indexed_count(): int {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $this->table ) );
		if ( ! $exists ) {
			return 0;
		}
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$this->table}`" );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB
		return $count;
	}

	public function flush_index(): bool {
		global $wpdb;
		\WPTS\Installer::ensure_tables();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB
		$res = $wpdb->query( "TRUNCATE TABLE `{$this->table}`" );
		if ( false === $res ) {
			$res = $wpdb->query( $wpdb->prepare( "DELETE FROM `{$this->table}` WHERE site_id = %d", get_current_blog_id() ) );
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB
		Spelling::flush_vocabulary();
		do_action( 'wpts_index_flushed', 'mysql' );
		return false !== $res;
	}

	private function has_fulltext_index(): bool {
		global $wpdb;
		static $has_ft = null;
		if ( null !== $has_ft ) {
			return $has_ft;
		}
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SHOW INDEX FROM `{$this->table}` WHERE Index_type = 'FULLTEXT'" ),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB
		$has_ft = ! empty( $rows );
		return $has_ft;
	}

	/**
	 * Direct fallback search querying WordPress wp_posts table.
	 * Guarantees results are returned immediately even when wpts_index is unpopulated or syncing.
	 */
	public function search_direct_posts( string $raw_query, array $filters = [], int $per_page = 10, int $page = 1 ): array {
		global $wpdb;

		$settings = \WPTS\Admin\Settings::get_all();
		$offset   = ( $page - 1 ) * $per_page;
		$like     = '%' . $wpdb->esc_like( $raw_query ) . '%';

		$where_conditions = [
			"post_status IN ('publish', 'inherit')",
		];
		$where_vals = [];

		// Words / tokens
		$tokens = preg_split( '/\s+/u', trim( $raw_query ), -1, PREG_SPLIT_NO_EMPTY ) ?: [ $raw_query ];
		$token_clauses = [];
		foreach ( $tokens as $t ) {
			$t_like = '%' . $wpdb->esc_like( $t ) . '%';
			$token_clauses[] = '(post_title LIKE %s OR post_content LIKE %s OR post_excerpt LIKE %s)';
			$where_vals[]    = $t_like;
			$where_vals[]    = $t_like;
			$where_vals[]    = $t_like;
		}
		if ( ! empty( $token_clauses ) ) {
			$where_conditions[] = '(' . implode( ' OR ', $token_clauses ) . ')';
		}

		// Post types
		if ( ! empty( $filters['post_type'] ) ) {
			$pts = is_array( $filters['post_type'] ) ? $filters['post_type'] : explode( ',', (string) $filters['post_type'] );
			$pts = array_values( array_filter( array_map( 'sanitize_key', $pts ) ) );
			if ( ! empty( $pts ) ) {
				$placeholders = implode( ',', array_fill( 0, count( $pts ), '%s' ) );
				$where_conditions[] = "post_type IN ({$placeholders})";
				foreach ( $pts as $pt ) {
					$where_vals[] = $pt;
				}
			}
		} else {
			$where_conditions[] = "post_type NOT IN ('revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache', 'user_request', 'wp_block', 'wp_template', 'wp_template_part', 'wp_global_styles')";
		}

		$where_sql = implode( ' AND ', $where_conditions );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB
		$count_query = "SELECT COUNT(*) FROM `{$wpdb->posts}` WHERE {$where_sql}";
		$count_sql   = $wpdb->prepare( $count_query, ...$where_vals );
		$found       = (int) $wpdb->get_var( $count_sql );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB

		if ( 0 === $found ) {
			return [
				'hits'         => [],
				'found'        => 0,
				'page'         => $page,
				'facets'       => [ 'post_types' => [], 'taxonomies' => [], 'dates' => [] ],
				'did_you_mean' => Spelling::suggest( $raw_query ),
			];
		}

		$score_vals = [ $like, $like ];
		$all_params = array_merge( $score_vals, $where_vals, [ $per_page, $offset ] );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB
		$fetch_query = "SELECT ID, post_title, post_excerpt, post_content, post_type, post_date
			 FROM `{$wpdb->posts}`
			 WHERE {$where_sql}
			 ORDER BY (CASE WHEN post_title LIKE %s THEN 100 WHEN post_title LIKE %s THEN 50 ELSE 10 END) DESC, post_date DESC
			 LIMIT %d OFFSET %d";
		$fetch_sql   = $wpdb->prepare( $fetch_query, ...$all_params );

		$posts = $wpdb->get_results( $fetch_sql, ARRAY_A );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB
		$hits  = [];
		$pids  = [];

		foreach ( (array) $posts as $p ) {
			$post_id   = (int) $p['ID'];
			$pids[]    = $post_id;
			$raw_title = (string) $p['post_title'];
			$raw_exc   = (string) $p['post_excerpt'];
			$raw_cont  = (string) $p['post_content'];

			if ( '' === trim( $raw_exc ) ) {
				$raw_exc = wp_trim_words( wp_strip_all_tags( $raw_cont ), 24, '…' );
			}

			if ( ! empty( $settings['highlight_results'] ) ) {
				$title   = Highlighter::highlight( $raw_title, $raw_query );
				$excerpt = Highlighter::highlight_snippet( $raw_cont, $raw_exc, $raw_query );
			} else {
				$title   = esc_html( $raw_title );
				$excerpt = esc_html( $raw_exc );
			}

			$thumb_url = (string) get_the_post_thumbnail_url( $post_id, 'thumbnail' );

			$hit = [
				'post_id'        => $post_id,
				'title'          => $title,
				'excerpt'        => $excerpt,
				'post_type'      => (string) $p['post_type'],
				'url'            => (string) get_permalink( $post_id ),
				'thumbnail_url'  => $thumb_url,
				'date'           => strtotime( (string) $p['post_date'] ),
				'date_formatted' => get_the_date( '', $post_id ),
			];

			// Enrich product if WooCommerce
			if ( 'product' === $p['post_type'] && function_exists( 'wc_get_product' ) ) {
				$product = wc_get_product( $post_id );
				if ( $product ) {
					$hit['is_product']       = true;
					$hit['sku']              = (string) $product->get_sku();
					$hit['price_html']       = (string) $product->get_price_html();
					$hit['in_stock']         = $product->is_in_stock();
					$hit['on_sale']          = $product->is_on_sale();
					$hit['add_to_cart_url']  = esc_url( $product->add_to_cart_url() );
					$hit['add_to_cart_text'] = esc_html( $product->add_to_cart_text() );
				}
			}

			$hits[] = apply_filters( 'wpts_result_hit', $hit, $raw_query );

			// Auto-index into wpts_index for fast future lookup
			$post_obj = get_post( $post_id );
			if ( $post_obj && class_exists( '\WPTS\Core' ) ) {
				\WPTS\Core::instance()->on_save_post( $post_id, $post_obj, true );
			}
		}

		return [
			'hits'         => $hits,
			'found'        => $found,
			'page'         => $page,
			'facets'       => Facets::build_facets( $pids ),
			'did_you_mean' => null,
		];
	}

	public function get_last_error(): ?string {
		return $this->last_error;
	}

	public function is_configured(): bool {
		return true;
	}
}
