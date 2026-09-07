# Chapter 3: Indexing, Document Extraction & Caching Architecture

## 1. Real-Time Instant Sync (0ms Delay)

Turbo Search operates on an **event-driven incremental architecture**:

```
[WordPress Admin / WooCommerce / REST]
               │
               ▼ (save_post, edit_attachment, delete_post)
 ┌─────────────────────────────────────────────────────────────┐
 │ Turbo Search Hook Interceptor                            │
 │  1. Builds document payload (< 1ms)                        │
 │  2. Extracts PDF/DOCX text, taxonomies, comments, meta     │
 │  3. Upserts/deletes directly in search index & driver       │
 │  4. Invalidates cached queries for that specific item       │
 └─────────────────────────────────────────────────────────────┘
```

* **No Full Re-indexing Required for Post CRUD**: Whenever an editor publishes, edits, or deletes an article, product, or PDF, only that specific document is updated immediately in **< 1 millisecond**.

---

## 2. Document & File Attachment Full-Text Extraction Engine

Turbo Search features a native, pure-PHP document extraction engine that extracts and indexes full-text content from uploaded attachments and documents attached to posts and products.

### A. How It Works (Extraction & Search Lifecycle)

```
 [User Uploads File in WordPress]
 (PDF / DOCX / TXT / CSV / MD)
                │
                ▼
 ┌─────────────────────────────────────────────────────────────┐
 │ 1. WordPress Media Lifecycle Interceptor                    │
 │    Hooks: wp_update_attachment_metadata, added_post_meta   │
 │    Resolves: Filepath via get_attached_file($post_id)      │
 └──────────────────────────────┬──────────────────────────────┘
                                │
                                ▼
 ┌─────────────────────────────────────────────────────────────┐
 │ 2. Format Detection & Parser Dispatcher                     │
 │    Inspects MIME type & file extension (case-insensitive)   │
 └───────┬──────────────┬──────────────┬──────────────┬────────┘
         │              │              │              │
         ▼              ▼              ▼              ▼
   [.pdf parser]  [.docx parser] [.txt parser]  [.csv parser]
   FlateDecode    ZipArchive     Stream buffer  Tabular cell
   gzuncompress   word/doc.xml   UTF-8 sanitize tokenization
         │              │              │              │
         └──────────────┴──────┬───────┴──────────────┘
                               │
                               ▼
 ┌─────────────────────────────────────────────────────────────┐
 │ 3. Text Sanitization & Token Normalization                  │
 │    • Strips raw XML/PDF control tags                        │
 │    • Resolves word boundaries & whitespace                  │
 │    • UTF-8 / Multi-byte string normalization                │
 └──────────────────────────────┬──────────────────────────────┘
                                │
                                ▼
 ┌─────────────────────────────────────────────────────────────┐
 │ 4. Search Engine Indexing & Invalidation                    │
 │    • Stored in `wp_wpts_index.content` (MySQL/Typesense)   │
 │    • Query cache invalidated instantly                      │
 │    • Keyword-In-Context (KWIC) search snippet generated     │
 └─────────────────────────────────────────────────────────────┘
```

---

### B. Technologies & Libraries Behind Each Document Format

#### 1. PDF Documents (`.pdf`):
* **Technology / Libraries**:
  * **PHP `zlib` Extension**: Uses native `gzuncompress()`, `gzinflate()`, and `zlib_decode()` to decompress `/FlateDecode` streams.
  * **ISO 32000-1 PDF Stream Parser**: Parses PDF object matrices, extracting text operators (`Tj`, `'`, `"`), text matrices, and array-positioned strings (`[(Text 1) -20 (Text 2)] TJ`).
  * **UTF-16BE / CID Hexadecimal Decoder**: Decodes hexadecimal string representations (`<00480065006c006c006f>`) into UTF-8 characters via `mb_convert_encoding()`.
  * **Stream Boundary Normalization**: Trims variable stream padding (`\r\n` linebreaks from Canva, Word, and Acrobat) to prevent compression data errors.
  * **Poppler `pdftotext` CLI Fallback**: If `pdftotext` is installed on the host server (`exec('which pdftotext')`), it is used for accelerated parsing of complex PDF layouts.

