# Turbo Search: Complete Documentation & Technical Reference

Welcome to the official technical documentation for **Turbo Search (v1.0.0)** - the enterprise-grade, high-performance search engine and discovery platform built natively for WordPress and WooCommerce.

---

## 🏛️ System Architecture Overview

```
                                    ┌──────────────────────────────────────┐
                                    │       Frontend User Experience       │
                                    │ • Command+K Modal  • Instant Dropdown│
                                    │ • Voice Search     • Quick Buy Cart  │
                                    └──────────────────┬───────────────────┘
                                                       │ REST API (JSON)
                                                       ▼
                                    ┌──────────────────────────────────────┐
                                    │        REST / AJAX Search API        │
                                    │  /wp-json/wpts/v1/search (< 5ms)     │
                                    │ • Bot Protection   • Role Security   │
                                    └──────────────────┬───────────────────┘
                                                       │
                     ┌─────────────────────────────────┴─────────────────────────────────┐
                     ▼                                                                   ▼
       ┌───────────────────────────┐                                       ┌───────────────────────────┐
       │   Multi-Tier Cache Layer  │                                       │   Hybrid AI Vector Search │
       │ • Browser Local Memory    │                                       │ • OpenAI text-embedding-3 │
       │ • HTTP 304 ETag Headers   │                                       │ • Local Self-Hosted Ollama│
       │ • Redis / Memcached Cache │                                       │ • Semantic Reranking (RRF)│
       └─────────────┬─────────────┘                                       └─────────────┬─────────────┘
                     │ Cache Miss                                                        │ Vector Cosine Score
                     ▼                                                                   ▼
┌──────────────────────────────────────────────────────────────────────────────────────────────────────┐
│                                   Pluggable Search Engine Drivers                                     │
│  ┌──────────────────────────────┐  ┌──────────────────────────────┐  ┌──────────────────────────────┐ │
│  │   MySQL / MariaDB Engine     │  │   Typesense Engine           │  │   Elasticsearch / OpenSearch │ │
│  │ • Weighted FULLTEXT scoring  │  │ • Sub-millisecond C++ engine │  │ • Distributed enterprise     │ │
│  │ • Exact phrases & exclusions │  │ • Typo-tolerant fuzzy search │  │ • Massive multi-node cluster │ │
│  └──────────────────────────────┘  └──────────────────────────────┘  └──────────────────────────────┘ │
└──────────────────────────────────────────────────┬───────────────────────────────────────────────────┘
                                                   │
                                                   ▼
┌──────────────────────────────────────────────────────────────────────────────────────────────────────┐
│                                Document Extraction & Indexing Engine                                  │
│ • Real-Time Incremental Sync (0ms delay on post/product/attachment CRUD)                             │
│ • PDF Full-Text Decompression (FlateDecode zlib streams + pdftotext CLI fallback)                    │
│ • Microsoft Word Documents (.docx via ZipArchive & WordprocessingML body parser)                     │
│ • Plain Text & Spreadsheets (.txt, .csv, .tsv, .md, .log up to 500KB per file)                       │
│ • Automated WP-Cron Scheduled Synchronization (Hourly, Twice Daily, Daily, Weekly)                   │
└──────────────────────────────────────────────────────────────────────────────────────────────────────┘
```

---

## 📚 Complete Documentation Sitemap

| Chapter | Title | Summary & Key Topics Covered |
| :--- | :--- | :--- |
| **[01](01-installation-and-setup.md)** | **[Installation, Requirements & Search Engine Setup](01-installation-and-setup.md)** | System requirements matrix, automatic table installer, MySQL FULLTEXT tuning, Typesense Cloud/Local setup, Elasticsearch/OpenSearch cluster configuration, and Redis/Memcached caching. |
| **[02](02-search-features-and-shortcodes.md)** | **[Search Features, Shortcodes & Frontend UX](02-search-features-and-shortcodes.md)** | `[wpts_search]` shortcode attributes, Gutenberg block, 3 Results Layout Modes (List, Grid, Compact Card), Command+K Spotlight modal, Category filter multi-tabs, Web Speech voice search, WooCommerce 1-click cart, recent history, and full-text document search. |
| **[03](03-indexing-and-caching.md)** | **[Indexing, Document Extraction & Caching Architecture](03-indexing-and-caching.md)** | Real-time CRUD synchronization, PDF/DOCX/TXT/CSV extraction technology, 4-tier query caching architecture, and Multi-Tier Fallback Architecture to native WordPress Core `WP_Query`. |
| **[04](04-ai-vector-search.md)** | **[Hybrid Semantic AI Vector Search](04-ai-vector-search.md)** | Combining keyword fulltext with OpenAI or local Ollama embeddings, Reciprocal Rank Fusion (RRF), cosine similarity reranking, and postmeta vector caching. |
| **[05](05-developer-hooks-api.md)** | **[Developer Hooks & Actions API](05-developer-hooks-api.md)** | Comprehensive developer reference for indexing filters, search query transformers, SQL WHERE injectors, result card mutators, fallback filters (`wpts_core_fallback_query_args`), and cache invalidation actions with copy-paste code snippets. |
| **[06](06-rest-api-reference.md)** | **[REST API Reference](06-rest-api-reference.md)** | Complete specification of all public search, autocomplete, and CTR tracking endpoints, as well as admin management routes with schemas and response codes. |
| **[07](07-multisite-multilingual.md)** | **[Multisite & Multilingual Architecture](07-multisite-multilingual.md)** | Cross-network search across WordPress Multisite subsites, WPML integration, Polylang integration, and automatic frontend locale detection. |
| **[08](08-cli-commands.md)** | **[WP-CLI Command Suite Reference](08-cli-commands.md)** | Full guide to the unified `wp turbo-search` command suite for re-indexing, cache flushes, telemetry pruning, diagnostics, and options management in DevOps pipelines. |
| **[09](09-complete-settings-reference.md)** | **[Complete Settings & Configuration Reference](09-complete-settings-reference.md)** | Exhaustive technical reference covering all 72 configuration options, 3 Results Layout choices, archive live query interception, data types, defaults, validation rules, and best-practice recommendations. |

---

## ⚡ Quick Start in 3 Steps

### Step 1: Install & Activate
1. Upload the `wp-search-plugin` folder to your `/wp-content/plugins/` directory.
2. Activate the plugin in **WordPress Admin → Plugins**.
3. All required custom database tables (`wp_wpts_index`, `wp_wpts_search_log`, `wp_wpts_synonyms`, `wp_wpts_analytics_summary`, `wp_wpts_events`) are provisioned automatically.

### Step 2: Build the Search Index
1. Navigate to **Turbo Search → Index Manager** (`/wp-admin/admin.php?page=wpts-index`).
2. Click **`↺ Re-index All Posts Now`** (or run `wp turbo-search reindex --yes` in your terminal).

### Step 3: Embed Search Anywhere
* **Gutenberg Block**: In the Block Editor, insert the **"Turbo Search Bar"** block.
* **Shortcode**: Add `[wpts_search placeholder="Search products, articles, documents..."]` to any page or widget area.
* **PHP Template Tag**: Add `<?php if ( function_exists( 'wpts_render_search_shortcode' ) ) echo wpts_render_search_shortcode(); ?>` directly into your header or template file.
