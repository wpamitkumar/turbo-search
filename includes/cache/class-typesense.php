<?php
namespace WPTS\Cache;

use WPTS\Search\EngineInterface;
use WPTS\Search\QueryParser;
use WPTS\Search\Spelling;
use WPTS\Search\Facets;

defined( 'ABSPATH' ) || exit;

require_once WPTS_DIR . 'includes/search/interface-engine.php';

/**
 * Typesense search engine adapter v1.0.0
 * Implements EngineInterface, supports WooCommerce fields, facets, and spelling suggestions.
 */
class Typesense implements EngineInterface {

	private string  $base_url;
	private string  $api_key;
	private string  $collection;
	private bool    $collection_ready = false;
	private ?string $last_error       = null;

	public function __construct( array $settings ) {
		$raw_host = trim( (string) ( $settings['typesense_host'] ?? '127.0.0.1' ) );
		$proto    = $settings['typesense_protocol'] ?? 'http';
		$port     = $settings['typesense_port']     ?? '8108';

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
		$this->api_key    = trim( (string) ( $settings['typesense_api_key'] ?? '' ) );
		$this->collection = ! empty( $settings['typesense_collection'] ) ? trim( (string) $settings['typesense_collection'] ) : 'wpts_posts';
		$this->base_url   = "{$proto}://{$host}:{$port}";
	}

	public function search( string $query, array $filters = [], int $per_page = 10, int $page = 1 ): array {
		if ( '' === trim( $query ) ) {
			return [ 'hits' => [], 'found' => 0, 'page' => $page ];
		}

		if ( ! $this->ensure_collection() ) {
			$fallback = new MySQL();
			return $fallback->search( $query, $filters, $per_page, $page );
		}

		$filter_by = $this->build_filter_string( $filters );
		$settings  = \WPTS\Admin\Settings::get_all();

		$w_title = (int) ( $settings['weight_title'] ?? 10 );
		$w_exc   = (int) ( $settings['weight_excerpt'] ?? 5 );
		$w_cont  = (int) ( $settings['weight_content'] ?? 1 );

		$params = [
			'q'                          => $query,
			'query_by'                   => 'title,excerpt,content,taxonomies,sku',
			'query_by_weights'           => "{$w_title},{$w_exc},{$w_cont},4,8",
			'per_page'                   => $per_page,
			'page'                       => $page,
			'highlight_full_fields'      => 'title,excerpt',
			'highlight_affix_num_tokens' => 4,
			'num_typos'                  => min( 2, (int) floor( strlen( $query ) / 4 ) ),
			'prefix'                     => 'true',
			'infix'                      => 'fallback',
			'split_join_tokens'          => 'always',
		];

		if ( $filter_by ) {
			$params['filter_by'] = $filter_by;
		}

		$params = apply_filters( 'wpts_typesense_search_params', $params, $query, $filters );

		$response = $this->request(
			'GET',
			"/collections/{$this->collection}/documents/search?" . http_build_query( $params )
		);

		if ( is_wp_error( $response ) ) {
			do_action( 'wpts_search_error', $response, 'typesense' );
			$fallback = new MySQL();
			return $fallback->search( $query, $filters, $per_page, $page );
		}

		$formatted = $this->format_results( $response, $page, $query );

		// Fallback to MySQL if collection returned 0 hits
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
		$document = apply_filters( 'wpts_before_index_document', $document );

		$doc_id = (string) ( $document['id'] ?? '' );
		if ( '' === $doc_id ) {
			$this->last_error = __( 'Missing document ID.', 'turbo-search' );
			return false;
		}

		$title = trim( (string) ( $document['title'] ?? '' ) );
		if ( '' === $title ) {
			$title = (string) ( $document['slug'] ?? '' );
			if ( '' === $title ) {
				$title = sprintf( 'Post #%s', $doc_id );
			}
		}

		$doc = [
			'id'           => $doc_id,
			'title'        => $title,
			'content'      => (string) ( $document['content']   ?? '' ),
			'excerpt'      => (string) ( $document['excerpt']   ?? '' ),
			'slug'         => (string) ( $document['slug']      ?? '' ),
			'post_type'    => (string) ( $document['post_type'] ?? 'post' ),
			'author_id'    => (int)    ( $document['author_id'] ?? 0 ),
			'date'         => (int)    ( $document['date'] ?? 0 ) ?: time(),
			'lang'         => (string) ( $document['lang']      ?? '' ),
			'site_id'      => (int)    ( $document['site_id']   ?? get_current_blog_id() ),
			'sku'          => (string) ( $document['sku']       ?? '' ),
			'price'        => (float)  ( $document['price']     ?? 0.0 ),
			'stock_status' => (string) ( $document['stock_status'] ?? 'instock' ),
			'taxonomies'   => (string) ( $document['taxonomies']   ?? '' ),
			'meta_json'    => (string) ( $document['thumbnail_url'] ?? '' ),
		];

		if ( ! $this->ensure_collection() ) {
			return false;
		}

		$response = $this->request(
			'POST',
			"/collections/{$this->collection}/documents?action=upsert",
			$doc
		);

		$success = ! is_wp_error( $response );
		if ( ! $success && is_wp_error( $response ) ) {
			$this->last_error = $response->get_error_message();
		}
		do_action( 'wpts_after_index_document', $document, $success, 'typesense' );
		return $success;
	}

