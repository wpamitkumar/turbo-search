=== Turbo Search ===
Contributors: wpamitkumar
Donate link: https://profiles.wordpress.org/wpamitkumar
Tags: search, ajax search, live search, woocommerce search, product search
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Enterprise-grade instant search engine with sub-10ms queries, full-text PDF/DOCX extraction, AI vector embeddings, Typesense & Redis.

== Description ==

**Turbo Search** is a modern, high-performance search and discovery engine for WordPress and WooCommerce. Built for speed and scale, it replaces default slow database queries with an indexed full-text engine, sub-10ms query execution, and optional hybrid AI vector reranking.

### ⚡ Key Features

* **Instant Live Search**: Sub-10ms query execution with real-time dropdown and keyboard navigation.
* **3 Results Layout Modes**: Choose between List View, responsive Grid Cards, and dense Compact Card layout.
* **Multi-Tier Fallback Architecture**: Automatic failover from Typesense / Elasticsearch to local MySQL and native WordPress Core `WP_Query`.
* **Document & Attachment Extraction**: Native full-text parsing for `.pdf`, `.docx`, `.txt`, `.csv`, `.tsv`, and `.md` files without heavy external server dependencies.
* **Pluggable Search Backends**: Works out-of-the-box on MySQL/MariaDB FULLTEXT, with 1-click drivers for Typesense Server and Elasticsearch / OpenSearch clusters.
* **Hybrid Semantic AI Vector Search**: Combines keyword fulltext with OpenAI or local self-hosted Ollama embeddings (`nomic-embed-text`) using Reciprocal Rank Fusion (RRF).
* **Multi-Tier Caching**: In-memory JavaScript query caching, HTTP ETag headers, Redis, Memcached, and WordPress Transients.
* **Command+K Spotlight Modal**: Global keyboard shortcut modal (`⌘K` / `Ctrl+K`) for macOS and Windows.
* **Voice Search**: Built-in Web Speech API speech-to-text recognition (works with HTTPS connection only).
* **WooCommerce Integration**: 1-click AJAX Quick Cart, SKU search, regular/sale price schedules, and stock filters.
* **Faceted Category Multi-Tabs**: Instant tabbed filtering by post type, product category, or custom taxonomy.
* **Analytics & CTR Telemetry**: Track top search queries, zero-result searches, and click-through-rate ranking positions with GDPR-compliant IP anonymization.
* **Enterprise Multisite & Multilingual**: Cross-network multisite search with subsite origin badging, plus native WPML and Polylang integration.
* **DevOps Ready**: Unified `wp turbo-search` WP-CLI command suite for reindexing, cache management, and automated cron jobs.
* **100% Private & Self-Contained**: No external third-party CDN leaks; all dependencies and assets are bundled locally.

== External or Third-Party Services ==

Turbo Search works 100% locally out-of-the-box using your existing WordPress MySQL or MariaDB database without requiring any third-party services or remote tracking.

Optionally, administrators may configure connections to external search engines or AI embedding services for high-volume scale:

1. **Typesense** (Optional)
   * **Purpose**: High-speed distributed faceted search indexing and querying.
   * **Data Sent**: Post ID, post title, excerpt, content, post type, taxonomies, dates, and selected custom fields.
   * **When Sent**: Only during indexing/reindexing and live search queries if Typesense is selected as active engine.
   * **Service Provider**: Typesense Inc. (or self-hosted on your own server).
   * **Terms of Service**: https://typesense.org/terms-of-service/
   * **Privacy Policy**: https://typesense.org/privacy-policy/

2. **Elasticsearch / OpenSearch** (Optional)
   * **Purpose**: Enterprise search cluster indexing and querying.
   * **Data Sent**: Post ID, post title, excerpt, content, post type, taxonomies, dates, and selected custom fields.
   * **When Sent**: Only during indexing/reindexing and search queries if Elasticsearch is selected as active engine.
   * **Service Provider**: Elastic N.V. / AWS OpenSearch (or self-hosted on your own server).
   * **Terms of Service**: https://www.elastic.co/legal/terms-of-service
   * **Privacy Policy**: https://www.elastic.co/legal/privacy-policy

3. **OpenAI API** (Optional)
   * **Purpose**: Generating semantic vector embeddings for hybrid AI search.
   * **Data Sent**: Search query text and post title/excerpt text chunks to generate mathematical vector embeddings.
   * **When Sent**: Only during post indexing and user searches if AI Vector Search is enabled and OpenAI is configured.
   * **Service Provider**: OpenAI, LLC.
   * **Terms of Service**: https://openai.com/policies/terms-of-use/
   * **Privacy Policy**: https://openai.com/policies/privacy-policy/

