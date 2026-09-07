# Chapter 9: Complete Settings & Configuration Reference

This chapter contains the complete reference guide for **all 72 settings** available in Turbo Search, organized by admin panel tab and category.

---

## 📑 Settings Directory & Table of Contents

1. [⚙️ General Search Settings](#1-general-search-settings)
2. [⚖️ Field Relevance Weights](#2-field-relevance-weights)
3. [📖 Synonyms & Query Expansion](#3-synonyms-query-expansion)
4. [🗂️ Content Coverage & Documents](#4-content-coverage-documents)
5. [🚀 Search Engine Drivers](#5-search-engine-drivers)
6. [🧠 Hybrid AI Vector Search](#6-hybrid-ai-vector-search)
7. [🎨 Frontend UI & UX Features](#7-frontend-ui-ux-features)
8. [⚡ Cache & Performance](#8-cache-performance)
9. [🔄 Background Scheduled Sync](#9-background-scheduled-sync)
10. [📊 Analytics, CTR & A/B Testing](#10-analytics-ctr-ab-testing)
11. [🔐 Security, GDPR & Multisite](#11-security-gdpr-multisite)

---

## 1. General Search Settings

Navigate to: **Turbo Search → Search Settings → General**

### 1.1 `post_types`: Post Types to Index
* **Option Key**: `wpts_post_types`
* **Type**: `array`
* **Default**: `['post', 'page']`
* **Description**: Selects which WordPress post types are indexed and returned in search queries. Supports standard posts, pages, attachments, WooCommerce products (`product`), and any registered Custom Post Types (CPTs) e.g., `portfolio`, `docs`, `course`, `recipe`.
* **Recommendation**: Enable `product` for WooCommerce stores and `attachment` if document search is desired.

### 1.2 `enable_frontend_search`: Enable Frontend Live Search
* **Option Key**: `wpts_enable_frontend_search`
* **Type**: `bool`
* **Default**: `true`
* **Description**: Master switch for frontend search functionality. When enabled, registers shortcodes, Gutenberg blocks, AJAX hooks, and REST search endpoints.
* **Recommendation**: Keep `true` unless performing scheduled maintenance.

### 1.3 `debounce_ms`: Search Debounce Delay
* **Option Key**: `wpts_debounce_ms`
* **Type**: `int` (Milliseconds: `50`–`1000`)
* **Default**: `200`
* **Description**: The delay in milliseconds after the user stops typing before the search query is dispatched to the server. Prevents unnecessary server queries on every keystroke.
* **Recommendation**: `150`–`200ms` for ultra-fast response; `300`–`400ms` for high-traffic shared hosting servers.

### 1.4 `results_per_page`: Results Dropdown Limit
* **Option Key**: `wpts_results_per_page`
* **Type**: `int` (`1`–`50`)
* **Default**: `10`
* **Description**: Maximum number of search results returned in the live dropdown or spotlight modal per query.
* **Recommendation**: `8`–`10` for desktop dropdowns; `5`–`6` for mobile screens.

### 1.5 `highlight_results`: Keyword Highlighting (KWIC)
* **Option Key**: `wpts_highlight_results`
* **Type**: `bool`
* **Default**: `true`
* **Description**: Highlights matched search terms inside result titles and excerpts using `<mark class="wpts-mark">`.
* **Recommendation**: Keep `true` for superior visual feedback and user clarity.

### 1.6 `enable_boolean_search`: Boolean Search Operators
* **Option Key**: `wpts_enable_boolean_search`
* **Type**: `bool`
* **Default**: `true`
* **Description**: Allows advanced search syntax in queries:
  * `+term`: Term must be present.
  * `-term`: Term must be excluded.
  * `*`: Wildcard prefix matching (`comp*` matches *computer*, *computing*, *company*).
* **Recommendation**: Keep `true` for power-user flexibility.

### 1.7 `enable_phrase_search`: Exact Phrase Matching
* **Option Key**: `wpts_enable_phrase_search`
* **Type**: `bool`
* **Default**: `true`
* **Description**: Enclosing query terms in double quotes (`"wordpress search"`) requires words to match in exact sequence.
* **Recommendation**: Keep `true`.

### 1.8 `enable_spelling_suggestions`: "Did You Mean?" Suggestions
* **Option Key**: `wpts_enable_spelling_suggestions`
* **Type**: `bool`
* **Default**: `true`
* **Description**: Uses Levenshtein distance and character trigram matrix matching to suggest corrections when a query returns low or zero hits.
* **Recommendation**: Keep `true` to reduce zero-result bounces.

### 1.9 `admin_auto_refresh`: Admin Dashboard Live Polling Rate
* **Option Key**: `wpts_admin_auto_refresh`
* **Type**: `int` (`0`, `10`, `30`, `60` seconds)
* **Default**: `30`
* **Description**: The background polling frequency for live analytics graphs, CTR telemetry, and index coverage counters in the admin panel. Set to `0` to pause live polling.
* **Recommendation**: `30` seconds.

---

## 2. Field Relevance Weights

Navigate to: **Turbo Search → Search Settings → Field Weights**

Field weights determine how search relevance scores are calculated. Higher values prioritize matches in that specific field.

```
       Relevance Score = (Title × 10) + (Excerpt × 5) + (Taxonomies × 4) + (Meta × 3) + (Content × 1)
```

### 2.1 `weight_title`: Title Match Weight
* **Option Key**: `wpts_weight_title`
* **Type**: `int` (`1`–`20`) | **Default**: `10`
* **Description**: Multiplier applied when search terms appear in the document title.

### 2.2 `weight_excerpt`: Excerpt Match Weight
* **Option Key**: `wpts_weight_excerpt`
* **Type**: `int` (`1`–`20`) | **Default**: `5`
* **Description**: Multiplier applied for matches in the manual post excerpt or WooCommerce short description.

### 2.3 `weight_content`: Body Content Match Weight
* **Option Key**: `wpts_weight_content`
* **Type**: `int` (`1`–`20`) | **Default**: `1`
* **Description**: Multiplier applied for matches in the main article body, page content, or document text.

### 2.4 `weight_taxonomies`: Category & Tag Match Weight
* **Option Key**: `wpts_weight_taxonomies`
* **Type**: `int` (`1`–`20`) | **Default**: `4`
* **Description**: Multiplier applied for matches in category names, post tags, product attributes, or custom taxonomy terms.

### 2.5 `weight_meta`: Custom Meta / ACF Weight
* **Option Key**: `wpts_weight_meta`
* **Type**: `int` (`1`–`20`) | **Default**: `3`
* **Description**: Multiplier applied for matches in indexed custom fields, postmeta, and ACF attributes.

### 2.6 `weight_comments`: Comments Match Weight
* **Option Key**: `wpts_weight_comments`
* **Type**: `int` (`1`–`20`) | **Default**: `1`
* **Description**: Multiplier applied for matches in approved user comments.

---

## 3. Synonyms & Query Expansion

Navigate to: **Turbo Search → Search Settings → Synonyms**

### 3.1 `enable_synonyms`: Enable Synonym Expansion
* **Option Key**: `wpts_enable_synonyms`
* **Type**: `bool`
* **Default**: `true`
* **Description**: Enables multi-way synonym expansion. When a visitor searches for any word in a synonym group, results for all related words are returned automatically.
* **Examples**:
  * `laptop, notebook, portable pc`
  * `sneakers, shoes, trainers, kicks, footwear`
  * `couch, sofa, settee, davenport`

---

## 4. Content Coverage & Documents

Navigate to: **Turbo Search → Search Settings → Content Coverage**

### 4.1 `search_in_meta`: Index Custom Fields & ACF
* **Option Key**: `wpts_search_in_meta`
* **Type**: `bool` | **Default**: `false`
* **Description**: Indexes public postmeta keys, Advanced Custom Fields (ACF), and custom post attributes into the searchable document body.

### 4.2 `index_thumbnails`: Include Image Thumbnails
* **Option Key**: `wpts_index_thumbnails`
* **Type**: `bool` | **Default**: `true`
* **Description**: Resolves and stores featured image URLs and WooCommerce catalog thumbnails directly in the search payload for zero-latency card rendering.

### 4.3 `index_comments`: Index Approved Comments
* **Option Key**: `wpts_index_comments`
* **Type**: `bool` | **Default**: `false`
* **Description**: Appends text from approved visitor comments to the search index.

### 4.4 `index_attachments`: PDF & Document Text Extraction
* **Option Key**: `wpts_index_attachments`
* **Type**: `bool` | **Default**: `false`
* **Description**: Enables full-text extraction for `.pdf`, `.docx`, `.txt`, `.csv`, `.tsv`, and `.md` files uploaded to the Media Library or attached to posts.
* **Recommendation**: Enable if you provide downloadable product manuals, brochures, reports, or documentation.

### 4.5 `index_woocommerce`: WooCommerce Product Data
* **Option Key**: `wpts_index_woocommerce`
* **Type**: `bool` | **Default**: `true`
* **Description**: Indexes WooCommerce SKUs, numeric prices, regular/sale price schedules, inventory status (`instock`, `outofstock`), total sales, and average review ratings.

---

## 5. Search Engine Drivers

Navigate to: **Turbo Search → Search Settings → Search Engines**

### 5.1 `search_engine`: Active Search Engine
* **Option Key**: `wpts_search_engine`
* **Type**: `string` (`mysql`, `typesense`, `elasticsearch`) | **Default**: `mysql`
* **Description**: Selects the active search engine backend.

### 5.2 Typesense Settings:
* **`typesense_host`**: Hostname or IP address (e.g. `localhost` or `xxx.typesense.net`).
* **`typesense_port`**: Connection port (`8108` for local, `443` for SSL).
* **`typesense_protocol`**: `http` or `https`.
* **`typesense_api_key`**: Admin or Search API Key.
* **`typesense_collection`**: Collection name in Typesense (`wpts_posts`).

### 5.3 Elasticsearch / OpenSearch Settings:
* **`elasticsearch_host`**: Endpoint URL (e.g. `https://search.example.com`).
* **`elasticsearch_port`**: Cluster port (Default: `9200`).
* **`elasticsearch_protocol`**: `http` or `https`.
* **`elasticsearch_username`**: HTTP Basic Auth username.
* **`elasticsearch_password`**: HTTP Basic Auth password.
* **`elasticsearch_api_key`**: Base64 Elasticsearch API Key.
* **`elasticsearch_index`**: Index name in Elasticsearch (`wpts_posts`).

---

## 6. Hybrid AI Vector Search

Navigate to: **Turbo Search → Search Settings → AI Vector Search**

### 6.1 `enable_vector_search`: Enable AI Semantic Search
* **Option Key**: `wpts_enable_vector_search`
* **Type**: `bool` | **Default**: `false`
* **Description**: Activates hybrid semantic search using high-dimensional AI vector embeddings.

### 6.2 `vector_provider`: Embedding Provider
* **Option Key**: `wpts_vector_provider`
* **Type**: `string` (`openai`, `ollama`, `custom`) | **Default**: `openai`
* **Description**: AI provider used to generate vector embeddings.

### 6.3 `vector_api_key`: OpenAI API Key
* **Option Key**: `wpts_vector_api_key`
* **Type**: `string` | **Default**: `""`
* **Description**: Secret API key for OpenAI (`sk-proj-...`).

### 6.4 `vector_model`: Embedding Model Name
* **Option Key**: `wpts_vector_model`
* **Type**: `string` | **Default**: `text-embedding-3-small`
* **Description**: Model name (`text-embedding-3-small`, `text-embedding-3-large`, `nomic-embed-text`).

### 6.5 `vector_endpoint`: Self-Hosted Endpoint
* **Option Key**: `wpts_vector_endpoint`
* **Type**: `string` | **Default**: `http://localhost:11434/api/embeddings`
* **Description**: HTTP endpoint for local Ollama or custom embedding microservices.

### 6.6 `vector_weight`: Semantic Reranking Weight
* **Option Key**: `wpts_vector_weight`
* **Type**: `float` (`0.0`–`1.0`) | **Default**: `0.3`
* **Description**: Balance between keyword relevance ($1 - w$) and AI semantic similarity ($w$).

---

## 7. Frontend UI & UX Features

Navigate to: **Turbo Search → Search Settings → Frontend & UX**

### 7.1 `enable_command_k_modal`: Command + K Spotlight Modal
* **Option Key**: `wpts_enable_command_k_modal`
* **Type**: `bool` | **Default**: `true`
* **Description**: Enables the `⌘K` / `Ctrl+K` global keyboard shortcut to launch the Spotlight search overlay.

### 7.2 `enable_voice_search`: Web Speech Voice Search
* **Option Key**: `wpts_enable_voice_search`
* **Type**: `bool` | **Default**: `true`
* **Description**: Displays the microphone button for speech recognition search.
  > **Note**: Under modern W3C browser security standards, Voice Search works only with an **HTTPS connection** (or `localhost` during local development). On plain HTTP connections, browsers block microphone access.

### 7.3 `enable_category_tabs_dropdown`: Category Filter Multi-Tabs
* **Option Key**: `wpts_enable_category_tabs_dropdown`
* **Type**: `bool` | **Default**: `true`
* **Description**: Displays filter tabs (`All`, `Products`, `Posts`, `Documentation`) at the top of the search dropdown.

### 7.4 `results_layout`: Default Results Layout
* **Option Key**: `wpts_results_layout`
* **Type**: `string` (`list`, `grid`, `card`) | **Default**: `list`
* **Description**: Default visual layout of search results across shortcodes, blocks, and dropdowns:
  * `list`: Classic vertical row layout with thumbnail, title, badge, and excerpt.
  * `grid`: Multi-column responsive cards with full-width thumbnail and action badges.
  * `card`: Ultra-dense Compact Card layout with 52×52px thumbnail and title/price badges on the right.

### 7.5 `woocommerce_quick_add_to_cart`: 1-Click WooCommerce Cart
* **Option Key**: `wpts_woocommerce_quick_add_to_cart`
* **Type**: `bool` | **Default**: `true`
* **Description**: Adds an instant AJAX **"Add to Cart"** button next to simple products in search results.

### 7.6 `enable_user_history`: Recent Search History
* **Option Key**: `wpts_enable_user_history`
* **Type**: `bool` | **Default**: `true`
* **Description**: Shows the user's last 5 recent searches when focusing an empty search input.

### 7.7 `enable_user_favorites`: Bookmark Star Icon
* **Option Key**: `wpts_enable_user_favorites`
* **Type**: `bool` | **Default**: `true`
* **Description**: Allows visitors to star (`★`) favorite queries for quick access.

### 7.8 `enable_archive_live_filter`: Live Search Archive Filtering & Query Interception
* **Option Key**: `wpts_enable_archive_live_filter`
* **Type**: `bool` | **Default**: `false`
* **Description**: Enhances standard WordPress theme search archive pages and main queries (`/?s=keyword`) with Turbo Search ranked results, with silent, zero-downtime fallback to native WordPress Core search if any search engine server or table is unavailable.

---

## 8. Cache & Performance

Navigate to: **Turbo Search → Cache & Performance**

### 8.1 `cache_driver`: Query Cache Backend
* **Option Key**: `wpts_cache_driver`
* **Type**: `string` (`auto`, `redis`, `memcached`, `object-cache`, `transient`) | **Default**: `auto`
* **Description**: Storage backend for search query caching.

### 8.2 `cache_ttl`: Query Cache TTL
* **Option Key**: `wpts_cache_ttl`
* **Type**: `int` (Seconds: `10`–`86400`) | **Default**: `300` (5 minutes)
* **Description**: Duration before cached search results expire.

### 8.3 `cache_stats`: Track Cache Hit Ratios
* **Option Key**: `wpts_cache_stats`
* **Type**: `bool` | **Default**: `false`
* **Description**: Logs cache hits vs misses in the analytics dashboard.

### 8.4 Redis Settings:
* **`redis_host`**: `127.0.0.1`
* **`redis_port`**: `6379`
* **`redis_password`**: Redis `AUTH` password (optional).
* **`redis_db`**: Redis database index (`0`–`15`, default: `0`).

### 8.5 Memcached Settings:
* **`memcached_host`**: `127.0.0.1`
* **`memcached_port`**: `11211`

### 8.6 CDN & Edge Caching:
* **`cdn_cache_headers`**: `bool` (Default: `false`). Sends `Cache-Control: public, max-age=...` headers to allow Cloudflare/Fastly to cache search queries at the edge.
* **`cdn_cache_ttl`**: `int` (Default: `300` seconds).

---

## 9. Background Scheduled Sync

Navigate to: **Turbo Search → Index Manager**

### 9.1 `scheduled_reindex_enabled`: Enable WP-Cron Auto Sync
* **Option Key**: `wpts_scheduled_reindex_enabled`
* **Type**: `bool` | **Default**: `false`
* **Description**: Runs scheduled background re-indexing via WP-Cron.

### 9.2 `scheduled_reindex_interval`: Sync Frequency
* **Option Key**: `wpts_scheduled_reindex_interval`
* **Type**: `string` (`hourly`, `twicedaily`, `daily`, `weekly`) | **Default**: `daily`
* **Description**: How often the background sync runs.

### 9.3 `scheduled_reindex_mode`: Sync Mode
* **Option Key**: `wpts_scheduled_reindex_mode`
* **Type**: `string` (`incremental`, `full`) | **Default**: `incremental`
* **Description**: `incremental` only checks posts modified since the last sync; `full` rebuilds the entire index.

---

## 10. Analytics, CTR & A/B Testing

Navigate to: **Turbo Search → Search Tracking & Analytics**

### 10.1 `tracking_enabled`: Enable Search Logging
* **Option Key**: `wpts_tracking_enabled`
* **Type**: `bool` | **Default**: `true`
* **Description**: Logs queries, execution times, result counts, and zero-result queries.

### 10.2 `track_clicks`: Track Result Clicks & CTR
* **Option Key**: `wpts_track_clicks`
* **Type**: `bool` | **Default**: `true`
* **Description**: Tracks which result items visitors click and their ranked position.

### 10.3 `tracking_retention_days`: Telemetry Retention Period
* **Option Key**: `wpts_tracking_retention_days`
* **Type**: `int` | **Default**: `90` days
* **Description**: Days to keep detailed search logs before automatic pruning.

### 10.4 `ab_testing_enabled`: Live A/B Testing
* **Option Key**: `wpts_ab_testing_enabled`
* **Type**: `bool` | **Default**: `false`
* **Description**: Enables split testing across search configurations.

### 10.5 `ab_debounce_b`: Variant B Debounce Delay
* **Option Key**: `wpts_ab_debounce_b`
* **Type**: `int` | **Default**: `350` ms
* **Description**: Alternate debounce timing tested against Variant A.

### 10.6 `ab_theme_b`: Variant B Visual Theme
* **Option Key**: `wpts_ab_theme_b`
* **Type**: `string` (`light`, `dark`, `minimal`) | **Default**: `minimal`
* **Description**: Alternate theme tested against Variant A.

---

## 11. Security, GDPR & Multisite

Navigate to: **Turbo Search → Search Settings → Security & GDPR**

### 11.1 `enable_honeypot`: Bot & Scraper Protection
* **Option Key**: `wpts_enable_honeypot`
* **Type**: `bool` | **Default**: `true`
* **Description**: Embeds hidden honeypot fields in search forms to detect and block automated scrapers.

### 11.2 `role_restrictions`: Role-Based Post Type Access
* **Option Key**: `wpts_role_restrictions`
* **Type**: `array` | **Default**: `[]`
* **Description**: Restricts specific post types from search results based on user roles (e.g. hide `internal_docs` from `subscriber` and `guest`).

### 11.3 `gdpr_anonymize_days`: GDPR Privacy Anonymization
* **Option Key**: `wpts_gdpr_anonymize_days`
* **Type**: `int` | **Default**: `30` days
* **Description**: Automatically hashes and wipes IP addresses and user identifiers from search telemetry logs after $N$ days to comply with GDPR and CCPA.

### 11.4 `multisite_cross_search`: Cross-Network Search
* **Option Key**: `wpts_multisite_cross_search`
* **Type**: `bool` | **Default**: `false`
* **Description**: Allows searching across all public subsites in a WordPress Multisite network from any search bar.

