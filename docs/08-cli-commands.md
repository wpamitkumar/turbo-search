# Chapter 8: Complete WP-CLI Command Suite & DevOps Automation Manual

Turbo Search provides an enterprise-grade command-line interface (CLI) built directly on the standard WP-CLI framework. The command suite is registered under both the primary `wp turbo-search` namespace and the convenient short alias `wp wpts`.

This manual covers every command, flag, argument, output format, exit code, and production DevOps recipe in exhaustive detail.

---

## 📑 Table of Contents

* [1. Command Namespace & Global Options](#1-command-namespace--global-options)
* [2. Command Matrix & Quick Reference](#2-command-matrix--quick-reference)
* [3. Exhaustive Command Reference](#3-exhaustive-command-reference)
  * [3.1 wp turbo-search status](#31-wp-turbo-search-status)
  * [3.2 wp turbo-search reindex](#32-wp-turbo-search-reindex)
  * [3.3 wp turbo-search index <id>](#33-wp-turbo-search-index-id)
  * [3.4 wp turbo-search search <query>](#34-wp-turbo-search-search-query)
  * [3.5 wp turbo-search health](#35-wp-turbo-search-health)
  * [3.6 wp turbo-search stats](#36-wp-turbo-search-stats)
  * [3.7 wp turbo-search flush-cache](#37-wp-turbo-search-flush-cache)
  * [3.8 wp turbo-search flush-index](#38-wp-turbo-search-flush-index)
  * [3.9 wp turbo-search prune-logs](#39-wp-turbo-search-prune-logs)
  * [3.10 wp turbo-search flush-tracking](#310-wp-turbo-search-flush-tracking)
  * [3.11 wp turbo-search settings list](#311-wp-turbo-search-settings-list)
  * [3.12 wp turbo-search settings get](#312-wp-turbo-search-settings-get)
  * [3.13 wp turbo-search settings set](#313-wp-turbo-search-settings-set)
* [4. DevOps Automation Recipes](#4-devops-automation-recipes)
  * [Recipe 1: Nightly Server Crontab Maintenance](#recipe-1-nightly-server-crontab-maintenance)
  * [Recipe 2: CI/CD Deployment Pipeline Hook](#recipe-2-cicd-deployment-pipeline-hook)
  * [Recipe 3: Multisite Network-Wide Synchronization](#recipe-3-multisite-network-wide-synchronization)
  * [Recipe 4: Datadog / Nagios / Monit Health Check](#recipe-4-datadog--nagios--monit-health-check)
* [5. Exit Codes & Troubleshooting](#5-exit-codes--troubleshooting)

---

## 1. Command Namespace & Global Options

All commands can be invoked using either `wp turbo-search` or `wp wpts`:

```bash
# Primary namespace:
wp turbo-search <command> [args] [options]

# Shorthand alias:
wp wpts <command> [args] [options]
```

### Global Options Supported by All Commands:

| Option | Type | Description |
| :--- | :--- | :--- |
| `--yes` | `flag` | Automatically answers **Yes** to all confirmation prompts (essential for non-interactive bash scripts and CI/CD pipelines). |
| `--format=<format>` | `string` | Output format: `table` (default), `json`, `csv`, `yaml`, `count`, `ids`. |
| `--quiet` | `flag` | Suppresses non-essential log messages, returning only fatal errors or requested return values. |
| `--path=<path>` | `path` | Path to WordPress installation directory (standard WP-CLI flag). |
| `--url=<url>` | `url` | Target subsite domain/URL in a WordPress Multisite network. |

---

## 2. Command Matrix & Quick Reference

| Command | Shorthand Alias Example | Description |
| :--- | :--- | :--- |
| **`status`** | `wp wpts status` | View active engine, cache backend, document counts, and coverage %. |
| **`reindex`** | `wp wpts reindex --yes` | Batch reindex all published content and attached documents into search engine. |
| **`index`** | `wp wpts index 17` | Instantly index or update a single post, page, product, or attachment by ID. |
| **`search`** | `wp wpts search "invoice"` | Execute a terminal test search with relevance scoring and execution timing. |
| **`health`** | `wp wpts health` | Validate database schema, engine connectivity, and index integrity. |
| **`stats`** | `wp wpts stats` | Inspect search analytics, cache hit ratio, CTR rate, and query volume. |
| **`flush-cache`** | `wp wpts flush-cache --yes` | Purge query caches (Redis / Memcached / WP Object Cache / Transients). |
| **`flush-index`** | `wp wpts flush-index --yes` | Wipe all indexed records from search table/collection without touching posts. |
| **`prune-logs`** | `wp wpts prune-logs --days=30` | Prune search tracking and telemetry logs older than specified days. |
| **`flush-tracking`**| `wp wpts flush-tracking --yes` | Purge all search queries, click logs, and analytics event records. |
| **`settings list`** | `wp wpts settings list` | Export all 72 plugin configuration settings in table or JSON format. |
| **`settings get`** | `wp wpts settings get <key>` | Read a specific configuration setting value. |
| **`settings set`** | `wp wpts settings set <key> <val>` | Write a configuration setting value directly from the CLI. |

---

## 3. Exhaustive Command Reference

---

### 3.1 `wp turbo-search status`

Displays real-time operational status of the search engine, active cache driver, document index counts, and total published posts across all configured post types.

#### Options:
* `[--format=<table|json|csv|yaml>]`: Rendering format (Default: `table`).

#### Examples:

```bash
# Standard table view
wp turbo-search status
```

**Output:**
```text
+---------------------+--------------+
| Field               | Value        |
+---------------------+--------------+
| Search engine       | typesense    |
| Cache driver        | object-cache |
| Indexed documents   | 1,450        |
| Published posts     | 1,450        |
| Index coverage      | 100.0%       |
| Vector search       | enabled      |
| Tracking enabled    | yes          |
| Cache hit rate      | 82.4%        |
+---------------------+--------------+
```

```bash
# JSON output for monitoring tools
wp turbo-search status --format=json
```

**JSON Output:**
```json
[
  {"Field":"Search engine","Value":"typesense"},
  {"Field":"Cache driver","Value":"object-cache"},
  {"Field":"Indexed documents","Value":"1450"},
  {"Field":"Published posts","Value":"1450"},
  {"Field":"Index coverage","Value":"100.0%"},
  {"Field":"Vector search","Value":"enabled"},
  {"Field":"Tracking enabled","Value":"yes"},
  {"Field":"Cache hit rate","Value":"82.4%"}
]
```

---

### 3.2 `wp turbo-search reindex`

Executes a memory-safe batch re-indexing process across all configured post types (including WooCommerce products, pages, and media attachments with PDF/DOCX text extraction).

#### Options:
* `[--id=<number>]`: Index only a single specific post or document ID.
* `[<id>]`: Positional single post ID argument.
* `[--batch=<number>]`: Number of posts processed per batch (Default: `100`).
* `[--batch-size=<number>]`: Alias for `--batch`.
* `[--quiet]`: Suppresses batch-by-batch progress output.
* `[--yes]`: Skips the interactive confirmation prompt.

#### Examples:

```bash
# Interactive re-index with live progress bar
wp turbo-search reindex

# Unattended re-index in 200-item chunks (ideal for large sites & cron)
wp turbo-search reindex --batch=200 --yes

# Re-index a single post by ID using the flag
wp turbo-search reindex --id=17
```

**Terminal Output During Batch Run:**
```text
🔍 Search Engine: TYPESENSE | Post Types: [post, page, product, attachment] | Batch Size: 100
📊 Current documents in index before run: 450
🚀 Found 1,450 published item(s) to index across 15 total batch(es)...
  ↳ [Batch 1/15] Indexed 100 posts (Total processed so far: 100/1450)
  ↳ [Batch 2/15] Indexed 100 posts (Total processed so far: 200/1450)
  ...
  ↳ [Batch 15/15] Indexed 50 posts (Total processed so far: 1450/1450)
Success: Re-indexing complete in 3.42s! 1450 posts processed. Database now contains 1450 indexed documents.
```

---

### 3.3 `wp turbo-search index <id>`

Instantly indexes or refreshes a single individual post, page, WooCommerce product, or media attachment (PDF, Word DOCX, text file) by its WordPress ID.

#### Arguments:
* `<id>`: The numeric WordPress post or attachment ID (Required).

#### Examples:

```bash
# Index a newly uploaded Tax Invoice PDF or post
wp turbo-search index 17
```

**Terminal Output:**
```text
Success: Indexed single item #17: "Sky Green Laser Tax Invoice #17" (attachment) into TYPESENSE search engine! [Extracted 601 bytes from attached/embedded document]
```

---

### 3.4 `wp turbo-search search <query>`

Executes a test search directly against the active engine from the terminal, calculating relevance scores, highlighting, and execution latency.

#### Arguments:
* `<query>`: Search keyword or quoted exact phrase (Required).

#### Options:
* `[--per-page=<number>]`: Number of results to return (Default: `10`).
* `[--page=<number>]`: Pagination page number (Default: `1`).
* `[--post-type=<types>]`: Comma-separated list of post types (e.g. `--post-type=product,post`).
* `[--format=<table|json|csv>]`: Output format.

#### Examples:

```bash
# Search for an invoice number or company name
wp turbo-search search "24BEQPR1644G1ZB"

# Search for products with JSON output
wp turbo-search search "MacBook" --post-type=product --per-page=3 --format=json
```

**Terminal Output:**
```text
Found 1 result(s) for "24BEQPR1644G1ZB" in 2ms (engine: typesense)
+----+--------------------------------+------------+------------------------------------------+
| ID | Title                          | Type       | URL                                      |
+----+--------------------------------+------------+------------------------------------------+
| 17 | Sky Green Laser Tax Invoice #17| attachment | https://example.com/uploads/invoice17.pdf|
+----+--------------------------------+------------+------------------------------------------+
```

---

### 3.5 `wp turbo-search health`

Runs a full automated diagnostic suite to verify that database tables, engines, cache systems, and index coverage are operating at 100% health.

#### Options:
* `[--format=<table|json|csv>]`: Output format.

#### Examples:

```bash
wp turbo-search health
```

**Diagnostic Output:**
```text
+-----------------------+--------+-------------------------------------------------+
| Check                 | Status | Details                                         |
+-----------------------+--------+-------------------------------------------------+
| Search Engine Status  | PASS   | Search Engine (TYPESENSE) is healthy            |
| Index Coverage        | PASS   | 100% coverage (1,450 of 1,450 posts indexed)    |
| Database Tables       | PASS   | All 4 required tables exist                     |
+-----------------------+--------+-------------------------------------------------+
Success: System health check completed. All critical checks passed.
```

---

### 3.6 `wp turbo-search stats`

Outputs search telemetry, click-through rates (CTR), search volume, cache hit ratios, and zero-result rates.

#### Options:
* `[--format=<table|json|csv>]`: Output format.

#### Examples:

```bash
wp turbo-search stats
```

**Output:**
```text
+------------------------------------+---------+
| Metric                             | Value   |
+------------------------------------+---------+
| Indexed documents (live)           | 1,450   |
| Total searches (all-time)          | 12,840  |
| Searches (last 30 days)            | 3,420   |
| Searches (last 7 days)             | 890     |
| Cache hit rate                     | 82%     |
| Cache hits                         | 10,528  |
| Database queries                   | 2,312   |
| Zero-result searches (last 30 days)| 24      |
| Click-through rate (CTR)           | 64.2%   |
+------------------------------------+---------+
```

---

### 3.7 `wp turbo-search flush-cache`

Purges all cached search result sets across Redis, Memcached, Transients, and the WordPress Object Cache.

> [!NOTE]
> This command only clears transient query caches. It does **not** delete any documents from your search index.

#### Options:
* `[--yes]`: Bypass confirmation prompt.

#### Examples:

```bash
wp turbo-search flush-cache --yes
```

**Output:**
```text
Success: Cache flushed (driver: object-cache).
```

---

### 3.8 `wp turbo-search flush-index`

Completely flushes and wipes the search index table (`wp_wpts_index`) or external Typesense collection.

> [!WARNING]
> This will wipe the search index. Your actual WordPress posts and media files are **never deleted**. Run `wp turbo-search reindex --yes` after flushing to rebuild the index.

#### Options:
* `[--yes]`: Bypass confirmation prompt.

#### Examples:

```bash
wp turbo-search flush-index --yes
```

**Output:**
```text
Success: Search index flushed (engine: typesense). Run `wp turbo-search reindex` to rebuild.
```

---

### 3.9 `wp turbo-search prune-logs`

Prunes older search query analytics, click tracking records, and telemetry logs to maintain optimal database performance and comply with GDPR data retention limits.

#### Options:
* `[--days=<number>]`: Number of days of telemetry data to preserve (Default: `30`).
* `[--yes]`: Bypass confirmation prompt.

#### Examples:

```bash
# Keep last 14 days of analytics and delete older records
wp turbo-search prune-logs --days=14 --yes
```

**Output:**
```text
Success: Pruned search tracking logs older than 14 days.
```

---

### 3.10 `wp turbo-search flush-tracking`

Permanently purges all search tracking logs, click data, and analytics events.

#### Options:
* `[--yes]`: Bypass confirmation prompt.

#### Examples:

```bash
wp turbo-search flush-tracking --yes
```

**Output:**
```text
Success: All search tracking logs and analytics data have been flushed.
```

---

### 3.11 `wp turbo-search settings list`

Outputs all 72 plugin configuration settings and their current runtime values.

#### Options:
* `[--format=<table|json|csv>]`: Output format.

#### Examples:

```bash
# Output as table
wp turbo-search settings list

# Export current configuration as JSON for backup
wp turbo-search settings list --format=json > /backups/wpts-settings.json
```

---

### 3.12 `wp turbo-search settings get <key>`

Reads the value of a single plugin configuration option.

#### Arguments:
* `<key>`: Setting identifier (e.g. `search_engine`, `debounce_ms`, `index_attachments`).

#### Examples:

```bash
wp turbo-search settings get search_engine
# Returns: typesense

wp turbo-search settings get debounce_ms
# Returns: 200
```

---

### 3.13 `wp turbo-search settings set <key> <val>`

Updates a configuration setting directly from the command line with automatic type casting and sanitization.

#### Arguments:
* `<key>`: Setting identifier.
* `<value>`: Value to assign.

#### Examples:

```bash
# Switch active search engine to Typesense
wp turbo-search settings set search_engine typesense

# Set debounce timing to 150ms
wp turbo-search settings set debounce_ms 150

# Enable PDF and document indexing
wp turbo-search settings set index_attachments 1
```

---

## 4. DevOps Automation Recipes

### Recipe 1: Nightly Server Crontab Maintenance

Add the following entries to your server crontab (`crontab -e`) for automated background re-indexing, cache pruning, and log maintenance:

```bash
# 1. Nightly search index synchronization at 2:30 AM
30 2 * * * /usr/local/bin/wp turbo-search reindex --path=/var/www/html --batch=200 --yes > /var/log/wpts-reindex.log 2>&1

# 2. Weekly log pruning (retain 30 days) every Sunday at 3:00 AM
0 3 * * 0 /usr/local/bin/wp turbo-search prune-logs --days=30 --path=/var/www/html --yes > /dev/null 2>&1
```

---

### Recipe 2: CI/CD Deployment Pipeline Hook

Include this snippet in your deployment script (GitHub Actions, GitLab CI, Deployer, Forge, Ploi, or RunCloud) to refresh the index and clear caches upon new code release:

```bash
#!/usr/bin/env bash
set -e

echo "🚀 Post-deployment: Syncing Turbo Search..."

# Ensure database tables exist and are up to date
wp turbo-search health --path=/var/www/html

# Flush transient query caches
wp turbo-search flush-cache --path=/var/www/html --yes

# Rebuild search index non-interactively
wp turbo-search reindex --path=/var/www/html --batch=250 --yes

echo "✅ Turbo Search successfully synced!"
```

---

### Recipe 3: Multisite Network-Wide Synchronization

Use this bash script to iterate across every subsite in a WordPress Multisite network and re-index all network content:

```bash
#!/usr/bin/env bash
WP_PATH="/var/www/html"

echo "🌐 Re-indexing all sites in the Multisite Network..."

for site_url in $(wp site list --field=url --path="$WP_PATH"); do
    echo "⚙️ Syncing site: $site_url"
    wp turbo-search reindex --url="$site_url" --path="$WP_PATH" --batch=150 --yes
    wp turbo-search flush-cache --url="$site_url" --path="$WP_PATH" --yes
done

echo "🎉 Multisite Network re-indexing complete!"
```

---

### Recipe 4: Datadog / Nagios / Monit Health Check

Monitor search engine uptime and health programmatically:

```bash
#!/usr/bin/env bash
HEALTH_JSON=$(wp turbo-search health --path=/var/www/html --format=json 2>/dev/null)

if echo "$HEALTH_JSON" | grep -q '"Status":"PASS"'; then
    echo "OK - Turbo Search is fully operational."
    exit 0
else
    echo "CRITICAL - Turbo Search health check failed!"
    echo "$HEALTH_JSON"
    exit 2
fi
```

---

## 5. Exit Codes & Troubleshooting

| Exit Code | Meaning | Common Cause & Resolution |
| :--- | :--- | :--- |
| **`0`** | **Success** | Command completed cleanly with zero errors. |
| **`1`** | **General Error** | Missing required parameters, invalid post ID, or network timeout to external search engine. |

### Common CLI Troubleshooting Tips:

1. **`unknown --id parameter`**:
   - Update to the latest release which registers `--id` in the WP-CLI synopsis. You can also run `wp turbo-search index <id>` directly.
2. **`Connection refused (Typesense / Elasticsearch)`**:
   - Verify that your external server daemon is running:
     ```bash
     curl http://localhost:8108/health
     ```
3. **`Memory limit exhausted during reindex`**:
   - Reduce batch size with `--batch=50` or increase CLI memory allocation:
     ```bash
     php -d memory_limit=512M /usr/local/bin/wp turbo-search reindex --batch=50 --yes
     ```