	public function upsert_bulk( array $documents ): bool {
		$this->last_error = null;
		if ( empty( $documents ) ) {
			return true;
		}

		if ( ! $this->ensure_collection() ) {
			return false;
		}

		$lines = [];
		foreach ( $documents as $document ) {
			$document = apply_filters( 'wpts_before_index_document', $document );

			$doc_id = (string) ( $document['id'] ?? '' );
			if ( '' === $doc_id ) {
				continue;
			}

			$title = trim( (string) ( $document['title'] ?? '' ) );
			if ( '' === $title ) {
				$title = (string) ( $document['slug'] ?? '' );
				if ( '' === $title ) {
					$title = sprintf( 'Post #%s', $doc_id );
				}
			}

			$doc = [
				'id'           => $doc_id,
				'title'        => $title,
				'content'      => (string) ( $document['content']   ?? '' ),
				'excerpt'      => (string) ( $document['excerpt']   ?? '' ),
				'slug'         => (string) ( $document['slug']      ?? '' ),
				'post_type'    => (string) ( $document['post_type'] ?? 'post' ),
				'author_id'    => (int)    ( $document['author_id'] ?? 0 ),
				'date'         => (int)    ( $document['date'] ?? 0 ) ?: time(),
				'lang'         => (string) ( $document['lang']      ?? '' ),
				'site_id'      => (int)    ( $document['site_id']   ?? get_current_blog_id() ),
				'sku'          => (string) ( $document['sku']       ?? '' ),
				'price'        => (float)  ( $document['price']     ?? 0.0 ),
				'stock_status' => (string) ( $document['stock_status'] ?? 'instock' ),
				'taxonomies'   => (string) ( $document['taxonomies']   ?? '' ),
				'meta_json'    => (string) ( $document['thumbnail_url'] ?? '' ),
			];

			$lines[] = wp_json_encode( $doc );
		}

		if ( empty( $lines ) ) {
			return true;
		}

		$jsonl_payload = implode( "\n", $lines );
		$url  = $this->base_url . "/collections/{$this->collection}/documents/import?action=upsert";
		$args = [
			'method'    => 'POST',
			'headers'   => [
				'X-TYPESENSE-API-KEY' => $this->api_key,
				'Content-Type'        => 'text/plain',
				'Accept'              => 'application/json',
			],
			'body'      => $jsonl_payload,
			'timeout'   => 15,
			'sslverify' => false,
		];

		$resp    = wp_remote_request( $url, $args );
		$success = ! is_wp_error( $resp );
		if ( ! $success && is_wp_error( $resp ) ) {
			$this->last_error = $resp->get_error_message();
		}
		do_action( 'wpts_after_index_bulk', $documents, $success, 'typesense' );
		return $success;
	}

