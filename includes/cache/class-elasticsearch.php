<?php
namespace WPTS\Cache;

use WPTS\Search\EngineInterface;
use WPTS\Search\QueryParser;
use WPTS\Search\Highlighter;
use WPTS\Search\Spelling;
use WPTS\Universal\Utils;

defined( 'ABSPATH' ) || exit;

require_once WPTS_DIR . 'includes/search/interface-engine.php';

/**
 * Elasticsearch / OpenSearch search engine adapter.
 * Uses WordPress HTTP API (zero external SDKs).
 */
class Elasticsearch implements EngineInterface {

	private string  $base_url;
	private string  $index_name;
	private string  $username;
	private string  $password;
	private string  $api_key;
	private bool    $index_ready = false;
	private ?string $last_error  = null;

	public function __construct( array $settings ) {
		$raw_host = trim( (string) ( $settings['elasticsearch_host'] ?? 'localhost' ) );
		$proto    = $settings['elasticsearch_protocol'] ?? 'http';
		$port     = $settings['elasticsearch_port']     ?? '9200';

		if ( preg_match( '#^https?://#i', $raw_host ) ) {
			$parsed = wp_parse_url( $raw_host );
			$proto  = $parsed['scheme'] ?? $proto;
			$host   = $parsed['host'] ?? $raw_host;
			if ( ! empty( $parsed['port'] ) ) {
				$port = (string) $parsed['port'];
			}
		} else {
			$host = $raw_host;
			if ( strpos( $host, ':' ) !== false ) {
				$parts = explode( ':', $host, 2 );
				$host  = $parts[0];
				$port  = $parts[1];
			}
		}

		$host             = rtrim( $host, '/' );
		$this->index_name = ! empty( $settings['elasticsearch_index'] ) ? trim( (string) $settings['elasticsearch_index'] ) : 'wpts_posts';
		$this->username   = $settings['elasticsearch_username'] ?? '';
		$this->password   = $settings['elasticsearch_password'] ?? '';
		$this->api_key    = $settings['elasticsearch_api_key'] ?? '';
		$this->base_url   = "{$proto}://{$host}:{$port}";
	}

