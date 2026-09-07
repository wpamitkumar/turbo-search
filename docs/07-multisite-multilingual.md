# Chapter 7: Multisite & Multilingual Architecture

Turbo Search includes enterprise multi-tenant support for **WordPress Multisite Networks** and seamless integration with **WPML** and **Polylang**.

---

## 1. WordPress Multisite Cross-Network Search

Turbo Search allows visitors to search across individual subsites or query the entire network from a single global search bar.

```
                           ┌─────────────────────────────┐
                           │   Network Search Request    │
                           │     "developer tools"       │
                           └──────────────┬──────────────┘
                                          │
                  ┌───────────────────────┼───────────────────────┐
                  ▼                       ▼                       ▼
       ┌─────────────────────┐ ┌─────────────────────┐ ┌─────────────────────┐
       │ Subsite 1 (Main)    │ │ Subsite 2 (Docs)    │ │ Subsite 3 (Store)   │
       │ blog_id: 1          │ │ blog_id: 2          │ │ blog_id: 3          │
       └──────────┬──────────┘ └──────────┬──────────┘ └──────────┬──────────┘
                  │                       │                       │
                  └───────────────────────┼───────────────────────┘
                                          ▼
                      ┌───────────────────────────────────────┐
                      │   Aggregated Global Search Results    │
                      │  [Docs] API Reference (#101)          │
                      │  [Store] Developer Pro License (#502) │
                      └───────────────────────────────────────┘
```

### Key Multisite Features:
* **Network-Aware Database Schema**: The `wp_wpts_index` table includes a dedicated `blog_id` BIGINT column.
* **Zero Cross-Query Bloat**: In standalone mode, queries strictly filter by `WHERE blog_id = %d`. When **Cross-Network Search** is enabled, all public, unarchived subsites are queried in parallel.
* **Origin Subsite Badging**: Results returned from other subsites display the origin blog name badge and resolve correct cross-domain permalinks.
* **WP-CLI Network Re-indexing**:

```bash
# Re-index all sites in the entire network
wp site list --field=url | xargs -n 1 -I {} wp turbo-search reindex --url={} --yes
```

---

## 2. Multilingual Architecture (WPML & Polylang)

Turbo Search natively detects and integrates with **WPML** and **Polylang** without requiring add-on plugins.

### A. How Multilingual Indexing Works:

1. **Automatic Language Tagging**:
   * During document ingestion, the plugin queries the active translation manager (`WPML` or `Polylang`) to retrieve the ISO 639-1 language code (e.g. `en`, `es`, `fr`, `de`, `ja`, `zh`).
   * The code is stored in the `language_code` column of `wp_wpts_index`.

2. **Real-Time Translation Synchronization**:
   * When an editor publishes or updates a translation, the plugin hooks into translation lifecycle events:
     * **Polylang**: `pll_save_post`
     * **WPML**: `icl_after_save_post`
   * Only the translated post payload is refreshed in the search index in real time.

3. **Frontend Automatic Locale Detection**:
   * The frontend search component automatically detects the active page language using `document.documentElement.lang` and includes `&lang=xx` in all REST API search queries.
   * Visitors searching on `/es/` only receive Spanish results; visitors on `/fr/` only receive French results.

---

## 3. Multilingual Shortcode Overrides

You can explicitly lock a search bar instance to a specific language:

```text
# Force Spanish-only search on a specific landing page
[wpts_search placeholder="Buscar productos..." lang="es"]

# Force English documentation search
[wpts_search placeholder="Search documentation..." lang="en"]
```