	public function delete( int $post_id ): bool {
		$response = $this->request(
			'DELETE',
			"/collections/{$this->collection}/documents/" . (string) $post_id
		);
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
		return 'typesense';
	}

	public function get_indexed_count(): int {
		$status = $this->get_status();
		return (int) ( $status['num_documents'] ?? 0 );
	}

	public function get_status(): array {
		$response = $this->request( 'GET', "/collections/{$this->collection}" );

		if ( is_wp_error( $response ) ) {
			return [
				'ok'            => false,
				'reachable'     => false,
				'error'         => $response->get_error_message(),
				'collection'    => $this->collection,
				'num_documents' => 0,
			];
		}

		return [
			'ok'            => true,
			'reachable'     => true,
			'collection'    => $this->collection,
			'num_documents' => (int) ( $response['num_documents'] ?? 0 ),
		];
	}

	public function flush_collection(): bool {
		$this->request( 'DELETE', "/collections/{$this->collection}" );
		$this->collection_ready = false;
		$ok = $this->ensure_collection();
		do_action( 'wpts_typesense_collection_flushed', $this->collection );
		do_action( 'wpts_index_flushed', 'typesense' );
		return $ok;
	}

	public function flush_index(): bool {
		return $this->flush_collection();
	}

	private function ensure_collection(): bool {
		if ( $this->collection_ready ) {
			return true;
		}

		$response = $this->request( 'GET', "/collections/{$this->collection}" );

		if ( ! is_wp_error( $response ) && isset( $response['name'] ) ) {
			$this->collection_ready = true;
			return true;
		}

		$schema = [
			'name'                   => $this->collection,
			'fields'                 => [
				[ 'name' => 'id',           'type' => 'string' ],
				[ 'name' => 'title',        'type' => 'string' ],
				[ 'name' => 'content',      'type' => 'string' ],
				[ 'name' => 'excerpt',      'type' => 'string', 'optional' => true ],
				[ 'name' => 'taxonomies',   'type' => 'string', 'optional' => true ],
				[ 'name' => 'slug',         'type' => 'string' ],
				[ 'name' => 'post_type',    'type' => 'string', 'facet' => true ],
				[ 'name' => 'author_id',    'type' => 'int32' ],
				[ 'name' => 'date',         'type' => 'int64' ],
				[ 'name' => 'lang',         'type' => 'string', 'facet' => true, 'optional' => true ],
				[ 'name' => 'site_id',      'type' => 'int32',  'facet' => true ],
				[ 'name' => 'sku',          'type' => 'string', 'optional' => true ],
				[ 'name' => 'price',        'type' => 'float',  'facet' => true, 'optional' => true ],
				[ 'name' => 'stock_status', 'type' => 'string', 'facet' => true, 'optional' => true ],
				[ 'name' => 'meta_json',    'type' => 'string', 'index' => false, 'optional' => true ],
			],
			'token_separators'       => [ '-', '_', '/', '@', '.', '#', ':' ],
			'symbols_to_index'       => [ '#', '@', '-', '_', '/', ':', '.' ],
			'default_sorting_field' => 'date',
		];
		$schema = apply_filters( 'wpts_typesense_collection_schema', $schema, $this->collection );

		$create = $this->request( 'POST', '/collections', $schema );
		if ( is_wp_error( $create ) ) {
			$this->last_error = $create->get_error_message();
			$this->collection_ready = false;
			return false;
		}

		$this->collection_ready = true;
		return true;
	}

