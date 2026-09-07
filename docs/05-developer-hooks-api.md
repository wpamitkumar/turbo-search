# Chapter 5: Developer Hooks & Actions API Reference

Turbo Search provides an enterprise-grade developer API with 41 verified public hooks and filters covering every phase of document indexing, query transformation, multi-tier zero-downtime fallback, caching, REST endpoints, analytics, and security.

---

## 📑 Table of Contents

- [1. Indexing & Documents (6 hooks)](#1-indexing-and-documents)
- [2. Caching (8 hooks)](#2-caching)
- [3. Search & Results (8 hooks)](#3-search-and-results)
- [4. REST API (2 hooks)](#4-rest-api)
- [5. Typesense (4 hooks)](#5-typesense)
- [6. Frontend & Blocks (2 hooks)](#6-frontend-and-blocks)
- [7. Custom Post Types (3 hooks)](#7-custom-post-types)
- [8. Multilingual (1 hooks)](#8-multilingual)
- [9. Settings & Lifecycle (3 hooks)](#9-settings-and-lifecycle)
- [10. Analytics & Tracking (2 hooks)](#10-analytics-and-tracking)
- [11. AI Vector Search (1 hooks)](#11-ai-vector-search)
- [12. Security & Roles (1 hooks)](#12-security-and-roles)

---

## 1. Indexing & Documents

### `wpts_indexable_document` *(filter)*

Modify the document array built from a post before it is sent to the search engine. Runs on every save, for every configured post type.

* **Type**: `filter`
* **Location**: `includes/class-core.php - on_save_post()`
* **Parameters**: `$document (array), $post (WP_Post)`

**Code Example**:
```php
add_filter( 'wpts_indexable_document', function ( array $document, \WP_Post $post ) {
    // Prefix the indexed title with the post's primary category
    $terms = get_the_category( $post->ID );
    if ( ! empty( $terms ) ) {
        $document['title'] = '[' . $terms[0]->name . '] ' . $document['title'];
    }
    return $document;
}, 10, 2 );
```

---

### `wpts_before_index_document` *(filter)*

Last-chance modification of the document immediately before it is written to the active engine (MySQL or Typesense). Fires after wpts_indexable_document.

* **Type**: `filter`
* **Location**: `includes/cache/class-mysql.php + class-typesense.php - upsert()`
* **Parameters**: `$document (array)`

**Code Example**:
```php
add_filter( 'wpts_before_index_document', function ( array $document ) {
    // Strip a noisy phrase from content right before indexing,
    // without affecting what is stored in the post itself.
    $document['content'] = str_replace( 'Advertisement', '', $document['content'] );
    return $document;
} );
```

---

### `wpts_after_index_document` *(action)*

Fires after a single post has been indexed (or failed to index). Useful for custom logging, webhooks, or alerting on failures.

* **Type**: `action`
* **Location**: `includes/cache/class-mysql.php + class-typesense.php - upsert()`
* **Parameters**: `$document (array), $success (bool), $engine (string)`

**Code Example**:
```php
add_action( 'wpts_after_index_document', function ( array $document, bool $success, string $engine ) {
    if ( ! $success ) {
        error_log( sprintf(
            '[MyPlugin] Failed to index post #%s via %s',
            $document['id'] ?? '?',
            $engine
        ) );
    }
}, 10, 3 );
```

---

### `wpts_reindex_complete` *(action)*

Fires once a full re-index operation (via admin UI, AJAX chunking, or WP-CLI) finishes. $count is the number of documents now in the index.

* **Type**: `action`
* **Location**: `includes/cache/class-mysql.php + class-typesense.php - reindex_chunk()`
* **Parameters**: `$count (int), $engine (string)`

**Code Example**:
```php
add_action( 'wpts_reindex_complete', function ( int $count, string $engine ) {
    // Notify Slack whenever a re-index finishes
    wp_remote_post( 'https://hooks.slack.com/services/YOUR/WEBHOOK/URL', [
        'body' => wp_json_encode( [
            'text' => "🔎 Re-index complete via {$engine}: {$count} documents indexed.",
        ] ),
        'headers' => [ 'Content-Type' => 'application/json' ],
    ] );
}, 10, 2 );
```

---

### `wpts_after_index_bulk` *(action)*

Fires after a bulk batch of documents has been submitted for indexing into Typesense or Elasticsearch.

* **Type**: `action`
* **Location**: `includes/cache/class-typesense.php + class-elasticsearch.php - upsert_bulk()`
* **Parameters**: `$documents (array), $success (bool), $engine (string)`

**Code Example**:
```php
add_action( 'wpts_after_index_bulk', function ( array $documents, bool $success, string $engine ) {
    if ( ! $success ) {
        error_log( sprintf( '[WPTS] Bulk indexing batch of %d items failed on %s', count( $documents ), $engine ) );
    }
}, 10, 3 );
```

---

### `wpts_index_flushed` *(action)*

Fires when an index or external collection is wiped/cleared by an administrator via the admin dashboard or WP-CLI.

* **Type**: `action`
* **Location**: `includes/api/class-rest-admin.php - clear_index()`
* **Parameters**: `$engine (string)`

**Code Example**:
```php
add_action( 'wpts_index_flushed', function ( string $engine ) {
    error_log( sprintf( '[WPTS] Index cleared for engine: %s', $engine ) );
} );
```

---

## 2. Caching

### `wpts_cache_ttl` *(filter)*

Modify the cache TTL (time-to-live, in seconds) for a specific result before it is stored. Runs on every cache write, for any driver.

* **Type**: `filter`
* **Location**: `includes/cache/class-cache-manager.php - cache_set()`
* **Parameters**: `$ttl (int, seconds), $key (string), $driver (string)`

**Code Example**:
```php
add_filter( 'wpts_cache_ttl', function ( int $ttl, string $key, string $driver ) {
    // Cache empty/rare queries for longer, popular ones for a shorter time
    // to keep results fresher without losing the speed benefit overall.
    return $ttl;
}, 10, 3 );

// A more concrete example - shorten TTL during a flash sale so new
// products show up in search results faster:
add_filter( 'wpts_cache_ttl', function ( int $ttl, string $key, string $driver ) {
    return get_option( 'my_flash_sale_active' ) ? 30 : $ttl;
}, 10, 3 );
```

---

### `wpts_cache_key` *(filter)*

Extend the raw string used to build a search result's cache key, before it is hashed. Use this if your integration adds context that should produce a DIFFERENT cached result for the same query (e.g. per-user personalization).

* **Type**: `filter`
* **Location**: `includes/cache/class-cache-manager.php - make_key()`
* **Parameters**: `$raw (string, pre-hash key material), $query (string), $filters (array)`

**Code Example**:
```php
add_filter( 'wpts_cache_key', function ( string $raw, string $query, array $filters ) {
    // Cache results separately per logged-in user role, so an admin's
    // search doesn't get served a visitor's cached (possibly filtered) results.
    $raw .= '|role:' . ( wp_get_current_user()->roles[0] ?? 'guest' );
    return $raw;
}, 10, 3 );
```

---

### `wpts_cache_hit` *(action)*

Fires when a search result is served from cache instead of hitting the search engine. Powers the built-in Cache Statistics dashboard toggle.

* **Type**: `action`
* **Location**: `includes/cache/class-cache-manager.php - search()`
* **Parameters**: `$key (string), $driver (string)`

**Code Example**:
```php
add_action( 'wpts_cache_hit', function ( string $key, string $driver ) {
    do_action( 'my_plugin_metric', 'wpts.cache.hit', [ 'driver' => $driver ] );
}, 10, 2 );
```

---

### `wpts_cache_miss` *(action)*

Fires when a search was not found in cache and had to hit the search engine directly. Powers the built-in Cache Statistics dashboard toggle.

* **Type**: `action`
* **Location**: `includes/cache/class-cache-manager.php - search()`
* **Parameters**: `$key (string), $driver (string)`

**Code Example**:
```php
add_action( 'wpts_cache_miss', function ( string $key, string $driver ) {
    do_action( 'my_plugin_metric', 'wpts.cache.miss', [ 'driver' => $driver ] );
}, 10, 2 );
```

---

### `wpts_cache_flushed` *(action)*

Fires after the entire result cache is flushed (via "Flush All Cache" button, WP-CLI, or automatically before a full re-index).

* **Type**: `action`
* **Location**: `includes/cache/class-cache-manager.php - flush_all()`
* **Parameters**: `$driver (string)`

**Code Example**:
```php
add_action( 'wpts_cache_flushed', function ( string $driver ) {
    error_log( "[WPTS] Cache fully flushed (driver: {$driver}) at " . current_time( 'mysql' ) );
} );
```

---

### `wpts_before_cache_flush_post` *(action)*

Fires before a single post's cached search entries are invalidated (e.g. right after that post is saved).

* **Type**: `action`
* **Location**: `includes/cache/class-cache-manager.php - flush_post()`
* **Parameters**: `$post_id (int), $driver (string)`

**Code Example**:
```php
add_action( 'wpts_before_cache_flush_post', function ( int $post_id, string $driver ) {
    error_log( "About to invalidate cache for post #{$post_id}" );
}, 10, 2 );
```

---

### `wpts_after_cache_flush_post` *(action)*

Fires after a single post's cached search entries have been invalidated.

* **Type**: `action`
* **Location**: `includes/cache/class-cache-manager.php - flush_post()`
* **Parameters**: `$post_id (int), $driver (string)`

**Code Example**:
```php
add_action( 'wpts_after_cache_flush_post', function ( int $post_id, string $driver ) {
    // Warm a related cache your own plugin maintains
    my_plugin_rebuild_related_posts_cache( $post_id );
}, 10, 2 );
```

---

### `wpts_cache_connect_error` *(action)*

Fires when a Redis or Memcached connection attempt fails. Good hook point for ops alerting.

* **Type**: `action`
* **Location**: `includes/cache/class-cache-manager.php - connect_redis() / connect_memcached()`
* **Parameters**: `$driver (string), $message (string)`

**Code Example**:
```php
add_action( 'wpts_cache_connect_error', function ( string $driver, string $message ) {
    wp_mail(
        get_option( 'admin_email' ),
        "Turbo Search: {$driver} connection failed",
        $message
    );
}, 10, 2 );
```

---

## 3. Search & Results

### `wpts_result_hit` *(filter)*

Modify a single search result before it is returned. Fires once per hit, identically for REST requests and the AJAX fallback. Use this to add custom fields or rewrite URLs.

* **Type**: `filter`
* **Location**: `includes/cache/class-mysql.php + class-typesense.php - search()`
* **Parameters**: `$hit (array), $query (string)`

**Code Example**:
```php
add_filter( 'wpts_result_hit', function ( array $hit, string $query ) {
    // Add a "read time" estimate to every result
    $post = get_post( $hit['post_id'] );
    if ( $post ) {
        $words = str_word_count( wp_strip_all_tags( $post->post_content ) );
        $hit['read_time_minutes'] = max( 1, (int) round( $words / 200 ) );
    }
    return $hit;
}, 10, 2 );
```

---

### `wpts_mysql_where_clauses` *(filter)*

Add custom SQL WHERE conditions to the MySQL search query. Must return the same [conditions, values] pair shape.

* **Type**: `filter`
* **Location**: `includes/cache/class-mysql.php - search()`
* **Parameters**: `[$where_parts, $where_values] (array pair), $query (string), $filters (array)`

**Code Example**:
```php
add_filter( 'wpts_mysql_where_clauses', function ( array $pair, string $query, array $filters ) {
    [ $conditions, $values ] = $pair;

    // Only ever show posts from the last 2 years in search results
    $conditions[] = 'indexed_at >= %s';
    $values[]     = gmdate( 'Y-m-d', strtotime( '-2 years' ) );

    return [ $conditions, $values ];
}, 10, 3 );
```

---

### `wpts_synonym_groups` *(filter)*

Filter or programmatically inject custom synonym mappings into the active search query expander.

* **Type**: `filter`
* **Location**: `includes/search/class-synonyms.php - get_all()`
* **Parameters**: `$groups (array of comma-separated string synonym groups)`

**Code Example**:
```php
add_filter( 'wpts_synonym_groups', function ( array $groups ) {
    // Dynamically inject custom product synonym group
    $groups[] = 'sneakers, shoes, trainers, footwear';
    return $groups;
} );
```

---

### `wpts_search_engine` *(filter)*

Swap in a completely custom search engine object. Must implement search(), upsert(), delete(), reindex_all(), reindex_chunk(), get_driver(), get_indexed_count().

* **Type**: `filter`
* **Location**: `includes/class-core.php - get_engine()`
* **Parameters**: `$engine (Typesense|MySQL|Elasticsearch object), $settings (array)`

**Code Example**:
```php
add_filter( 'wpts_search_engine', function ( $engine, array $settings ) {
    // Force MySQL regardless of Typesense settings, e.g. on staging
    if ( wp_get_environment_type() === 'staging' ) {
        return new \WPTS\Cache\MySQL();
    }
    return $engine;
}, 10, 2 );
```

---

### `wpts_engine_fallback` *(action)*

Fires when the configured engine (e.g. Typesense) returned no results or errored during a search, and the plugin automatically fell back to MySQL.

* **Type**: `action`
* **Location**: `includes/cache/class-cache-manager.php - search()`
* **Parameters**: `$fallback_to (string), $original_engine_class (string)`

**Code Example**:
```php
add_action( 'wpts_engine_fallback', function ( string $fallback_to, string $original_class ) {
    error_log( "[WPTS] {$original_class} failed - search served by {$fallback_to} instead." );
}, 10, 2 );
```

---

### `wpts_typesense_fallback` *(action)*

Fires when Typesense is configured but was found unreachable while resolving which engine to use - before any search even runs. Currently $reason is always "unreachable".

* **Type**: `action`
* **Location**: `includes/class-core.php - get_engine()`
* **Parameters**: `$reason (string)`

**Code Example**:
```php
add_action( 'wpts_typesense_fallback', function ( string $reason ) {
    // Email the admin once per hour if Typesense goes down
    $last_notified = get_transient( 'wpts_typesense_down_notice' );
    if ( ! $last_notified ) {
        wp_mail( get_option( 'admin_email' ), 'Typesense unreachable', "Reason: {$reason}" );
        set_transient( 'wpts_typesense_down_notice', 1, HOUR_IN_SECONDS );
    }
} );
```

---

### `wpts_core_fallback_query_args` *(filter)*

Filter the arguments passed to WP_Query when the engine performs an emergency fallback to WordPress Core search.

* **Type**: `filter`
* **Location**: `includes/search/class-wp-core-fallback.php - search()`
* **Parameters**: `$query_args (array), $query (string), $filters (array)`

**Code Example**:
```php
add_filter( 'wpts_core_fallback_query_args', function ( array $args, string $query, array $filters ) {
    // Exclude password-protected posts during Core fallback
    $args['has_password'] = false;
    return $args;
}, 10, 3 );
```

---

### `wpts_search_error` *(action)*

Fires when the search engine itself returns an error response while executing a query (e.g. Typesense API error).

* **Type**: `action`
* **Location**: `includes/cache/class-typesense.php - search()`
* **Parameters**: `$error (WP_Error), $engine (string)`

**Code Example**:
```php
add_action( 'wpts_search_error', function ( \WP_Error $error, string $engine ) {
    error_log( "[WPTS] {$engine} search error: " . $error->get_error_message() );
}, 10, 2 );
```

---

## 4. REST API

### `wpts_rest_search_query` *(filter)*

Modify or rewrite the search query string before it is executed. Runs only on REST requests (not the AJAX fallback). Good for synonym expansion.

* **Type**: `filter`
* **Location**: `includes/api/class-rest-search.php - handle_search()`
* **Parameters**: `$query (string), $request (WP_REST_Request)`

**Code Example**:
```php
add_filter( 'wpts_rest_search_query', function ( string $query, \WP_REST_Request $request ) {
    $synonyms = [ 'js' => 'javascript', 'wp' => 'wordpress' ];
    $lower    = strtolower( trim( $query ) );
    return $synonyms[ $lower ] ?? $query;
}, 10, 2 );
```

---

### `wpts_rest_search_results` *(filter)*

Modify the full { hits, found, page } payload before it is sent to the client. Runs only on REST requests.

* **Type**: `filter`
* **Location**: `includes/api/class-rest-search.php - handle_search()`
* **Parameters**: `$results (array), $query (string), $request (WP_REST_Request)`

**Code Example**:
```php
add_filter( 'wpts_rest_search_results', function ( array $results, string $query, \WP_REST_Request $request ) {
    // Add a top-level flag when very few results are found
    $results['low_results'] = ( $results['found'] ?? 0 ) < 3;
    return $results;
}, 10, 3 );
```

---

## 5. Typesense

### `wpts_typesense_search_params` *(filter)*

Customize the raw parameters sent to Typesense's /documents/search endpoint (query_by, num_typos, highlight settings, etc).

* **Type**: `filter`
* **Location**: `includes/cache/class-typesense.php - search()`
* **Parameters**: `$params (array), $query (string), $filters (array)`

**Code Example**:
```php
add_filter( 'wpts_typesense_search_params', function ( array $params, string $query, array $filters ) {
    // Allow more typo tolerance for longer queries
    if ( strlen( $query ) > 12 ) {
        $params['num_typos'] = 3;
    }
    return $params;
}, 10, 3 );
```

---

### `wpts_typesense_filter_parts` *(filter)*

Add custom filter_by clauses to Typesense queries (each entry is a Typesense filter expression, joined with &&).

* **Type**: `filter`
* **Location**: `includes/cache/class-typesense.php - build_filter_string()`
* **Parameters**: `$parts (array of strings), $filters (array)`

**Code Example**:
```php
add_filter( 'wpts_typesense_filter_parts', function ( array $parts, array $filters ) {
    // Only ever return posts by a specific author ID
    $parts[] = 'author_id:=42';
    return $parts;
}, 10, 2 );
```

---

### `wpts_typesense_collection_schema` *(filter)*

Customize the Typesense collection schema before it is created - add extra indexed fields, change field types, etc. Only takes effect the next time the collection is (re)created.

* **Type**: `filter`
* **Location**: `includes/cache/class-typesense.php - create_collection()`
* **Parameters**: `$schema (array), $collection (string)`

**Code Example**:
```php
add_filter( 'wpts_typesense_collection_schema', function ( array $schema, string $collection ) {
    $schema['fields'][] = [ 'name' => 'view_count', 'type' => 'int32', 'optional' => true ];
    return $schema;
}, 10, 2 );
```

---

### `wpts_typesense_collection_flushed` *(action)*

Fires after the Typesense collection is dropped via the "Flush Typesense Data" button, WP-CLI, or the flush endpoint.

* **Type**: `action`
* **Location**: `includes/cache/class-typesense.php - flush_collection()`
* **Parameters**: `$collection (string)`

**Code Example**:
```php
add_action( 'wpts_typesense_collection_flushed', function ( string $collection ) {
    error_log( "[WPTS] Typesense collection '{$collection}' was flushed." );
} );
```

---

## 6. Frontend & Blocks

### `wpts_block_render_attrs` *(filter)*

Modify the Gutenberg search block's attributes before its frontend HTML is rendered - placeholder text, colors, debounce/throttle values, etc.

* **Type**: `filter`
* **Location**: `includes/class-block.php - render()`
* **Parameters**: `$attrs (array, merged with defaults), $raw_attrs (array, original from editor)`

**Code Example**:
```php
add_filter( 'wpts_block_render_attrs', function ( array $attrs, array $raw_attrs ) {
    // Force dark theme for this block everywhere it appears
    $attrs['theme'] = 'dark';
    return $attrs;
}, 10, 2 );
```

---

### `wpts_shortcode_atts` *(filter)*

Modify [wpts_search] shortcode attributes before the widget is rendered - same options as the shortcode itself (placeholder, theme, debounce, etc).

* **Type**: `filter`
* **Location**: `templates/shortcode.php - wpts_render_search_shortcode()`
* **Parameters**: `$atts (array)`

**Code Example**:
```php
add_filter( 'wpts_shortcode_atts', function ( array $atts ) {
    // Always restrict shortcode search to the "docs" post type
    $atts['post_type'] = 'docs';
    return $atts;
} );
```

---

## 7. Custom Post Types

### `wpts_register_sample_cpt` *(filter)*

Return false to prevent the plugin's demo "Resource" custom post type from registering - useful if you don't need the example CPT in production.

* **Type**: `filter`
* **Location**: `includes/cpt/class-cpt-manager.php - register()`
* **Parameters**: `$register (bool)`

**Code Example**:
```php
add_filter( 'wpts_register_sample_cpt', '__return_false' );
```

---

### `wpts_resource_cpt_args` *(filter)*

Customize the register_post_type() arguments used for the demo "Resource" CPT (only relevant if wpts_register_sample_cpt is not disabled).

* **Type**: `filter`
* **Location**: `includes/cpt/class-cpt-manager.php - register()`
* **Parameters**: `$args (array)`

**Code Example**:
```php
add_filter( 'wpts_resource_cpt_args', function ( array $args ) {
    $args['menu_icon'] = 'dashicons-book';
    $args['public']    = true;
    return $args;
} );
```

---

### `wpts_register_post_types` *(action)*

Fires at the right time in the WordPress init sequence to register your own custom post types alongside the plugin's - guarantees correct ordering relative to the plugin's own CPT registration.

* **Type**: `action`
* **Location**: `includes/cpt/class-cpt-manager.php - register()`
* **Parameters**: `(no arguments)`

**Code Example**:
```php
add_action( 'wpts_register_post_types', function () {
    register_post_type( 'product', [
        'label'        => 'Products',
        'public'       => true,
        'show_in_rest' => true,
        'supports'     => [ 'title', 'editor', 'thumbnail', 'excerpt' ],
    ] );
} );
```

---

## 8. Multilingual

### `wpts_register_wpml_strings` *(action)*

Fires when WPML String Translation should register any custom UI strings your integration adds (e.g. custom placeholder text per language).

* **Type**: `action`
* **Location**: `includes/i18n/class-i18n-loader.php`
* **Parameters**: `(no arguments)`

**Code Example**:
```php
add_action( 'wpts_register_wpml_strings', function () {
    if ( function_exists( 'icl_register_string' ) ) {
        icl_register_string( 'my-plugin', 'search_placeholder', 'Search our docs…' );
    }
} );
```

---

## 9. Settings & Lifecycle

### `wpts_settings_saved` *(action)*

Fires after any plugin setting is saved, from any of the settings pages (General, Cache, Tracking).

* **Type**: `action`
* **Location**: `includes/admin/class-settings.php - save()`
* **Parameters**: `$data (array, raw submitted settings)`

**Code Example**:
```php
add_action( 'wpts_settings_saved', function ( array $data ) {
    error_log( '[WPTS] Settings were updated: ' . implode( ', ', array_keys( $data ) ) );
} );
```

---

### `wpts_settings_reset` *(action)*

Fires when plugin settings are restored to factory defaults.

* **Type**: `action`
* **Location**: `includes/admin/class-settings.php - reset()`
* **Parameters**: `(no arguments)`

**Code Example**:
```php
add_action( 'wpts_settings_reset', function () {
    error_log( '[WPTS] Settings were reset to factory defaults.' );
} );
```

---

### `wpts_booted` *(action)*

Fires once the plugin has fully finished booting on plugins_loaded - the safe, guaranteed place to hook any late initialization logic that depends on the plugin being ready.

* **Type**: `action`
* **Location**: `includes/class-core.php - boot() (end)`
* **Parameters**: `$core (Core instance)`

**Code Example**:
```php
add_action( 'wpts_booted', function ( \WPTS\Core $core ) {
    // Safe to call get_engine() here - the plugin is fully initialized
    $engine = $core->get_engine();
    error_log( '[WPTS] Plugin booted, active engine: ' . $engine->get_engine_driver() );
} );
```

---

## 10. Analytics & Tracking

### `wpts_search_tracked` *(action)*

Fires when a frontend search query is logged into the analytics telemetry database.

* **Type**: `action`
* **Location**: `includes/class-tracker.php - track_search()`
* **Parameters**: `$query (string), $results_count (int), $duration_ms (float), $from_cache (bool)`

**Code Example**:
```php
add_action( 'wpts_search_tracked', function ( string $query, int $results, float $duration, bool $cached ) {
    // Send search event to Google Analytics or external warehouse
    if ( $results === 0 ) {
        error_log( "[WPTS Analytics] Zero results for query: {$query}" );
    }
}, 10, 4 );
```

---

### `wpts_click_tracked` *(action)*

Fires when a user clicks on a search result card or dropdown item.

* **Type**: `action`
* **Location**: `includes/class-tracker.php - record_click()`
* **Parameters**: `$query (string), $post_id (int), $position (int)`

**Code Example**:
```php
add_action( 'wpts_click_tracked', function ( string $query, int $post_id, int $pos ) {
    // Log CTR click telemetry
    error_log( sprintf( '[WPTS CTR] User searched "%s" and clicked post #%d at rank #%d', $query, $post_id, $pos ) );
}, 10, 3 );
```

---

## 11. AI Vector Search

### `wpts_vector_embedding` *(filter)*

Filter or provide custom semantic AI embeddings for documents or search queries.

* **Type**: `filter`
* **Location**: `includes/search/class-vector-search.php - get_embedding()`
* **Parameters**: `$embedding (array|null), $text (string), $is_query (bool)`

**Code Example**:
```php
add_filter( 'wpts_vector_embedding', function ( $embedding, string $text, bool $is_query ) {
    // Return custom precomputed embedding vector
    return $embedding;
}, 10, 3 );
```

---

## 12. Security & Roles

### `wpts_role_allowed_post_types` *(filter)*

Filter the allowed post types visible in search results for the current logged-in user role or guest.

* **Type**: `filter`
* **Location**: `includes/security/class-role-restrictions.php - filter_allowed_post_types()`
* **Parameters**: `$allowed (array of post type slugs), $current_role (string)`

**Code Example**:
```php
add_filter( 'wpts_role_allowed_post_types', function ( array $allowed, string $role ) {
    // VIP members can search exclusive 'case_study' post types
    if ( 'vip_member' === $role ) {
        $allowed[] = 'case_study';
    }
    return $allowed;
}, 10, 2 );
```

---
