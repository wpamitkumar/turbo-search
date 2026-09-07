<?php
namespace WPTS\Search;

defined( 'ABSPATH' ) || exit;

/**
 * Interface that all search engines (MySQL, Typesense, Elasticsearch) must implement.
 */
interface EngineInterface {

	/**
	 * Execute a search query.
	 *
	 * @param string $query    The raw search string.
	 * @param array  $filters  Key-value filters (post_type, taxonomies, price, etc.).
	 * @param int    $per_page Number of hits per page.
	 * @param int    $page     Page number (1-indexed).
	 * @return array{hits: array, found: int, page: int, facets?: array, did_you_mean?: string}
	 */
	public function search( string $query, array $filters = [], int $per_page = 10, int $page = 1 ): array;

	/**
	 * Insert or update a document in the index.
	 *
	 * @param array $document Normalized document fields.
	 */
	public function upsert( array $document ): bool;

	/**
	 * Delete a document from the index by WordPress post ID.
	 */
	public function delete( int $post_id ): bool;

	/**
	 * Re-index all posts across all tracked post types.
	 */
	public function reindex_all(): void;

	/**
	 * Re-index a single chunk/page of posts.
	 *
	 * @return array{done: bool, processed: int, total: int, page: int, max_pages: int, indexed_count?: int}
	 */
	public function reindex_chunk( int $paged = 1, int $batch = 50 ): array;

	/**
	 * Return the unique driver identifier (e.g. 'mysql', 'typesense', 'elasticsearch').
	 */
	public function get_driver(): string;

	/**
	 * Return the total count of documents currently in this engine's index.
	 */
	public function get_indexed_count(): int;

	/**
	 * Completely flush and wipe all indexed documents and schema from this engine.
	 */
	public function flush_index(): bool;

	/**
	 * Return the last error message recorded by the engine, if any.
	 */
	public function get_last_error(): ?string;

	/**
	 * Check whether the engine has minimum necessary settings configured.
	 */
	public function is_configured(): bool;
}

