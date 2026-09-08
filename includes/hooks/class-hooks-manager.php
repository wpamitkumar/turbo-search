<?php
namespace WPTS\Hooks;

defined( 'ABSPATH' ) || exit;

/**
 * Registers internal hooks and provides a reference of all public hooks
 * for the Dev Hooks admin page.
 *
 * The reference in get_reference() is kept in sync with the actual
 * do_action()/apply_filters() calls in the codebase - every hook listed
 * here is verified to actually fire somewhere. If you add a new hook to
 * the plugin, add its entry here too.
 */
class Manager {

	public function init(): void {
		// Auto-index when Polylang / WPML saves a translation
		add_action( 'pll_save_post',       [ $this, 'on_translation_save' ], 10, 3 );
		add_action( 'icl_after_save_post', [ $this, 'on_wpml_save' ], 10, 3 );

		// Multisite: index across blogs
		add_action( 'wpts_reindex_complete', [ $this, 'log_reindex' ], 10, 2 );
	}

	public function on_translation_save( int $post_id, \WP_Post $post, array $translations ): void {
		\WPTS\Core::instance()->on_save_post( $post_id, $post, true );
	}

	public function on_wpml_save( int $post_id, int $trid, string $lang ): void {
		$post = get_post( $post_id );
		if ( $post ) {
			\WPTS\Core::instance()->on_save_post( $post_id, $post, true );
		}
	}