	public function search( string $query, array $filters = [], int $per_page = 10, int $page = 1 ): array {
		if ( '' === trim( $query ) ) {
			return [ 'hits' => [], 'found' => 0, 'page' => $page ];
		}

		if ( ! $this->ensure_index() ) {
			$fallback = new MySQL();
			return $fallback->search( $query, $filters, $per_page, $page );
		}

		$parsed = QueryParser::parse( $query, true );
		$offset = max( 0, ( $page - 1 ) * $per_page );

		$settings = \WPTS\Admin\Settings::get_all();
		$w_title  = (float) ( $settings['weight_title'] ?? 10 );
		$w_exc    = (float) ( $settings['weight_excerpt'] ?? 5 );
		$w_cont   = (float) ( $settings['weight_content'] ?? 1 );
		$w_tax    = (float) ( $settings['weight_taxonomies'] ?? 4 );
		$w_sku    = 8.0;

		// Build Elasticsearch Query DSL
		$must_clauses     = [];
		$filter_clauses   = [];
		$must_not_clauses = [];

		// Main search clause
		if ( ! empty( $parsed['phrases'] ) ) {
			foreach ( $parsed['phrases'] as $phrase ) {
				$must_clauses[] = [
					'multi_match' => [
						'query'  => $phrase,
						'type'   => 'phrase',
						'fields' => [ "title^{$w_title}", "excerpt^{$w_exc}", "content^{$w_cont}", "taxonomies^{$w_tax}" ],
					],
				];
			}
		}

		if ( ! empty( $parsed['expanded_terms'] ) ) {
			$terms_str = implode( ' ', $parsed['expanded_terms'] );
			$must_clauses[] = [
				'multi_match' => [
					'query'     => $terms_str,
					'fields'    => [ "title^{$w_title}", "excerpt^{$w_exc}", "content^{$w_cont}", "taxonomies^{$w_tax}", "sku^{$w_sku}" ],
					'operator'  => 'OR' === $parsed['boolean_mode'] ? 'or' : 'and',
					'fuzziness' => 'AUTO',
				],
			];
		}

		// Filters
		if ( ! empty( $filters['post_type'] ) ) {
			$pts = is_array( $filters['post_type'] ) ? $filters['post_type'] : explode( ',', (string) $filters['post_type'] );
			$pts = array_map( 'sanitize_key', $pts );
			$filter_clauses[] = [ 'terms' => [ 'post_type' => $pts ] ];
		}

		if ( ! empty( $filters['lang'] ) ) {
			$filter_clauses[] = [ 'term' => [ 'lang' => (string) $filters['lang'] ] ];
		}

		if ( is_multisite() && ! empty( $filters['site_id'] ) ) {
			$filter_clauses[] = [ 'term' => [ 'site_id' => (int) $filters['site_id'] ] ];
		}

		if ( ! empty( $filters['stock_status'] ) ) {
			$filter_clauses[] = [ 'term' => [ 'stock_status' => (string) $filters['stock_status'] ] ];
		}

		// Excluded terms
		if ( ! empty( $parsed['excluded_terms'] ) ) {
			foreach ( $parsed['excluded_terms'] as $ex_term ) {
				$must_not_clauses[] = [
					'multi_match' => [
						'query'  => $ex_term,
						'fields' => [ 'title', 'content', 'excerpt' ],
					],
				];
			}
		}

		$dsl = [
			'from'  => $offset,
			'size'  => $per_page,
			'query' => [
				'bool' => array_filter( [
					'must'     => $must_clauses,
					'filter'   => $filter_clauses,
					'must_not' => $must_not_clauses,
				] ),
			],
			'highlight' => [
				'pre_tags'  => [ '<mark class="wpts-mark">' ],
				'post_tags' => [ '</mark>' ],
				'fields'    => [
					'title'   => new \stdClass(),
					'excerpt' => new \stdClass(),
					'content' => new \stdClass(),
				],
			],
		];

		$response = $this->request( 'POST', "/{$this->index_name}/_search", $dsl );
		if ( is_wp_error( $response ) ) {
			$fallback = new MySQL();
			return $fallback->search( $query, $filters, $per_page, $page );
		}

		$formatted = $this->format_results( $response, $page, $query );
		if ( empty( $formatted['hits'] ) && 0 === ( $formatted['found'] ?? 0 ) ) {
			$fallback     = new MySQL();
			$fallback_res = $fallback->search( $query, $filters, $per_page, $page );
			if ( ! empty( $fallback_res['hits'] ) ) {
				return $fallback_res;
			}
		}

		return $formatted;
	}

	public function upsert( array $document ): bool {
		$this->last_error = null;
		$post_id = (int) ( $document['id'] ?? 0 );
		if ( $post_id <= 0 ) {
			$this->last_error = __( 'Missing or invalid document ID.', 'turbo-search' );
			return false;
		}

		if ( ! $this->ensure_index() ) {
			return false;
		}

		$title = trim( (string) ( $document['title'] ?? '' ) );
		if ( '' === $title ) {
			$title = (string) ( $document['slug'] ?? '' );
			if ( '' === $title ) {
				$title = sprintf( 'Post #%d', $post_id );
			}
		}

		$doc = [
			'post_id'      => $post_id,
			'title'        => $title,
			'content'      => (string) ( $document['content'] ?? '' ),
			'excerpt'      => (string) ( $document['excerpt'] ?? '' ),
			'slug'         => (string) ( $document['slug'] ?? '' ),
			'post_type'    => (string) ( $document['post_type'] ?? 'post' ),
			'author_id'    => (string) ( $document['author_id'] ?? '' ),
			'date'         => (int) ( $document['date'] ?? time() ),
			'lang'         => (string) ( $document['lang'] ?? '' ),
			'site_id'      => (int) ( $document['site_id'] ?? get_current_blog_id() ),
			'sku'          => (string) ( $document['sku'] ?? '' ),
			'price'        => (float) ( $document['price'] ?? 0.0 ),
			'stock_status' => (string) ( $document['stock_status'] ?? 'instock' ),
			'taxonomies'   => (string) ( $document['taxonomies'] ?? '' ),
			'meta_json'    => (string) ( $document['thumbnail_url'] ?? '' ),
		];

		$doc_id   = $post_id . '_' . ( $document['site_id'] ?? get_current_blog_id() );
		$response = $this->request( 'PUT', "/{$this->index_name}/_doc/{$doc_id}", $doc );

		$success = ! is_wp_error( $response );
		if ( ! $success && is_wp_error( $response ) ) {
			$this->last_error = $response->get_error_message();
		}

		do_action( 'wpts_after_index_document', $document, $success, 'elasticsearch' );
		return $success;
	}

