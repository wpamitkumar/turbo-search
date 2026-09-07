# Chapter 1: Installation, System Requirements & Search Engine Setup

This chapter provides a complete guide to system requirements, database schema architecture, search engine driver configuration (MySQL, Typesense, Elasticsearch), and cache layer setup.

---

## 1. System Requirements Matrix

| Component | Minimum Requirement | Recommended Production Setup | Notes |
| :--- | :--- | :--- | :--- |
| **WordPress** | 5.8+ | 6.4+ / 6.5+ / 6.6+ | Full block editor & classic widget compatibility. |
| **PHP** | 7.4 | PHP 8.1, 8.2, 8.3, or 8.4+ | Requires 64-bit PHP for large integer indexes. |
| **Database** | MySQL 5.7+ / MariaDB 10.3+ | MySQL 8.0+ / MariaDB 10.6+ | InnoDB engine with FULLTEXT index support enabled. |
| **PHP Extensions** | `ext-json`, `ext-zlib`, `ext-mbstring` | `ext-zip`, `ext-curl`, `ext-redis` | `ext-zip` enables `.docx` parsing; `ext-zlib` enables `.pdf` decompression. |
| **Memory Limit** | 128 MB | 256 MB or 512 MB | Recommended for high-volume PDF & document indexing. |
| **Search Engines** | MySQL (Built-in) | Typesense 0.25+ or Elasticsearch 8.x | Optional external engines for sub-millisecond search at scale. |

---

## 2. Installation & Provisioning

### Method A: WordPress Admin Upload (Standard)
1. In your WordPress admin dashboard, navigate to **Plugins → Add New → Upload Plugin**.
2. Select the `wp-search-plugin.zip` file and click **Install Now**.
3. Click **Activate Plugin**.

### Method B: WP-CLI Installation
```bash
# Upload and activate via WP-CLI
wp plugin install /path/to/wp-search-plugin.zip --activate

# Verify plugin status
wp turbo-search status
```

---

## 3. Database Schema Architecture

Upon activation, the plugin automatically creates optimized custom database tables via `WPTS\Installer::create_tables()`. These tables isolate search workloads from default WordPress core tables, preventing table bloat and query contention.

```
 ┌─────────────────────────────────────────────────────────────────────────────┐
 │                             wp_wpts_index                                   │
 │ (Main search index containing tokenized titles, content, excerpts & meta)  │
 ├─────────────────┬──────────────┬────────────────────────────────────────────┤
 │ Column          │ Type         │ Purpose                                    │
 ├─────────────────┼──────────────┼────────────────────────────────────────────┤
 │ id              │ BIGINT(20)   │ Primary Key (Auto Increment)               │
 │ post_id         │ BIGINT(20)   │ WordPress Post / Attachment / Product ID   │
 │ blog_id         │ BIGINT(20)   │ Multisite Network Blog ID (Default: 1)     │
 │ title           │ TEXT         │ Document Title (Weighted FULLTEXT index)   │
 │ content         │ LONGTEXT     │ Clean Extracted Text + Document Body       │
 │ excerpt         │ TEXT         │ Generated Excerpt for Search Previews      │
 │ post_type       │ VARCHAR(50)  │ Post Type (post, page, product, attachment)│
 │ post_status     │ VARCHAR(20)  │ Status (publish, inherit, private)         │
 │ post_date       │ DATETIME     │ Original Publication Timestamp             │
 │ post_modified   │ DATETIME     │ Last Modified Timestamp                    │
 │ author_id       │ BIGINT(20)   │ Author User ID                             │
 │ taxonomy_terms  │ TEXT         │ Serialized Categories, Tags & Custom Tax   │
 │ custom_fields   │ LONGTEXT     │ Indexed Custom Post Meta / ACF Fields      │
 │ sku             │ VARCHAR(100) │ WooCommerce Product SKU                    │
 │ price           │ DECIMAL(10,2)│ WooCommerce Numeric Price for Facets       │
 │ stock_status    │ VARCHAR(20)  │ WooCommerce Stock (instock, outofstock)    │
 │ language_code   │ VARCHAR(10)  │ WPML / Polylang ISO Language Code          │
 │ indexed_at      │ DATETIME     │ Search Index Ingestion Timestamp           │
 └─────────────────┴──────────────┴────────────────────────────────────────────┘
```

### Additional Supporting Tables:
1. **`wp_wpts_search_log`**: Logs anonymous query terms, execution durations, hit counts, clicked post IDs, clicked rank positions, and timestamps for CTR analytics.
2. **`wp_wpts_synonyms`**: Stores multi-term synonym clusters (e.g. `laptop, notebook, portable pc`) synchronized across MySQL and Typesense.
3. **`wp_wpts_analytics_summary`**: Hourly and daily aggregated telemetry rollups for zero-overhead reporting.
4. **`wp_wpts_events`**: Asynchronous indexing queue for background batch sync and vector embedding workers.

---

## 4. Pluggable Search Engine Driver Setup

Turbo Search features a modular driver architecture. You can switch engines at any time without data loss:

