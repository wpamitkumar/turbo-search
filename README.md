# Turbo Search

[![CI Status](https://github.com/wpamitkumar/turbo-search/actions/workflows/ci.yml/badge.svg)](https://github.com/wpamitkumar/turbo-search/actions/workflows/ci.yml)
[![Release Package](https://github.com/wpamitkumar/turbo-search/actions/workflows/release.yml/badge.svg)](https://github.com/wpamitkumar/turbo-search/actions/workflows/release.yml)
[![GitHub Release](https://img.shields.io/github/v/release/wpamitkumar/turbo-search?color=blue&logo=github)](https://github.com/wpamitkumar/turbo-search/releases)
[![WordPress Tested](https://img.shields.io/badge/WordPress-6.0%20to%206.7-21759b?logo=wordpress&logoColor=white)](https://wordpress.org)
[![PHP Version](https://img.shields.io/badge/PHP-%3E%3D%207.4-777bb4?logo=php&logoColor=white)](https://php.net)
[![License: GPL-2.0-or-later](https://img.shields.io/badge/License-GPL%20v2%2B-green.svg)](LICENSE)
[![GitHub Stars](https://img.shields.io/github/stars/wpamitkumar/turbo-search?style=social)](https://github.com/wpamitkumar/turbo-search)

> Enterprise-grade, instant search platform for WordPress & WooCommerce - powered by MySQL FULLTEXT (free, zero setup), Typesense (high-speed C++ search engine), or Elasticsearch.

---

## 🌟 Key Features

- **Triple Engine Support**: MySQL FULLTEXT works anywhere out-of-the-box; Typesense & Elasticsearch deliver sub-5ms query response times
- **🛡️ Multi-Tier Fallback Architecture**: Automatic failover from Typesense/Elasticsearch &rarr; MySQL &rarr; **WordPress Core native `WP_Query`**; search never fails if a server drops
- **📄 Full-Text Document Search**: extracts and indexes full text from PDF (`.pdf`), Word (`.docx`), Plain Text (`.txt`), and Spreadsheets (`.csv`, `.tsv`)
- **Multi-Post-Type Support**: filter by single or multiple post types (Posts, Pages, Products, Attachments, CPTs) in Shortcodes, Widgets, and Gutenberg Blocks
- **📐 3 Results Layout Modes**: switch between **📄 List View**, **⊞ Grid Cards**, and **🗂️ Compact Card** modes
- **🛒 WooCommerce 1-Click Buy**: instant AJAX "Add to Cart", live inventory, and pricing badges
- **⌨️ Spotlight Command + K Modal**: Spotlight search modal overlay with keyboard shortcuts and keyboard navigation
- **🎙️ Web Speech Voice Search**: real-time speech-to-text search input (works with HTTPS connection only)
- **⚡ 4-Tier Caching**: In-memory browser cache, HTTP 304 ETag, and Redis / Memcached / Transients with intelligent UI cache preservation
- **🖥️ WP-CLI Command Suite**: `wp turbo-search` CLI tool for re-indexing, diagnostics, stats, and DevOps automation
- **🧠 Hybrid AI Vector Search**: semantic embeddings with OpenAI (`text-embedding-3`) or self-hosted Ollama
- **Multisite & Multilingual**: network-wide search and full WPML / Polylang compatibility
- **🔒 Self-Contained & Private**: Pure PHP + WordPress HTTP API, all assets bundled locally without external third-party CDN leaks (100% WordPress.org compliant)

---

## 📚 Documentation

Complete documentation is available in the [`docs/`](docs/README.md) directory:
- [01. Installation & Setup](docs/01-installation-and-setup.md)
- [02. Search Features & Shortcodes](docs/02-search-features-and-shortcodes.md)
- [03. Indexing & Caching Architecture](docs/03-indexing-and-caching.md)
- [04. Hybrid AI Vector Search](docs/04-ai-vector-search.md)
- [05. Developer Hooks & Actions API](docs/05-developer-hooks-api.md)
- [06. REST API Reference](docs/06-rest-api-reference.md)
- [07. Multisite & Multilingual Architecture](docs/07-multisite-multilingual.md)
- [08. WP-CLI Command Suite Manual](docs/08-cli-commands.md)
- [09. Complete 72 Settings Reference](docs/09-complete-settings-reference.md)

---

## 🚀 Shortcode Reference

```text
[wpts_search placeholder="Search store…" post_types="post,page,product" layout="grid" per_page="8" theme="light"]
```

| Attribute | Default | Description |
| :--- | :--- | :--- |
| `placeholder` | `Search…` | Input placeholder text |
| `post_types` / `post_type` | *(all configured)* | Comma-separated post types to query (e.g. `post,page,product,attachment`) |
| `layout` | *(from Settings)* | Display layout: `list` (List View), `grid` (Grid Cards), or `card` (Compact Card) |
| `per_page` | `8` | Maximum results displayed in dropdown |
| `theme` | `light` | Theme preset: `light`, `dark`, `minimal`, `glass` |
| `category_tabs`| `true` | Display category multi-filter tabs |
| `quick_cart` | `true` | Show WooCommerce 1-click AJAX Add-to-Cart button |
| `show_voice` | `true` | Show voice search microphone button (works with HTTPS only) |
| `show_type` | `true` | Show post type badge |
| `class` | *(empty)* | Custom CSS container class name |

---

## REST API

### Search
```
GET /wp-json/wpts/v1/search
```

| Parameter   | Type    | Default | Description                      |
|-------------|---------|---------|----------------------------------|
| `q`         | string  | - | **Required.** Search query       |
| `post_type` | string  | all     | Filter by post type              |
| `lang`      | string  | current | Language code (WPML/Polylang)    |
| `per_page`  | integer | 10      | Results per page (max 100)       |
| `page`      | integer | 1       | Page number                      |

**Response**
```json
{
  "hits": [
    {
      "post_id": 42,
      "title": "Hello <mark>World</mark>",
      "excerpt": "A short excerpt…",
      "post_type": "post",
      "url": "https://example.com/hello-world/"
    }
  ],
  "found": 128,
  "page": 1
}
```

### Re-index (admin only)
```
POST /wp-json/wpts/v1/reindex
```
Requires a valid `X-WP-Nonce` header from a user with `manage_options`.

---

## Typesense Setup

1. Install Typesense on a VPS or use [Typesense Cloud](https://cloud.typesense.org).

```bash
# Docker (quickest)
docker run -d -p 8108:8108 \
  -v /data:/data typesense/typesense:latest \
  --data-dir /data \
  --api-key=YOUR_SECRET_KEY \
  --enable-cors
```

2. In WordPress go to **Turbo Search → Settings** and fill in:
   - Host: `your-server-ip` or domain
   - Port: `8108`
   - Protocol: `http` or `https`
   - API Key: your Typesense admin key
   - Collection: `wpts_posts` (or any name)

3. Click **Save Settings** then go to **Index Manager → Re-index All Posts**.

The plugin auto-creates the Typesense collection with the correct schema on first save.

---

## Developer Hooks

View all hooks live at **Turbo Search → Dev Hooks** in your WP admin.

### Filters

#### `wpts_indexable_document`
Modify the document before it is sent to the index.

```php
add_filter( 'wpts_indexable_document', function ( array $doc, WP_Post $post ) : array {
    // Add a custom field to the index
    $doc['price'] = get_post_meta( $post->ID, '_price', true );
    return $doc;
}, 10, 2 );
```

#### `wpts_before_index_document`
Last-chance filter before writing to Typesense or MySQL.

```php
add_filter( 'wpts_before_index_document', function ( array $doc ) : array {
    $doc['content'] = strip_shortcodes( $doc['content'] );
    return $doc;
} );
```

#### `wpts_rest_search_results`
Modify results before they reach the browser.

```php
add_filter( 'wpts_rest_search_results', function ( array $results, string $query ) : array {
    // Remove results the current user can't read
    $results['hits'] = array_filter( $results['hits'], fn($h) => current_user_can( 'read_post', $h['post_id'] ) );
    return $results;
}, 10, 2 );
```

#### `wpts_typesense_search_params`
Tune Typesense query parameters.

```php
add_filter( 'wpts_typesense_search_params', function ( array $params ) : array {
    $params['num_typos']  = 2;     // allow more typos
    $params['per_page']   = 20;
    return $params;
} );
```

#### `wpts_typesense_collection_schema`
Add custom fields to the Typesense schema (before collection is created).

```php
add_filter( 'wpts_typesense_collection_schema', function ( array $schema ) : array {
    $schema['fields'][] = [ 'name' => 'price', 'type' => 'float', 'optional' => true ];
    return $schema;
} );
```

#### `wpts_mysql_where_clauses`
Add WHERE clauses to the MySQL fallback search.

```php
add_filter( 'wpts_mysql_where_clauses', function ( array $pair, string $q, array $filters ) : array {
    [ $where, $values ] = $pair;
    $where[]  = 'post_type != %s';
    $values[] = 'attachment';
    return [ $where, $values ];
}, 10, 3 );
```

#### `wpts_register_sample_cpt`
Disable the built-in Resource CPT.

```php
add_filter( 'wpts_register_sample_cpt', '__return_false' );
```

### Actions

#### `wpts_booted`
Fires after the plugin is fully initialised.

```php
add_action( 'wpts_booted', function ( WPTS\Core $core ) {
    // your bootstrap code
} );
```

#### `wpts_register_post_types`
Register additional CPTs to include in search.

```php
add_action( 'wpts_register_post_types', function () {
    register_post_type( 'product', [ /* ... */ ] );
} );
```

#### `wpts_after_index_document`
Runs after a document is indexed.

```php
add_action( 'wpts_after_index_document', function ( array $doc, bool $ok, string $engine ) {
    if ( ! $ok ) {
        error_log( "WPTS: failed to index post {$doc['id']} via {$engine}" );
    }
}, 10, 3 );
```

#### `wpts_reindex_complete`
Fires when a full re-index finishes.

```php
add_action( 'wpts_reindex_complete', function ( int $count, string $engine ) {
    wp_mail( 'admin@example.com', 'Re-index done', "{$count} posts via {$engine}" );
}, 10, 2 );
```

#### `wpts_settings_saved`
Fires when settings are saved from the admin form.

```php
add_action( 'wpts_settings_saved', function ( array $data ) {
    // flush a custom cache, etc.
} );
```

---

## Multisite

When **network-activated**:

- The installer creates `wp_N_wpts_index` tables on every sub-site automatically.
- New sites added to the network get the table immediately (via `wp_initialize_site`).
- A **Network Admin → Turbo Search** page shows every site and a one-click "Re-index All Sites" button.
- Network admins can push a single Typesense host/key to all sites at once.

---

## Multilingual

### WPML
- Posts are indexed with their `lang` field set via `ICL_LANGUAGE_CODE`.
- Search results are automatically filtered to the active language.
- Plugin strings are registered with WPML String Translation.
- Hook into `wpts_register_wpml_strings` to register your own strings.

### Polylang
- Posts use `pll_current_language()` as the `lang` field.
- The `wpts_resource` CPT is automatically registered as translatable.
- All search queries are filtered to the current Polylang language.

---

## File Structure

```
turbo-search/
├── turbo-search.php          ← Plugin header, constants, autoloader, boot
├── uninstall.php                ← Cleanup on plugin deletion
├── includes/
│   ├── class-core.php           ← Singleton: wires all subsystems
│   ├── class-installer.php      ← DB table creation, default options
│   ├── ajax-handlers.php        ← wp_ajax_* handlers
│   ├── admin/
│   │   ├── class-settings.php   ← Option read/write/sanitise
│   │   └── class-admin-page.php ← Admin menus, settings form, index manager
│   ├── api/
│   │   └── class-rest-search.php← REST endpoint: /wpts/v1/search
│   ├── cache/
│   │   ├── class-typesense.php  ← Typesense adapter (REST via WP HTTP API)
│   │   └── class-mysql.php      ← MySQL FULLTEXT fallback
│   ├── cpt/
│   │   └── class-cpt-manager.php← CPT registration
│   ├── hooks/
│   │   └── class-hooks-manager.php ← Internal hooks + public hooks reference
│   ├── i18n/
│   │   └── class-i18n-loader.php← Text domain, WPML, Polylang
│   └── multisite/
│       └── class-network.php    ← Network admin page
├── assets/
│   ├── css/
│   │   ├── search.css           ← Frontend instant-search styles
│   │   └── admin.css            ← Admin page styles
│   └── js/
│       ├── search.js            ← Vanilla JS instant search widget
│       └── admin.js             ← Admin UI helpers
├── templates/
│   └── shortcode.php            ← [wpts_search] shortcode + WP widget
└── languages/
    └── turbo-search.pot      ← Translation template
```

---

## Changelog

### 1.0.0
- Initial release
- MySQL FULLTEXT engine (works on any host)
- Typesense adapter (zero PHP SDK dependencies)
- Multisite support with per-site table isolation
- WPML + Polylang language filtering
- REST API with pagination and filters
- Shortcode, Widget, and keyboard-navigable instant-search UI
- 15+ developer filters and actions
- Dev Hooks reference page in WP admin

---

## License

GPL-2.0-or-later - see [https://www.gnu.org/licenses/gpl-2.0.html](https://www.gnu.org/licenses/gpl-2.0.html)

---

## Dashboard & Analytics (v1.1.0)

### Admin Menu Structure

```
Turbo Search
├── 📊 Dashboard          ← KPI cards, volume chart, top queries, index status
├── ⚙️  Settings          ← General, post types, Typesense, frontend config
├── 📊 Tracking           ← Full search log, top queries, zero results, index events
├── ⚡ Cache              ← Driver config, Redis, Memcached, flush controls
├── 📄 Index Manager      ← Re-index, current index count
├── 🔗 Dev Hooks          ← All filters & actions reference
└── [Network Admin]       ← Multisite network overview (multisite only)
```

---

### Dashboard Page

The main overview page shows:

| Widget | Description |
|---|---|
| **9 KPI Cards** | Total searches, cache hit rate, cache hits/misses, indexed posts, posts indexed/deleted, zero-result searches, cache flushes |
| **Search Volume Chart** | 14-day bar chart (total / cached / zero-results) with 7/14/30 day selector |
| **Top Searches** | Most searched queries with avg results, speed, cache hit rate bar |
| **Zero-Result Queries** | Content gaps - queries that returned nothing, with "Create Post" shortcut |
| **Index Status** | Total indexed count and per-post-type breakdown |
| **Status Bar** | Active search engine + cache driver + TTL at a glance |

---

### Tracking Page

Full analytics broken into 5 tabs:

**🔎 Search Log**
- Every search query with timestamp, result count, cache hit/miss, engine, driver, language, post type, duration in ms
- Paginated (50 per page), filterable by date range (7/14/30/60/90 days)
- CSV export

**📈 Top Queries**
- Most searched terms with search count, avg results, avg speed, cache hit rate progress bar
- CSV export

**🚫 Zero Results**
- Queries that returned 0 results - colour-coded content gap report
- "Create Post" button links directly to new post editor with the query pre-filled as title

**📄 Index Events**
- Live index: total + per-post-type counts
- Recent index events: `index_upsert`, `index_delete`, `reindex`, `error` - with post title links and engine labels
- CSV export

**⚙️ Tracking Settings**
- Enable/disable tracking globally
- Set retention period (7–365 days - old records pruned daily by WP-Cron)
- Database usage counters (search log rows, event log rows)
- Prune now / Reset all tracking data buttons

---

### Cache Page

**General tab**
- Driver selector (Auto / Redis / Memcached / WP Object Cache / Transients / Disabled)
- TTL with quick-select presets (1 min → 24 hr)
- PHP extension status badges

**Redis tab**
- Host, port, password, DB index - editable in UI or overridable via `wp-config.php` constants
- 🔌 Live "Test Connection" button (AJAX, no page reload)

**Memcached tab**
- Host + port - editable in UI or overridable via constants
- 🔌 Live "Test Connection" button

**Flush tab**
- Full flush button (page redirect)
- ⚡ AJAX flush button (no page reload)
- Automatic invalidation reference table

---

### Cache Invalidation - Complete Reference

Cache is automatically flushed **and** the index is updated on every post lifecycle event:

| WordPress Event | Cache Action | Index Action |
|---|---|---|
| Post published | Flush all | Index post |
| Post updated | Flush all | Re-index post |
| Post deleted | Flush all | Remove from index |
| Post unpublished | Flush all | Remove from index |
| Re-index All (admin) | Flush all | Rebuild entire index |
| Manual flush (admin) | Flush all | - |

This means search results are **always fresh** - stale cache is never served after content changes.

---

### Tracking Database Tables

Two new tables are created automatically on plugin activation:

**`wp_wpts_search_log`** - every search query
```sql
id, query, query_hash, results, from_cache, engine, cache_driver,
post_type, lang, site_id, duration_ms, searched_at
```

**`wp_wpts_events`** - index and cache events
```sql
id, event_type, engine, cache_driver, post_id, post_type,
site_id, lang, meta, happened_at
```

Old records are pruned automatically via WP-Cron based on the retention period setting (default 90 days).

---

### New Hooks (v1.1.0)

| Hook | Type | Description |
|---|---|---|
| `wpts_cache_hit` | action | Fires when result served from cache |
| `wpts_cache_miss` | action | Fires when engine is queried (no cache) |
| `wpts_cache_flushed` | action | Fires after full cache flush |
| `wpts_before_cache_flush_post` | action | Before post-triggered flush |
| `wpts_cache_connect_error` | action | Redis/Memcached connection failure |
| `wpts_cache_ttl` | filter | Modify TTL per query |
| `wpts_cache_key` | filter | Extend cache key (e.g. add user role) |
| `wpts_search_engine` | filter | Swap entire search engine |

---

## Changelog

### 1.1.0
- Full **Dashboard** page with KPI cards and Chart.js volume chart
- **Tracking** page: search log, top queries, zero-result report, index events, CSV exports
- **Cache** page: Redis tab, Memcached tab, General tab, Flush tab with AJAX flush
- Redis & Memcached settings configurable via admin UI (no wp-config.php required)
- Live connection test for Redis and Memcached from admin UI
- Cache auto-invalidates on every post insert, update, delete, and unpublish
- `Tracker` class: two DB tables (`wpts_search_log`, `wpts_events`), daily cron pruning
- 8 new developer hooks for cache lifecycle events
- Admin CSS rewritten: dashboard cards, panels, tabs, tables, progress bars

### 1.0.0
- Initial release

---

## 💻 Contributing & Bug Reports

Contributions, issues, and feature requests are welcome!

- **Repository**: [https://github.com/wpamitkumar/turbo-search](https://github.com/wpamitkumar/turbo-search)
- **Issue Tracker**: [https://github.com/wpamitkumar/turbo-search/issues](https://github.com/wpamitkumar/turbo-search/issues)
- **Pull Requests**: [https://github.com/wpamitkumar/turbo-search/pulls](https://github.com/wpamitkumar/turbo-search/pulls)

### Clone & Development Setup

```bash
# 1. Fork the repo on GitHub, then clone your fork:
git clone https://github.com/wpamitkumar/turbo-search.git
cd turbo-search

# 2. Install development tools (PHPCS & WPCS):
composer install

# 3. Create a new topic branch:
git checkout -b feature/your-feature-name
```

---

### ❓ Frequently Asked Questions (FAQ) for Contributors

#### Q: How do I get started with contributing?
1. Fork the repository on GitHub: [https://github.com/wpamitkumar/turbo-search](https://github.com/wpamitkumar/turbo-search).
2. Clone it into your local WordPress plugins directory (`wp-content/plugins/turbo-search`).
3. Create a feature or bugfix branch (`git checkout -b feature/your-feature-name` or `fix/issue-description`).
4. Make your changes following the coding standards.
5. Push to your fork and submit a Pull Request against the `main` branch.

#### Q: What coding standards and PHP versions are required?
* **Coding Standards**: All PHP code must adhere to [WordPress Coding Standards (WPCS)](https://github.com/WordPress/WordPress-Coding-Standards). A configured `phpcs.xml.dist` is included.
* **PHP Compatibility**: Code must be compatible with **PHP 7.4 through PHP 8.3+**.
* **WordPress Compatibility**: Code must support **WordPress 6.0+**.

#### Q: How can I run linting and tests locally before submitting a PR?
* **Syntax check**:
  ```bash
  find . -name "*.php" -not -path "./vendor/*" -not -path "./build/*" -not -path "./scratch/*" -exec php -l {} \;
  ```
* **Coding standards check**:
  ```bash
  composer lint
  # or directly:
  vendor/bin/phpcs
  ```
* **Auto-fix code style issues**:
  ```bash
  composer format
  # or directly:
  vendor/bin/phpcbf
  ```
* **Verify release ZIP build**:
  ```bash
  mkdir -p build/turbo-search
  rsync -av --exclude-from='.distignore' ./ build/turbo-search/
  cd build && zip -r turbo-search.zip turbo-search/ && cd ..
  ```

#### Q: Do I need external servers (Typesense, Elasticsearch, Redis) to contribute?
No. Turbo Search uses native **MySQL / MariaDB FULLTEXT** and core WordPress fallback by default, meaning you can develop and test all core features, admin settings, indexer, blocks, widgets, shortcodes, and UI templates on a standard local WordPress environment (LocalWP, Docker, WP-Now, Valet, etc.) with zero external dependencies. External engine drivers (Typesense, Elasticsearch, Redis, Ollama) are modular and optional.

#### Q: What should I do if I find a bug or have a feature idea?
* **Search Existing Issues**: First, check [GitHub Issues](https://github.com/wpamitkumar/turbo-search/issues) to ensure it hasn't been reported yet.
* **Open an Issue**: Use our [Bug Report](https://github.com/wpamitkumar/turbo-search/issues/new?template=bug_report.md) or [Feature Request](https://github.com/wpamitkumar/turbo-search/issues/new?template=feature_request.md) template with as much detail and context as possible.

#### Q: How do I contribute translations or internationalization (i18n)?
All user-facing strings must use WordPress internationalization functions (`__()`, `_e()`, `esc_html__()`, etc.) with the `turbo-search` text domain. You can update or generate translation files using the template in [`languages/turbo-search.pot`](languages/turbo-search.pot).

#### Q: What is the PR review process?
Once you submit a Pull Request:
1. Automated **GitHub Actions CI** will run PHP syntax matrix checks across PHP 7.4 - 8.3 and packaging tests.
2. Maintainers will review your code for functionality, architecture consistency, and security.
3. Once approved and CI passes, your PR will be merged into `main`.

---

## 📄 License

This project is licensed under the GNU General Public License v2.0 or later - see the [LICENSE](LICENSE) file for details.