	public function upsert_bulk( array $documents ): bool {
		$this->last_error = null;
		if ( empty( $documents ) || ! $this->ensure_index() ) {
			return false;
		}

		$payload = '';
		foreach ( $documents as $document ) {
			$post_id = (int) ( $document['id'] ?? 0 );
			if ( $post_id <= 0 ) continue;

			$document = apply_filters( 'wpts_before_index_document', $document );

			$title = trim( (string) ( $document['title'] ?? '' ) );
			if ( '' === $title ) {
				$title = (string) ( $document['slug'] ?? '' );
				if ( '' === $title ) {
					$title = sprintf( 'Post #%d', $post_id );
				}
			}

			$doc = [
				'post_id'      => $post_id,
				'title'        => $title,
				'content'      => (string) ( $document['content'] ?? '' ),
				'excerpt'      => (string) ( $document['excerpt'] ?? '' ),
				'slug'         => (string) ( $document['slug'] ?? '' ),
				'post_type'    => (string) ( $document['post_type'] ?? 'post' ),
				'author_id'    => (string) ( $document['author_id'] ?? '' ),
				'date'         => (int) ( $document['date'] ?? time() ),
				'lang'         => (string) ( $document['lang'] ?? '' ),
				'site_id'      => (int) ( $document['site_id'] ?? get_current_blog_id() ),
				'sku'          => (string) ( $document['sku'] ?? '' ),
				'price'        => (float) ( $document['price'] ?? 0.0 ),
				'stock_status' => (string) ( $document['stock_status'] ?? 'instock' ),
				'taxonomies'   => (string) ( $document['taxonomies'] ?? '' ),
				'meta_json'    => (string) ( $document['thumbnail_url'] ?? '' ),
			];

			$doc_id = $post_id . '_' . ( $doc['site_id'] ?? get_current_blog_id() );
			$header = wp_json_encode( [ 'index' => [ '_index' => $this->index_name, '_id' => $doc_id ] ] );
			$body   = wp_json_encode( $doc );
			$payload .= $header . "\n" . $body . "\n";
		}

		if ( empty( $payload ) ) return true;

		$url  = $this->base_url . '/_bulk';
		$args = [
			'method'    => 'POST',
			'headers'   => [
				'Content-Type' => 'application/x-ndjson',
				'Accept'       => 'application/json',
			],
			'body'      => $payload,
			'timeout'   => 15,
			'sslverify' => false,
		];
		if ( $this->api_key ) {
			$args['headers']['Authorization'] = 'ApiKey ' . $this->api_key;
		} elseif ( $this->username && $this->password ) {
			$args['headers']['Authorization'] = 'Basic ' . base64_encode( "{$this->username}:{$this->password}" );
		}

		$resp    = wp_remote_request( $url, $args );
		$success = ! is_wp_error( $resp );
		if ( ! $success && is_wp_error( $resp ) ) {
			$this->last_error = $resp->get_error_message();
		}
		do_action( 'wpts_after_index_bulk', $documents, $success, 'elasticsearch' );
		return $success;
	}

	public function delete( int $post_id ): bool {
		$doc_id   = $post_id . '_' . get_current_blog_id();
		$response = $this->request( 'DELETE', "/{$this->index_name}/_doc/{$doc_id}" );
		return ! is_wp_error( $response );
	}

	public function reindex_all(): void {
		$paged = 1;
		$GLOBALS['wpts_reindexing'] = true;
		do {
			$res = $this->reindex_chunk( $paged, 100 );
			$paged++;
		} while ( ! $res['done'] );
		unset( $GLOBALS['wpts_reindexing'] );
	}