#### 2. Microsoft Word Documents (`.docx`):
* **Technology / Libraries**:
  * **PHP `ZipArchive` (libzip wrapper)**: Reads the Office Open XML (OOXML / ECMA-376 / ISO/IEC 29500) container without requiring third-party composer dependencies or external Java/Node services.
  * **WordprocessingML Parser**: Extracts the primary document body from `word/document.xml`.
  * **Tag Boundary Spacing**: Converts closing block and paragraph XML nodes (`</w:p>`, `</w:r>`, `</w:t>`, `</w:tc>`, `</w:tr>`) into whitespace delimiters, preventing words across table cells and paragraphs from coalescing.

#### 3. Plain Text & Markdown (`.txt`, `.md`, `.log`):
* **Technology / Libraries**:
  * **Binary-Safe Stream Reader**: Reads up to 500KB of buffered text per document using `file_get_contents()`.
  * **Multi-Byte Sanitization**: Cleans control characters, strips dangerous script payloads, and normalizes UTF-8 encoding via `WPTS\Universal\Utils::clean_text()`.

#### 4. Spreadsheets & Tabular Data (`.csv`, `.tsv`):
* **Technology / Libraries**:
  * **Delimiter-Aware Tokenizer**: Ingests comma-separated and tab-separated records, converting column headers, SKU numbers, product identifiers, and cell values into searchable index tokens.

---

### C. WordPress Media Lifecycle Integration

When users upload media via **Media → Add New** or attach documents inside Gutenberg / Classic Editor:
1. WordPress uploads the file and creates an `attachment` post type with post status `inherit`.
2. WordPress saves the file location in postmeta `_wp_attached_file` and fires `wp_update_attachment_metadata` and `added_post_meta`.
3. Turbo Search intercepts these events immediately, locates the file on disk via `get_attached_file($post_id)`, extracts the full document text, and writes the indexed document to the active search engine.
4. When visitors search for words contained inside the document, the search result includes:
   * Document title.
   * Keyword-in-Context (KWIC) highlighted snippet showing the exact sentence matching the search term.
   * Direct download/view link (`wp_get_attachment_url($post_id)`).

---

## 3. Automated Background Scheduled Sync (WP-Cron)

For websites that import content in bulk via external feeds, WP All Import, or REST API scripts:

1. Navigate to **Turbo Search → Index Manager** (`/wp-admin/admin.php?page=wpts-index`).
2. In the **🤖 Automated Background Sync (WP-Cron)** section, turn **ON** `Enable Scheduled Background Auto Re-indexing`.
3. Choose your sync frequency:
   * **Every Hour**
   * **Twice Daily (Every 12h)**
   * **Once Daily (Midnight)**
   * **Once Weekly**
4. Choose your sync mode:
   * **Incremental (Recommended)**: Compares WordPress `post_modified_gmt` timestamps and only updates posts modified since the last sync.
   * **Full Re-index**: Completely rebuilds the search index in safe background chunks.

---

## 4. High-Performance Multi-Tier Caching

Turbo Search achieves **sub-5ms search speeds** via a 4-tier caching architecture:

```
[User Types in Searchbar]
         │
         ▼
┌──────────────────────────────────────┐
│ Tier 1: Client-Side In-Memory Cache  │ ──► Hit: Renders in 0ms (0 bytes network)
└──────────────────┬───────────────────┘
                   │ Miss
                   ▼
┌──────────────────────────────────────┐
│ Tier 2: HTTP 304 ETag Validation     │ ──► Hit: 304 Not Modified (< 1ms)
└──────────────────┬───────────────────┘
                   │ Miss
                   ▼
┌──────────────────────────────────────┐
│ Tier 3: Redis / Memcached / Transient│ ──► Hit: PHP Shutdown hook (< 2ms)
└──────────────────┬───────────────────┘
                   │ Miss
                   ▼
┌──────────────────────────────────────┐
│ Tier 4: Engine Query (MySQL/Typesense│ ──► Executes weighted fulltext search
└──────────────────────────────────────┘
```