```
                      ┌─────────────────────────────────────┐
                      │          Turbo Search            │
                      └──────────────────┬──────────────────┘
                                         │
               ┌─────────────────────────┼─────────────────────────┐
               ▼                         ▼                         ▼
      ┌─────────────────┐       ┌─────────────────┐       ┌─────────────────┐
      │  MySQL FULLTEXT │       │ Typesense Cloud │       │  Elasticsearch  │
      │  (Zero-Config)  │       │  / Local Server │       │   / OpenSearch  │
      │  Default Engine │       │  Sub-Millisecond│       │ Massive Clusters│
      └─────────────────┘       └─────────────────┘       └─────────────────┘
```

---

### Option A: MySQL / MariaDB Driver (Default - Zero Configuration)
* **Best for**: Standard WordPress websites, WooCommerce stores with up to 100,000 items, and shared/VPS hosting.
* **Requirements**: MySQL 5.7+ or MariaDB 10.3+. No external daemon required.
* **Features**:
  * Dual-mode search: `IN BOOLEAN MODE` for precise operators (`+term -excluded "exact phrase"`) and `LIKE` fallback for partial prefixes.
  * Weighted relevance formula: Title matches receive a $3\times$ boost over content matches.
  * Dynamic Keyword-In-Context (KWIC) highlighted snippets.
* **Server Tuning Tip (Optional)**:
  In your MySQL configuration (`my.cnf`), set `innodb_ft_min_token_size = 2` to allow 2-character searches (e.g. `TV`, `PC`, `4K`).

---

### Option B: Typesense Driver (C++ Typo-Tolerant Engine)
* **Best for**: Fast-growing eCommerce catalogs, high-traffic media portals, and instant typo-tolerant search (< 2ms response).
* **Setup Instructions**:
  1. **Start Typesense**: Run Typesense locally via Docker or deploy on Typesense Cloud:

```bash
docker run -d -p 8108:8108 -v /tmp/typesense-data:/data \
  typesense/typesense:0.25.2 \
  --data-dir /data \
  --api-key=xyzMasterKey123 \
  --enable-cors
```

  2. Navigate to **Turbo Search → Settings → Search Engines**.
  3. Select **Typesense Server** from the driver dropdown.
  4. Enter your connection details:
     * **Host**: `localhost` (or `xxx.typesense.net` for cloud).
     * **Port**: `8108` (or `443` for HTTPS).
     * **Protocol**: `http` or `https`.
     * **API Key**: Your Typesense API Key.
     * **Collection Name**: `wpts_documents` (auto-created on first sync).
  5. Click **`🔌 Test Typesense`** to verify connection.
  6. Click **Save Settings** and run `wp turbo-search reindex --yes` (or click **Re-index All Posts**).

---

### Option C: Elasticsearch / OpenSearch Driver
* **Best for**: Multi-node enterprise clusters, federated search networks, and enterprise catalogs with millions of documents.
* **Setup Instructions**:
  1. Navigate to **Turbo Search → Settings → Search Engines**.
  2. Select **Elasticsearch / OpenSearch Cluster**.
  3. Enter your cluster endpoint:
     * **Host URL**: `https://search-cluster.example.com`
     * **Port**: `9200`
     * **Index Name**: `wpts_posts_prod`
     * **Authentication**: Provide **API Key** or **Username / Password**.
  4. Click **`🔌 Test Elasticsearch`**.
  5. Click **Save Settings** and rebuild the index.

---

## 5. Multi-Tier Query Cache Driver Setup

To guarantee **< 2ms response times** under heavy traffic, Turbo Search provides a 4-tier query cache:

1. Navigate to **Turbo Search → Cache Manager** (`/wp-admin/admin.php?page=wpts-cache`).
2. Select your **Cache Driver**:
   * **Auto (Recommended)**: Automatically probes for `Redis`, then `Memcached`, and falls back to WordPress Transients.
   * **Redis**: Connects to a local or remote Redis instance (`127.0.0.1:6379`).
   * **Memcached**: Connects to Memcached daemon (`127.0.0.1:11211`).
   * **WordPress Object Cache**: Uses `wp_cache_*()` functions (works smoothly with Redis Object Cache or LiteSpeed Object Cache plugins).
   * **Transient**: Stores cached results in `wp_options`.
3. Set your **Cache TTL (Seconds)** (Default: `300` seconds / 5 minutes).
4. Click **Save Settings**.

---

## 6. Verifying System Health

Run the built-in diagnostic suite to confirm that your database tables, search engine, document parsers, and cache drivers are 100% operational:

```bash
# Run complete system health check via WP-CLI
wp turbo-search health
```

Or view the interactive diagnostics report under **Turbo Search → Site Health** in your WordPress admin.

---

## 7. WordPress Coding Standards & Security Compliance

Turbo Search is developed according to **WordPress Core Coding Standards (WPCS)** and security best practices:
* **`phpcs.xml.dist` Included**: The repository includes an official PHP_CodeSniffer configuration file configured for CI/CD, local linting, and automated code review.
* **Strict Superglobal Unslashing**: All `$_GET`, `$_POST`, and `$_REQUEST` values are unslashed with `wp_unslash()` prior to sanitization (`sanitize_text_field`, `sanitize_key`, `absint`).
* **Bounded Remote Requests**: All HTTP API calls define explicit timeouts (`timeout => 5` to `timeout => 15`) preventing web server thread starvation.
* **Safe SQL Queries**: 100% of custom database queries use `$wpdb->prepare()` with explicit parameter bindings.
* **Zero CDN Leaks**: All styles, icons, and libraries (including Chart.js) are self-contained locally.