	public function reindex_chunk( int $paged = 1, int $batch = 50 ): array {
		$post_types = (array) \WPTS\Admin\Settings::get( 'post_types', [ 'post', 'page' ] );

		if ( ! empty( \WPTS\Admin\Settings::get( 'index_attachments' ) ) && ! in_array( 'attachment', $post_types, true ) ) {
			$post_types[] = 'attachment';
		}

		if ( 1 === $paged ) {
			$GLOBALS['wpts_reindexing'] = true;
		}

		$post_statuses = in_array( 'attachment', $post_types, true ) ? [ 'publish', 'inherit' ] : [ 'publish' ];

		$query = new \WP_Query( [
			'post_type'              => $post_types,
			'post_status'            => $post_statuses,
			'posts_per_page'         => $batch,
			'paged'                  => $paged,
			'fields'                 => 'ids',
			'no_found_rows'          => false,
			'update_post_meta_cache' => true,
		] );

		$max_pages = (int) $query->max_num_pages;
		$total     = (int) $query->found_posts;
		$docs      = [];
		$core      = \WPTS\Core::instance();

		foreach ( $query->posts as $pid ) {
			$post = get_post( (int) $pid );
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

		$done = empty( $query->posts ) || $paged >= $max_pages;
		if ( $done ) {
			unset( $GLOBALS['wpts_reindexing'] );
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
		return 'elasticsearch';
	}

	public function get_indexed_count(): int {
		$resp = $this->request( 'GET', "/{$this->index_name}/_count" );
		if ( is_wp_error( $resp ) || empty( $resp['count'] ) ) {
			return 0;
		}
		return (int) $resp['count'];
	}

	public function get_status(): array {
		$health = $this->request( 'GET', '/_cluster/health' );
		if ( is_wp_error( $health ) ) {
			return [
				'ok'            => false,
				'reachable'     => false,
				'error'         => $health->get_error_message(),
				'num_documents' => 0,
			];
		}

		return [
			'ok'            => true,
			'reachable'     => true,
			'cluster_name'  => $health['cluster_name'] ?? 'elasticsearch',
			'status'        => $health['status'] ?? 'green',
			'index'         => $this->index_name,
			'num_documents' => $this->get_indexed_count(),
		];
	}

	public function flush_index(): bool {
		$this->request( 'DELETE', "/{$this->index_name}" );
		$this->index_ready = false;
		$ok = $this->ensure_index();
		do_action( 'wpts_index_flushed', 'elasticsearch' );
		return $ok;
	}

	private function ensure_index(): bool {
		if ( $this->index_ready ) {
			return true;
		}

		// Check index exists
		$check = $this->request( 'HEAD', "/{$this->index_name}" );
		if ( ! is_wp_error( $check ) ) {
			$this->index_ready = true;
			return true;
		}

		// Create index with mapping
		$mapping = [
			'mappings' => [
				'properties' => [
					'post_id'      => [ 'type' => 'long' ],
					'title'        => [ 'type' => 'text', 'analyzer' => 'standard' ],
					'content'      => [ 'type' => 'text', 'analyzer' => 'standard' ],
					'excerpt'      => [ 'type' => 'text', 'analyzer' => 'standard' ],
					'taxonomies'   => [ 'type' => 'text', 'analyzer' => 'standard' ],
					'slug'         => [ 'type' => 'keyword' ],
					'post_type'    => [ 'type' => 'keyword' ],
					'sku'          => [ 'type' => 'keyword' ],
					'price'        => [ 'type' => 'float' ],
					'stock_status' => [ 'type' => 'keyword' ],
					'author_id'    => [ 'type' => 'keyword' ],
					'date'         => [ 'type' => 'date', 'format' => 'epoch_second' ],
					'lang'         => [ 'type' => 'keyword' ],
					'site_id'      => [ 'type' => 'integer' ],
					'meta_json'    => [ 'type' => 'text', 'index' => false ],
				],
			],
		];

		$create = $this->request( 'PUT', "/{$this->index_name}", $mapping );
		if ( is_wp_error( $create ) ) {
			$this->last_error = $create->get_error_message();
			$this->index_ready = false;
			return false;
		}

		$this->index_ready = true;
		return true;
	}

	private function format_results( array $response, int $page, string $query ): array {
		$hits_data = $response['hits']['hits'] ?? [];
		$found     = (int) ( $response['hits']['total']['value'] ?? count( $hits_data ) );
		$hits      = [];
		$post_ids  = [];

		foreach ( $hits_data as $h ) {
			$source  = $h['_source'] ?? [];
			$post_id = (int) ( $source['post_id'] ?? 0 );
			if ( $post_id <= 0 ) {
				continue;
			}

			$post_ids[] = $post_id;
			$hl_title   = $h['highlight']['title'][0] ?? null;
			$hl_excerpt = $h['highlight']['excerpt'][0] ?? ( $h['highlight']['content'][0] ?? null );

			$title   = $hl_title ?: esc_html( (string) ( $source['title'] ?? '' ) );
			$excerpt = $hl_excerpt ?: esc_html( (string) ( $source['excerpt'] ?? '' ) );

			$hits[] = apply_filters( 'wpts_result_hit', [
				'post_id'        => $post_id,
				'title'          => $title,
				'excerpt'        => $excerpt,
				'post_type'      => (string) ( $source['post_type'] ?? 'post' ),
				'url'            => (string) get_permalink( $post_id ),
				'thumbnail_url'  => (string) ( $source['meta_json'] ?? '' ),
				'date'           => (int) ( $source['date'] ?? 0 ),
				'date_formatted' => ! empty( $source['date'] ) ? date_i18n( get_option( 'date_format' ), (int) $source['date'] ) : '',
				'sku'            => (string) ( $source['sku'] ?? '' ),
				'price'          => (float) ( $source['price'] ?? 0.0 ),
				'stock_status'   => (string) ( $source['stock_status'] ?? 'instock' ),
			], $query );
		}

		$did_you_mean = ( $found === 0 ) ? Spelling::suggest( $query ) : null;
		$facets       = \WPTS\Search\Facets::build_facets( $post_ids );

		return [
			'hits'         => $hits,
			'found'        => $found,
			'page'         => $page,
			'facets'       => $facets,
			'did_you_mean' => $did_you_mean,
		];
	}

	private function request( string $method, string $path, ?array $body = null ) {
		$url     = $this->base_url . $path;
		$headers = [ 'Content-Type' => 'application/json', 'Accept' => 'application/json' ];

		if ( '' !== $this->api_key ) {
			$headers['Authorization'] = 'ApiKey ' . $this->api_key;
		} elseif ( '' !== $this->username && '' !== $this->password ) {
			$headers['Authorization'] = 'Basic ' . base64_encode( "{$this->username}:{$this->password}" );
		}

		$args = [
			'method'    => $method,
			'headers'   => $headers,
			'timeout'   => 5,
			'sslverify' => false,
		];

		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$resp = wp_remote_request( $url, $args );
		if ( is_wp_error( $resp ) ) {
			$this->last_error = $resp->get_error_message();
			return $resp;
		}

		$code = wp_remote_retrieve_response_code( $resp );
		if ( $code >= 400 ) {
			$body_raw = wp_remote_retrieve_body( $resp );
			$err_msg  = 'Elasticsearch returned HTTP ' . $code;
			$decoded  = json_decode( $body_raw, true );
			$reason   = $decoded['error']['reason'] ?? ( $decoded['error']['root_cause'][0]['reason'] ?? null );
			if ( $reason ) {
				$err_msg .= ': ' . $reason;
			}
			$this->last_error = $err_msg;
			return new \WP_Error( 'http_error', $err_msg, $body_raw );
		}

		if ( 'HEAD' === $method ) {
			return true;
		}

		return json_decode( wp_remote_retrieve_body( $resp ), true );
	}

	public function get_last_error(): ?string {
		return $this->last_error;
	}

	public function is_configured(): bool {
		return ! empty( $this->base_url );
	}
}