* **Deferred Analytics Logging**: On cache hits, search telemetry is deferred to the PHP `shutdown` hook (`add_action('shutdown', ...)`), allowing search JSON to be returned to the browser immediately without waiting for disk writes.
* **Intelligent Cache Preservation**: Saving UI display options (like Results Layout, Themes, or Debounce) preserves all active cached search entries. Full cache flushing is restricted strictly to index-altering configuration changes (post types, weight adjustments, or engine swaps).
* **Normalized Cache Key Generation**: Search queries are lowercased and filter arguments are systematically sorted so searches across blocks, shortcodes, and widgets hit the identical cache entry.
* **Instant Client-Side Layout Switching**: Changing view modes (**⊞ Grid** vs **☰ List**) on search archive pages performs instant CSS class transitions in the browser without making redundant database queries.

---

## 5. Multi-Tier Server & Dependency Fallback (Zero Downtime Guarantee)

Turbo Search incorporates a **fail-safe degradation architecture**. If an external cluster, memory caching daemon, custom database table, or third-party plugin is temporarily unavailable or misconfigured, search automatically degrades to native WordPress Core functionality without raising PHP errors or interrupting site visitors:

```
[ User Search Request ]
           │
           ▼
┌────────────────────────────────────────────────────────┐
│ Tier 1: External Search Engine                         │
│ (Typesense Server or Elasticsearch / OpenSearch)       │
└──────────────────────────┬─────────────────────────────┘
                           │ ❌ Server offline / connection refused / 0 hits
                           ▼
┌────────────────────────────────────────────────────────┐
│ Tier 2: Internal MySQL Full-Text Index                 │
│ (Custom wp_wpts_index database table)                  │
└──────────────────────────┬─────────────────────────────┘
                           │ ❌ Table unindexed / missing / DB error / 0 hits
                           ▼
┌────────────────────────────────────────────────────────┐
│ Tier 3: WordPress Core Native Search                   │
│ (WPTS\Search\WPCoreFallback via WP_Query)              │
│ Returns clean results with highlights & thumbnails     │
└────────────────────────────────────────────────────────┘
```

### Key Fallback Mechanisms:

1. **Search Engine Degradation**:
   * If **Typesense** or **Elasticsearch** is unreachable or times out, search degrades to local **MySQL fulltext**.
   * If the `wp_wpts_index` table is missing, empty, or fails, search smoothly executes via native `WP_Query` through `WPTS\Search\WPCoreFallback`.
   * The response retains the standard search hit schema (`post_id`, `title`, `excerpt`, `thumbnail_url`, `url`, `price`, `sku`) with keyword highlighting.

2. **Cache Server Degradation**:
   * If **Redis** or **Memcached** fails to connect or the required PHP extension is missing, `CacheManager` automatically switches to **WordPress Transients** or **WP Object Cache**.
   * Cache read/write exceptions are caught silently, preventing white screens.

3. **Standard WordPress Query Interception (`pre_get_posts`)**:
   * When frontend search query interception is active, Turbo Search enhances native theme archive search queries (`/?s=keyword`).
   * If any server or database issue occurs, the interceptor silently catches the error and lets standard WordPress Core search run completely uninterrupted.

4. **Third-Party Dependency Protection**:
   * **WooCommerce**: All product SKU, pricing, and cart operations check `function_exists('wc_get_product')` and `function_exists('WC')` inside protected try/catch blocks. If WooCommerce is deactivated, product searches degrade safely to standard posts.
   * **AI Vector Search**: If OpenAI or Ollama endpoints are unreachable, vector reranking is bypassed and standard keyword results are delivered immediately.
   * **Document Parsers**: If `pdftotext` or PHP `ZipArchive` are missing, extraction gracefully skips without halting.