	private function build_filter_string( array $filters ): string {
		$parts = [];

		if ( ! empty( $filters['post_type'] ) ) {
			$pts = is_array( $filters['post_type'] ) ? $filters['post_type'] : explode( ',', (string) $filters['post_type'] );
			$pts = array_map( 'sanitize_key', $pts );
			$parts[] = 'post_type:=[' . implode( ',', $pts ) . ']';
		}

		if ( ! empty( $filters['lang'] ) ) {
			$parts[] = 'lang:=' . sanitize_text_field( $filters['lang'] );
		}

		if ( is_multisite() && ! empty( $filters['site_id'] ) ) {
			$parts[] = 'site_id:=' . absint( $filters['site_id'] );
		}

		if ( ! empty( $filters['stock_status'] ) ) {
			$parts[] = 'stock_status:=' . sanitize_key( $filters['stock_status'] );
		}

		$parts = (array) apply_filters( 'wpts_typesense_filter_parts', $parts, $filters );
		return implode( ' && ', $parts );
	}

	private function format_results( array $response, int $page, string $query ): array {
		$hits     = [];
		$found    = (int) ( $response['found'] ?? 0 );
		$post_ids = [];

		foreach ( (array) ( $response['hits'] ?? [] ) as $item ) {
			$doc     = $item['document'] ?? [];
			$post_id = (int) ( $doc['id'] ?? 0 );
			if ( $post_id <= 0 ) {
				continue;
			}

			$post_ids[] = $post_id;
			$hl_title   = null;
			$hl_excerpt = null;

			foreach ( (array) ( $item['highlights'] ?? [] ) as $hl ) {
				if ( 'title' === ( $hl['field'] ?? '' ) ) {
					$hl_title = (string) ( $hl['snippet'] ?? '' );
				}
				if ( 'excerpt' === ( $hl['field'] ?? '' ) ) {
					$hl_excerpt = (string) ( $hl['snippet'] ?? '' );
				}
			}

			$title   = $hl_title ?: esc_html( (string) ( $doc['title'] ?? '' ) );
			$excerpt = $hl_excerpt ?: esc_html( (string) ( $doc['excerpt'] ?? '' ) );

			$hits[] = apply_filters( 'wpts_result_hit', [
				'post_id'        => $post_id,
				'title'          => $title,
				'excerpt'        => $excerpt,
				'post_type'      => (string) ( $doc['post_type'] ?? 'post' ),
				'url'            => (string) get_permalink( $post_id ),
				'thumbnail_url'  => (string) ( $doc['meta_json'] ?? '' ),
				'date'           => (int) ( $doc['date'] ?? 0 ),
				'date_formatted' => ! empty( $doc['date'] ) ? date_i18n( get_option( 'date_format' ), (int) $doc['date'] ) : '',
				'sku'            => (string) ( $doc['sku'] ?? '' ),
				'price'          => (float) ( $doc['price'] ?? 0.0 ),
				'stock_status'   => (string) ( $doc['stock_status'] ?? 'instock' ),
			], $query );
		}

		$did_you_mean = ( $found === 0 ) ? Spelling::suggest( $query ) : null;
		$facets       = Facets::build_facets( $post_ids );

		return [
			'hits'         => $hits,
			'found'        => $found,
			'page'         => $page,
			'facets'       => $facets,
			'did_you_mean' => $did_you_mean,
		];
	}

	private function request( string $method, string $path, ?array $body = null ) {
		$url  = $this->base_url . $path;
		$args = [
			'method'    => $method,
			'headers'   => [
				'X-TYPESENSE-API-KEY' => $this->api_key,
				'Content-Type'        => 'application/json',
				'Accept'              => 'application/json',
			],
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
			$err_msg  = 'Typesense returned HTTP ' . $code;
			$decoded  = json_decode( $body_raw, true );
			if ( is_array( $decoded ) && ! empty( $decoded['message'] ) ) {
				$err_msg .= ': ' . $decoded['message'];
			}
			$this->last_error = $err_msg;
			return new \WP_Error( 'http_error', $err_msg, $body_raw );
		}

		return json_decode( wp_remote_retrieve_body( $resp ), true );
	}

	public function get_last_error(): ?string {
		return $this->last_error;
	}

	public function is_configured(): bool {
		return ! empty( $this->api_key ) && ! empty( $this->base_url );
	}
}