4. **Ollama / Local Custom Embedding API** (Optional)
   * **Purpose**: Local or private server semantic vector embeddings.
   * **Data Sent**: Post text and search queries sent strictly to the self-hosted endpoint URL configured by the site administrator (e.g., http://localhost:11434).
   * **Service Provider**: Self-hosted on your own infrastructure.

== Installation ==

1. Upload `turbo-search` to the `/wp-content/plugins/` directory, or install the ZIP file via **Plugins → Add New → Upload Plugin**.
2. Activate the plugin through the **Plugins** screen in WordPress.
3. Go to **Turbo Search → Index Manager** and click **Re-index All Posts** to populate the search index table.
4. Add the search bar to your site using the **Turbo Search Bar** Gutenberg Block or the shortcode `[wpts_search]`.

== Frequently Asked Questions ==

= Does Turbo Search require external server software like Typesense or Elasticsearch? =
No. Turbo Search includes a native MySQL / MariaDB FULLTEXT engine that works on any standard shared hosting, VPS, or dedicated server out of the box with zero external dependencies. External drivers (Typesense, Elasticsearch, Redis) are optional for massive scale.

= How does PDF and document search work? =
When files are uploaded to the WordPress Media Library or attached to posts/products, Turbo Search parses text content directly using native PHP compression and XML parsers.

= Is search tracking GDPR compliant? =
Yes. IP addresses and user agents are hashed or anonymized according to the configured retention schedule (default 30 days).

= Can I customize search result templates? =
Yes. You can override templates in your child theme or use the extensive developer hooks API (e.g. `wpts_result_hit`, `wpts_indexable_document`).

= Does Voice Search work on plain HTTP connections? =
No. Modern web browsers (Google Chrome, Apple Safari, Microsoft Edge) enforce W3C device security policies and require an HTTPS connection (or localhost for local development) to access the microphone.

= How can I contribute to Turbo Search? =
Turbo Search is open source! You can contribute code, suggest new features, improve documentation, or report issues on our official GitHub repository:
https://github.com/wpamitkumar/turbo-search

= Where can I report a bug or request a feature? =
Please open an issue on our GitHub issue tracker:
https://github.com/wpamitkumar/turbo-search/issues

= How can I contribute translations? =
You can contribute translations via WordPress.org translate (GlotPress) or by submitting pull requests with updated POT/PO/MO files on GitHub:
https://github.com/wpamitkumar/turbo-search

= Does Turbo Search support custom post types and custom fields? =
Yes. You can select any public or custom post type from **Turbo Search → Settings → Post Types**, and configure custom fields, taxonomy terms, or WooCommerce attributes to be indexed.

= How can developers customize or extend the search engine? =
Turbo Search provides a comprehensive developer hooks API with over 40 actions and filters (e.g., `wpts_search_query`, `wpts_result_hit`, `wpts_cache_ttl`, `wpts_indexable_document`). Detailed developer documentation is available in the plugin's `docs/` directory and on GitHub.

== Screenshots ==

1. Live instant search dropdown with highlighted keywords and WooCommerce Quick Buy button.
2. Spotlight Command+K modal overlay with recent history and category tabs.
3. Interactive Admin Dashboard with live search volume, CTR rankings, and zero-result search analytics.
4. Modular Search Engine configuration panel (MySQL, Typesense, Elasticsearch).
5. Hybrid AI Vector Search configuration with OpenAI and local Ollama support.

== Changelog ==

= 1.0.0 =
* Initial release of Turbo Search.
* Event-driven incremental indexing engine (0ms post update delay).
* Native full-text document parsing for PDF, DOCX, TXT, CSV, TSV, and MD attachments with continuous word tokenization.
* 3 Visual Results Layouts: List View, Grid Cards, and Compact Card.
* Multi-Tier search engine & caching fallback with graceful WordPress Core native `WP_Query` failover.
* Multi-Tier caching engine (Redis, Memcached, Transients, Edge CDN headers).
* Hybrid Semantic AI Vector Search (OpenAI, Ollama, Custom HTTP).
* Unified `wp turbo-search` WP-CLI command suite (12 subcommands).
* Built-in Analytics, CTR tracking, and A/B testing suite.
* WordPress Multisite cross-network and WPML/Polylang multilingual compatibility.
* 100% self-contained codebase without third-party CDN dependencies.

== Upgrade Notice ==

= 1.0.0 =
Initial production release of Turbo Search.