	public function log_reindex( int $count, string $engine ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( "[WPTS] Re-index complete via {$engine}. Posts processed: {$count}" );
		}
	}

	/**
	 * Return every public hook for the admin reference page.
	 *
	 * Each entry:
	 *   name     Hook name.
	 *   type     'filter' or 'action'.
	 *   category Grouping shown on the admin page.
	 *   file     Where the hook fires, relative to the plugin root.
	 *   params   Human-readable parameter signature.
	 *   desc     One-sentence explanation of what the hook is for.
	 *   example  A complete, working code snippet using the hook.
	 */
	public static function get_reference(): array {
		return [

			// INDEXING & DOCUMENTS

			[
				'name'     => 'wpts_indexable_document',
				'type'     => 'filter',
				'category' => 'Indexing & Documents',
				'file'     => 'includes/class-core.php - on_save_post()',
				'params'   => '$document (array), $post (WP_Post)',
				'desc'     => 'Modify the document array built from a post before it is sent to the search engine. Runs on every save, for every configured post type.',
				'example'  => "add_filter( 'wpts_indexable_document', function ( array \$document, \\WP_Post \$post ) {\n    // Prefix the indexed title with the post's primary category\n    \$terms = get_the_category( \$post->ID );\n    if ( ! empty( \$terms ) ) {\n        \$document['title'] = '[' . \$terms[0]->name . '] ' . \$document['title'];\n    }\n    return \$document;\n}, 10, 2 );",
			],
			[
				'name'     => 'wpts_before_index_document',
				'type'     => 'filter',
				'category' => 'Indexing & Documents',
				'file'     => 'includes/cache/class-mysql.php + class-typesense.php - upsert()',
				'params'   => '$document (array)',
				'desc'     => 'Last-chance modification of the document immediately before it is written to the active engine (MySQL or Typesense). Fires after wpts_indexable_document.',
				'example'  => "add_filter( 'wpts_before_index_document', function ( array \$document ) {\n    // Strip a noisy phrase from content right before indexing,\n    // without affecting what is stored in the post itself.\n    \$document['content'] = str_replace( 'Advertisement', '', \$document['content'] );\n    return \$document;\n} );",
			],
			[
				'name'     => 'wpts_after_index_document',
				'type'     => 'action',
				'category' => 'Indexing & Documents',
				'file'     => 'includes/cache/class-mysql.php + class-typesense.php - upsert()',
				'params'   => '$document (array), $success (bool), $engine (string)',
				'desc'     => 'Fires after a single post has been indexed (or failed to index). Useful for custom logging, webhooks, or alerting on failures.',
				'example'  => "add_action( 'wpts_after_index_document', function ( array \$document, bool \$success, string \$engine ) {\n    if ( ! \$success ) {\n        error_log( sprintf(\n            '[MyPlugin] Failed to index post #%s via %s',\n            \$document['id'] ?? '?',\n            \$engine\n        ) );\n    }\n}, 10, 3 );",
			],
			[
				'name'     => 'wpts_reindex_complete',
				'type'     => 'action',
				'category' => 'Indexing & Documents',
				'file'     => 'includes/cache/class-mysql.php + class-typesense.php - reindex_chunk()',
				'params'   => '$count (int), $engine (string)',
				'desc'     => 'Fires once a full re-index operation (via admin UI, AJAX chunking, or WP-CLI) finishes. $count is the number of documents now in the index.',
				'example'  => "add_action( 'wpts_reindex_complete', function ( int \$count, string \$engine ) {\n    // Notify Slack whenever a re-index finishes\n    wp_remote_post( 'https://hooks.slack.com/services/YOUR/WEBHOOK/URL', [\n        'body' => wp_json_encode( [\n            'text' => \"🔎 Re-index complete via {\$engine}: {\$count} documents indexed.\",\n        ] ),\n        'headers' => [ 'Content-Type' => 'application/json' ],\n    ] );\n}, 10, 2 );",
			],
			[
				'name'     => 'wpts_after_index_bulk',
				'type'     => 'action',
				'category' => 'Indexing & Documents',
				'file'     => 'includes/cache/class-typesense.php + class-elasticsearch.php - upsert_bulk()',
				'params'   => '$documents (array), $success (bool), $engine (string)',
				'desc'     => 'Fires after a bulk batch of documents has been submitted for indexing into Typesense or Elasticsearch.',
				'example'  => "add_action( 'wpts_after_index_bulk', function ( array \$documents, bool \$success, string \$engine ) {\n    if ( ! \$success ) {\n        error_log( sprintf( '[WPTS] Bulk indexing batch of %d items failed on %s', count( \$documents ), \$engine ) );\n    }\n}, 10, 3 );",
			],
			[
				'name'     => 'wpts_index_flushed',
				'type'     => 'action',
				'category' => 'Indexing & Documents',
				'file'     => 'includes/api/class-rest-admin.php - clear_index()',
				'params'   => '$engine (string)',
				'desc'     => 'Fires when an index or external collection is wiped/cleared by an administrator via the admin dashboard or WP-CLI.',
				'example'  => "add_action( 'wpts_index_flushed', function ( string \$engine ) {\n    error_log( sprintf( '[WPTS] Index cleared for engine: %s', \$engine ) );\n} );",
			],

			[
				'name'     => 'wpts_cache_ttl',
				'type'     => 'filter',
				'category' => 'Caching',
				'file'     => 'includes/cache/class-cache-manager.php - cache_set()',
				'params'   => '$ttl (int, seconds), $key (string), $driver (string)',
				'desc'     => 'Modify the cache TTL (time-to-live, in seconds) for a specific result before it is stored. Runs on every cache write, for any driver.',
				'example'  => "add_filter( 'wpts_cache_ttl', function ( int \$ttl, string \$key, string \$driver ) {\n    // Cache empty/rare queries for longer, popular ones for a shorter time\n    // to keep results fresher without losing the speed benefit overall.\n    return \$ttl;\n}, 10, 3 );\n\n// A more concrete example - shorten TTL during a flash sale so new\n// products show up in search results faster:\nadd_filter( 'wpts_cache_ttl', function ( int \$ttl, string \$key, string \$driver ) {\n    return get_option( 'my_flash_sale_active' ) ? 30 : \$ttl;\n}, 10, 3 );",
			],
			[
				'name'     => 'wpts_cache_key',
				'type'     => 'filter',
				'category' => 'Caching',
				'file'     => 'includes/cache/class-cache-manager.php - make_key()',
				'params'   => '$raw (string, pre-hash key material), $query (string), $filters (array)',
				'desc'     => 'Extend the raw string used to build a search result\'s cache key, before it is hashed. Use this if your integration adds context that should produce a DIFFERENT cached result for the same query (e.g. per-user personalization).',
				'example'  => "add_filter( 'wpts_cache_key', function ( string \$raw, string \$query, array \$filters ) {\n    // Cache results separately per logged-in user role, so an admin's\n    // search doesn't get served a visitor's cached (possibly filtered) results.\n    \$raw .= '|role:' . ( wp_get_current_user()->roles[0] ?? 'guest' );\n    return \$raw;\n}, 10, 3 );",
			],

			// SEARCH & RESULTS

			[
				'name'     => 'wpts_result_hit',
				'type'     => 'filter',
				'category' => 'Search & Results',
				'file'     => 'includes/cache/class-mysql.php + class-typesense.php - search()',
				'params'   => '$hit (array), $query (string)',
				'desc'     => 'Modify a single search result before it is returned. Fires once per hit, identically for REST requests and the AJAX fallback. Use this to add custom fields or rewrite URLs.',
				'example'  => "add_filter( 'wpts_result_hit', function ( array \$hit, string \$query ) {\n    // Add a \"read time\" estimate to every result\n    \$post = get_post( \$hit['post_id'] );\n    if ( \$post ) {\n        \$words = str_word_count( wp_strip_all_tags( \$post->post_content ) );\n        \$hit['read_time_minutes'] = max( 1, (int) round( \$words / 200 ) );\n    }\n    return \$hit;\n}, 10, 2 );",
			],
			[
				'name'     => 'wpts_mysql_where_clauses',
				'type'     => 'filter',
				'category' => 'Search & Results',
				'file'     => 'includes/cache/class-mysql.php - search()',
				'params'   => '[$where_parts, $where_values] (array pair), $query (string), $filters (array)',
				'desc'     => 'Add custom SQL WHERE conditions to the MySQL search query. Must return the same [conditions, values] pair shape.',
				'example'  => "add_filter( 'wpts_mysql_where_clauses', function ( array \$pair, string \$query, array \$filters ) {\n    [ \$conditions, \$values ] = \$pair;\n\n    // Only ever show posts from the last 2 years in search results\n    \$conditions[] = 'indexed_at >= %s';\n    \$values[]     = gmdate( 'Y-m-d', strtotime( '-2 years' ) );\n\n    return [ \$conditions, \$values ];\n}, 10, 3 );",
			],
			[
				'name'     => 'wpts_synonym_groups',
				'type'     => 'filter',
				'category' => 'Search & Results',
				'file'     => 'includes/search/class-synonyms.php - get_all()',
				'params'   => '$groups (array of comma-separated string synonym groups)',
				'desc'     => 'Filter or programmatically inject custom synonym mappings into the active search query expander.',
				'example'  => "add_filter( 'wpts_synonym_groups', function ( array \$groups ) {\n    // Dynamically inject custom product synonym group\n    \$groups[] = 'sneakers, shoes, trainers, footwear';\n    return \$groups;\n} );",
			],
			[
				'name'     => 'wpts_search_engine',
				'type'     => 'filter',
				'category' => 'Search & Results',
				'file'     => 'includes/class-core.php - get_engine()',
				'params'   => '$engine (Typesense|MySQL|Elasticsearch object), $settings (array)',
				'desc'     => 'Swap in a completely custom search engine object. Must implement search(), upsert(), delete(), reindex_all(), reindex_chunk(), get_driver(), get_indexed_count().',
				'example'  => "add_filter( 'wpts_search_engine', function ( \$engine, array \$settings ) {\n    // Force MySQL regardless of Typesense settings, e.g. on staging\n    if ( wp_get_environment_type() === 'staging' ) {\n        return new \\WPTS\\Cache\\MySQL();\n    }\n    return \$engine;\n}, 10, 2 );",
			],
			[
				'name'     => 'wpts_engine_fallback',
				'type'     => 'action',
				'category' => 'Search & Results',
				'file'     => 'includes/cache/class-cache-manager.php - search()',
				'params'   => '$fallback_to (string), $original_engine_class (string)',
				'desc'     => 'Fires when the configured engine (e.g. Typesense) returned no results or errored during a search, and the plugin automatically fell back to MySQL.',
				'example'  => "add_action( 'wpts_engine_fallback', function ( string \$fallback_to, string \$original_class ) {\n    error_log( \"[WPTS] {\$original_class} failed - search served by {\$fallback_to} instead.\" );\n}, 10, 2 );",
			],
			[
				'name'     => 'wpts_typesense_fallback',
				'type'     => 'action',
				'category' => 'Search & Results',
				'file'     => 'includes/class-core.php - get_engine()',
				'params'   => '$reason (string)',
				'desc'     => 'Fires when Typesense is configured but was found unreachable while resolving which engine to use - before any search even runs. Currently $reason is always "unreachable".',
				'example'  => "add_action( 'wpts_typesense_fallback', function ( string \$reason ) {\n    // Email the admin once per hour if Typesense goes down\n    \$last_notified = get_transient( 'wpts_typesense_down_notice' );\n    if ( ! \$last_notified ) {\n        wp_mail( get_option( 'admin_email' ), 'Typesense unreachable', \"Reason: {\$reason}\" );\n        set_transient( 'wpts_typesense_down_notice', 1, HOUR_IN_SECONDS );\n    }\n} );",
			],
			[
				'name'     => 'wpts_core_fallback_query_args',
				'type'     => 'filter',
				'category' => 'Search & Results',
				'file'     => 'includes/search/class-wp-core-fallback.php - search()',
				'params'   => '$query_args (array), $query (string), $filters (array)',
				'desc'     => 'Filter the arguments passed to WP_Query when the engine performs an emergency fallback to WordPress Core search.',
				'example'  => "add_filter( 'wpts_core_fallback_query_args', function ( array \$args, string \$query, array \$filters ) {\n    // Exclude password-protected posts during Core fallback\n    \$args['has_password'] = false;\n    return \$args;\n}, 10, 3 );",
			],
			[
				'name'     => 'wpts_search_error',
				'type'     => 'action',
				'category' => 'Search & Results',
				'file'     => 'includes/cache/class-typesense.php - search()',
				'params'   => '$error (WP_Error), $engine (string)',
				'desc'     => 'Fires when the search engine itself returns an error response while executing a query (e.g. Typesense API error).',
				'example'  => "add_action( 'wpts_search_error', function ( \\WP_Error \$error, string \$engine ) {\n    error_log( \"[WPTS] {\$engine} search error: \" . \$error->get_error_message() );\n}, 10, 2 );",
			],

			// REST API

			[
				'name'     => 'wpts_rest_search_query',
				'type'     => 'filter',
				'category' => 'REST API',
				'file'     => 'includes/api/class-rest-search.php - handle_search()',
				'params'   => '$query (string), $request (WP_REST_Request)',
				'desc'     => 'Modify or rewrite the search query string before it is executed. Runs only on REST requests (not the AJAX fallback). Good for synonym expansion.',
				'example'  => "add_filter( 'wpts_rest_search_query', function ( string \$query, \\WP_REST_Request \$request ) {\n    \$synonyms = [ 'js' => 'javascript', 'wp' => 'wordpress' ];\n    \$lower    = strtolower( trim( \$query ) );\n    return \$synonyms[ \$lower ] ?? \$query;\n}, 10, 2 );",
			],
			[
				'name'     => 'wpts_rest_search_results',
				'type'     => 'filter',
				'category' => 'REST API',
				'file'     => 'includes/api/class-rest-search.php - handle_search()',
				'params'   => '$results (array), $query (string), $request (WP_REST_Request)',
				'desc'     => 'Modify the full { hits, found, page } payload before it is sent to the client. Runs only on REST requests.',
				'example'  => "add_filter( 'wpts_rest_search_results', function ( array \$results, string \$query, \\WP_REST_Request \$request ) {\n    // Add a top-level flag when very few results are found\n    \$results['low_results'] = ( \$results['found'] ?? 0 ) < 3;\n    return \$results;\n}, 10, 3 );",
			],

			// CACHING

			[
				'name'     => 'wpts_cache_hit',
				'type'     => 'action',
				'category' => 'Caching',
				'file'     => 'includes/cache/class-cache-manager.php - search()',
				'params'   => '$key (string), $driver (string)',
				'desc'     => 'Fires when a search result is served from cache instead of hitting the search engine. Powers the built-in Cache Statistics dashboard toggle.',
				'example'  => "add_action( 'wpts_cache_hit', function ( string \$key, string \$driver ) {\n    do_action( 'my_plugin_metric', 'wpts.cache.hit', [ 'driver' => \$driver ] );\n}, 10, 2 );",
			],
			[
				'name'     => 'wpts_cache_miss',
				'type'     => 'action',
				'category' => 'Caching',
				'file'     => 'includes/cache/class-cache-manager.php - search()',
				'params'   => '$key (string), $driver (string)',
				'desc'     => 'Fires when a search was not found in cache and had to hit the search engine directly. Powers the built-in Cache Statistics dashboard toggle.',
				'example'  => "add_action( 'wpts_cache_miss', function ( string \$key, string \$driver ) {\n    do_action( 'my_plugin_metric', 'wpts.cache.miss', [ 'driver' => \$driver ] );\n}, 10, 2 );",
			],
			[
				'name'     => 'wpts_cache_flushed',
				'type'     => 'action',
				'category' => 'Caching',
				'file'     => 'includes/cache/class-cache-manager.php - flush_all()',
				'params'   => '$driver (string)',
				'desc'     => 'Fires after the entire result cache is flushed (via "Flush All Cache" button, WP-CLI, or automatically before a full re-index).',
				'example'  => "add_action( 'wpts_cache_flushed', function ( string \$driver ) {\n    error_log( \"[WPTS] Cache fully flushed (driver: {\$driver}) at \" . current_time( 'mysql' ) );\n} );",
			],
			[
				'name'     => 'wpts_before_cache_flush_post',
				'type'     => 'action',
				'category' => 'Caching',
				'file'     => 'includes/cache/class-cache-manager.php - flush_post()',
				'params'   => '$post_id (int), $driver (string)',
				'desc'     => 'Fires before a single post\'s cached search entries are invalidated (e.g. right after that post is saved).',
				'example'  => "add_action( 'wpts_before_cache_flush_post', function ( int \$post_id, string \$driver ) {\n    error_log( \"About to invalidate cache for post #{\$post_id}\" );\n}, 10, 2 );",
			],
			[
				'name'     => 'wpts_after_cache_flush_post',
				'type'     => 'action',
				'category' => 'Caching',
				'file'     => 'includes/cache/class-cache-manager.php - flush_post()',
				'params'   => '$post_id (int), $driver (string)',
				'desc'     => 'Fires after a single post\'s cached search entries have been invalidated.',
				'example'  => "add_action( 'wpts_after_cache_flush_post', function ( int \$post_id, string \$driver ) {\n    // Warm a related cache your own plugin maintains\n    my_plugin_rebuild_related_posts_cache( \$post_id );\n}, 10, 2 );",
			],
			[
				'name'     => 'wpts_cache_connect_error',
				'type'     => 'action',
				'category' => 'Caching',
				'file'     => 'includes/cache/class-cache-manager.php - connect_redis() / connect_memcached()',
				'params'   => '$driver (string), $message (string)',
				'desc'     => 'Fires when a Redis or Memcached connection attempt fails. Good hook point for ops alerting.',
				'example'  => "add_action( 'wpts_cache_connect_error', function ( string \$driver, string \$message ) {\n    wp_mail(\n        get_option( 'admin_email' ),\n        \"Turbo Search: {\$driver} connection failed\",\n        \$message\n    );\n}, 10, 2 );",
			],

			// TYPESENSE

			[
				'name'     => 'wpts_typesense_search_params',
				'type'     => 'filter',
				'category' => 'Typesense',
				'file'     => 'includes/cache/class-typesense.php - search()',
				'params'   => '$params (array), $query (string), $filters (array)',
				'desc'     => 'Customize the raw parameters sent to Typesense\'s /documents/search endpoint (query_by, num_typos, highlight settings, etc).',
				'example'  => "add_filter( 'wpts_typesense_search_params', function ( array \$params, string \$query, array \$filters ) {\n    // Allow more typo tolerance for longer queries\n    if ( strlen( \$query ) > 12 ) {\n        \$params['num_typos'] = 3;\n    }\n    return \$params;\n}, 10, 3 );",
			],
			[
				'name'     => 'wpts_typesense_filter_parts',
				'type'     => 'filter',
				'category' => 'Typesense',
				'file'     => 'includes/cache/class-typesense.php - build_filter_string()',
				'params'   => '$parts (array of strings), $filters (array)',
				'desc'     => 'Add custom filter_by clauses to Typesense queries (each entry is a Typesense filter expression, joined with &&).',
				'example'  => "add_filter( 'wpts_typesense_filter_parts', function ( array \$parts, array \$filters ) {\n    // Only ever return posts by a specific author ID\n    \$parts[] = 'author_id:=42';\n    return \$parts;\n}, 10, 2 );",
			],
			[
				'name'     => 'wpts_typesense_collection_schema',
				'type'     => 'filter',
				'category' => 'Typesense',
				'file'     => 'includes/cache/class-typesense.php - create_collection()',
				'params'   => '$schema (array), $collection (string)',
				'desc'     => 'Customize the Typesense collection schema before it is created - add extra indexed fields, change field types, etc. Only takes effect the next time the collection is (re)created.',
				'example'  => "add_filter( 'wpts_typesense_collection_schema', function ( array \$schema, string \$collection ) {\n    \$schema['fields'][] = [ 'name' => 'view_count', 'type' => 'int32', 'optional' => true ];\n    return \$schema;\n}, 10, 2 );",
			],
			[
				'name'     => 'wpts_typesense_collection_flushed',
				'type'     => 'action',
				'category' => 'Typesense',
				'file'     => 'includes/cache/class-typesense.php - flush_collection()',
				'params'   => '$collection (string)',
				'desc'     => 'Fires after the Typesense collection is dropped via the "Flush Typesense Data" button, WP-CLI, or the flush endpoint.',
				'example'  => "add_action( 'wpts_typesense_collection_flushed', function ( string \$collection ) {\n    error_log( \"[WPTS] Typesense collection '{\$collection}' was flushed.\" );\n} );",
			],

			// FRONTEND & BLOCKS

			[
				'name'     => 'wpts_block_render_attrs',
				'type'     => 'filter',
				'category' => 'Frontend & Blocks',
				'file'     => 'includes/class-block.php - render()',
				'params'   => '$attrs (array, merged with defaults), $raw_attrs (array, original from editor)',
				'desc'     => 'Modify the Gutenberg search block\'s attributes before its frontend HTML is rendered - placeholder text, colors, debounce/throttle values, etc.',
				'example'  => "add_filter( 'wpts_block_render_attrs', function ( array \$attrs, array \$raw_attrs ) {\n    // Force dark theme for this block everywhere it appears\n    \$attrs['theme'] = 'dark';\n    return \$attrs;\n}, 10, 2 );",
			],
			[
				'name'     => 'wpts_shortcode_atts',
				'type'     => 'filter',
				'category' => 'Frontend & Blocks',
				'file'     => 'templates/shortcode.php - wpts_render_search_shortcode()',
				'params'   => '$atts (array)',
				'desc'     => 'Modify [wpts_search] shortcode attributes before the widget is rendered - same options as the shortcode itself (placeholder, theme, debounce, etc).',
				'example'  => "add_filter( 'wpts_shortcode_atts', function ( array \$atts ) {\n    // Always restrict shortcode search to the \"docs\" post type\n    \$atts['post_type'] = 'docs';\n    return \$atts;\n} );",
			],

			// CUSTOM POST TYPES

			[
				'name'     => 'wpts_register_sample_cpt',
				'type'     => 'filter',
				'category' => 'Custom Post Types',
				'file'     => 'includes/cpt/class-cpt-manager.php - register()',
				'params'   => '$register (bool)',
				'desc'     => 'Return false to prevent the plugin\'s demo "Resource" custom post type from registering - useful if you don\'t need the example CPT in production.',
				'example'  => "add_filter( 'wpts_register_sample_cpt', '__return_false' );",
			],
			[
				'name'     => 'wpts_resource_cpt_args',
				'type'     => 'filter',
				'category' => 'Custom Post Types',
				'file'     => 'includes/cpt/class-cpt-manager.php - register()',
				'params'   => '$args (array)',
				'desc'     => 'Customize the register_post_type() arguments used for the demo "Resource" CPT (only relevant if wpts_register_sample_cpt is not disabled).',
				'example'  => "add_filter( 'wpts_resource_cpt_args', function ( array \$args ) {\n    \$args['menu_icon'] = 'dashicons-book';\n    \$args['public']    = true;\n    return \$args;\n} );",
			],
			[
				'name'     => 'wpts_register_post_types',
				'type'     => 'action',
				'category' => 'Custom Post Types',
				'file'     => 'includes/cpt/class-cpt-manager.php - register()',
				'params'   => '(no arguments)',
				'desc'     => 'Fires at the right time in the WordPress init sequence to register your own custom post types alongside the plugin\'s - guarantees correct ordering relative to the plugin\'s own CPT registration.',
				'example'  => "add_action( 'wpts_register_post_types', function () {\n    register_post_type( 'product', [\n        'label'        => 'Products',\n        'public'       => true,\n        'show_in_rest' => true,\n        'supports'     => [ 'title', 'editor', 'thumbnail', 'excerpt' ],\n    ] );\n} );",
			],

			// MULTILINGUAL

			[
				'name'     => 'wpts_register_wpml_strings',
				'type'     => 'action',
				'category' => 'Multilingual',
				'file'     => 'includes/i18n/class-i18n-loader.php',
				'params'   => '(no arguments)',
				'desc'     => 'Fires when WPML String Translation should register any custom UI strings your integration adds (e.g. custom placeholder text per language).',
				'example'  => "add_action( 'wpts_register_wpml_strings', function () {\n    if ( function_exists( 'icl_register_string' ) ) {\n        icl_register_string( 'my-plugin', 'search_placeholder', 'Search our docs…' );\n    }\n} );",
			],

			// SETTINGS & LIFECYCLE

			[
				'name'     => 'wpts_settings_saved',
				'type'     => 'action',
				'category' => 'Settings & Lifecycle',
				'file'     => 'includes/admin/class-settings.php - save()',
				'params'   => '$data (array, raw submitted settings)',
				'desc'     => 'Fires after any plugin setting is saved, from any of the settings pages (General, Cache, Tracking).',
				'example'  => "add_action( 'wpts_settings_saved', function ( array \$data ) {\n    error_log( '[WPTS] Settings were updated: ' . implode( ', ', array_keys( \$data ) ) );\n} );",
			],
			[
				'name'     => 'wpts_settings_reset',
				'type'     => 'action',
				'category' => 'Settings & Lifecycle',
				'file'     => 'includes/admin/class-settings.php - reset()',
				'params'   => '(no arguments)',
				'desc'     => 'Fires when plugin settings are restored to factory defaults.',
				'example'  => "add_action( 'wpts_settings_reset', function () {\n    error_log( '[WPTS] Settings were reset to factory defaults.' );\n} );",
			],
			[
				'name'     => 'wpts_booted',
				'type'     => 'action',
				'category' => 'Settings & Lifecycle',
				'file'     => 'includes/class-core.php - boot() (end)',
				'params'   => '$core (Core instance)',
				'desc'     => 'Fires once the plugin has fully finished booting on plugins_loaded - the safe, guaranteed place to hook any late initialization logic that depends on the plugin being ready.',
				'example'  => "add_action( 'wpts_booted', function ( \\WPTS\\Core \$core ) {\n    // Safe to call get_engine() here - the plugin is fully initialized\n    \$engine = \$core->get_engine();\n    error_log( '[WPTS] Plugin booted, active engine: ' . \$engine->get_engine_driver() );\n} );",
			],

			// ANALYTICS & TRACKING

			[
				'name'     => 'wpts_search_tracked',
				'type'     => 'action',
				'category' => 'Analytics & Tracking',
				'file'     => 'includes/class-tracker.php - track_search()',
				'params'   => '$query (string), $results_count (int), $duration_ms (float), $from_cache (bool)',
				'desc'     => 'Fires when a frontend search query is logged into the analytics telemetry database.',
				'example'  => "add_action( 'wpts_search_tracked', function ( string \$query, int \$results, float \$duration, bool \$cached ) {\n    // Send search event to Google Analytics or external warehouse\n    if ( \$results === 0 ) {\n        error_log( \"[WPTS Analytics] Zero results for query: {\$query}\" );\n    }\n}, 10, 4 );",
			],
			[
				'name'     => 'wpts_click_tracked',
				'type'     => 'action',
				'category' => 'Analytics & Tracking',
				'file'     => 'includes/class-tracker.php - record_click()',
				'params'   => '$query (string), $post_id (int), $position (int)',
				'desc'     => 'Fires when a user clicks on a search result card or dropdown item.',
				'example'  => "add_action( 'wpts_click_tracked', function ( string \$query, int \$post_id, int \$pos ) {\n    // Log CTR click telemetry\n    error_log( sprintf( '[WPTS CTR] User searched \"%s\" and clicked post #%d at rank #%d', \$query, \$post_id, \$pos ) );\n}, 10, 3 );",
			],

			// AI VECTOR SEARCH & ADVANCED

			[
				'name'     => 'wpts_vector_embedding',
				'type'     => 'filter',
				'category' => 'AI Vector Search',
				'file'     => 'includes/search/class-vector-search.php - get_embedding()',
				'params'   => '$embedding (array|null), $text (string), $is_query (bool)',
				'desc'     => 'Filter or provide custom semantic AI embeddings for documents or search queries.',
				'example'  => "add_filter( 'wpts_vector_embedding', function ( \$embedding, string \$text, bool \$is_query ) {\n    // Return custom precomputed embedding vector\n    return \$embedding;\n}, 10, 3 );",
			],
			[
				'name'     => 'wpts_role_allowed_post_types',
				'type'     => 'filter',
				'category' => 'Security & Roles',
				'file'     => 'includes/security/class-role-restrictions.php - filter_allowed_post_types()',
				'params'   => '$allowed (array of post type slugs), $current_role (string)',
				'desc'     => 'Filter the allowed post types visible in search results for the current logged-in user role or guest.',
				'example'  => "add_filter( 'wpts_role_allowed_post_types', function ( array \$allowed, string \$role ) {\n    // VIP members can search exclusive 'case_study' post types\n    if ( 'vip_member' === \$role ) {\n        \$allowed[] = 'case_study';\n    }\n    return \$allowed;\n}, 10, 2 );",
			],

		];
	}
}
