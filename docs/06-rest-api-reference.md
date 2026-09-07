# Chapter 6: REST API Reference & Endpoint Specification

All Turbo Search REST API endpoints are registered under the `/wp-json/wpts/v1/` namespace.

---

## 1. Authentication & Security

* **Public Endpoints**: (`/search`, `/track-click`, `/suggest`, `/trending`) are accessible without authentication. Rate limiting, bot protection, and role-based field masking are applied automatically.
* **Admin Endpoints**: Require an authenticated WordPress administrator session (`manage_options` capability) with a valid WordPress REST Nonce (`X-WP-Nonce` header or cookie authentication).

---

## 2. Public Search Endpoints

### `GET /wp-json/wpts/v1/search`
Executes a high-speed search across indexed posts, products, pages, and document attachments.

#### Request Headers:
* `If-None-Match` *(optional)*: Send previous ETag header to receive `304 Not Modified` on unchanged queries.

#### Query Parameters:

| Parameter | Type | Required | Default | Description |
| :--- | :--- | :--- | :--- | :--- |
| `q` | `string` | **Yes** | - | Search keyword or phrase (e.g. `wireless headphones`). |
| `page` | `int` | No | `1` | Page number for paginated queries. |
| `per_page` | `int` | No | `10` | Number of results per page (Max: `50`). |
| `post_type` | `string` | No | *(all)* | Comma-separated post types to filter (e.g. `product,attachment`). |
| `category` | `string` | No | `""` | Filter by category slug or taxonomy term. |
| `lang` | `string` | No | `""` | ISO 639-1 language code (e.g. `en`, `es`, `fr`). |
| `site_id` | `int` | No | `1` | Subsite ID for WordPress Multisite network queries. |

#### Example Request:
```bash
curl -X GET "https://example.com/wp-json/wpts/v1/search?q=macbook&per_page=5" \
     -H "Accept: application/json"
```

#### Successful JSON Response (`200 OK`):
```json
{
  "hits": [
    {
      "post_id": 101,
      "title": "Apple MacBook Pro 16\" M3 <mark class=\"wpts-mark\">Laptop</mark>",
      "excerpt": "Ultra-fast performance <mark class=\"wpts-mark\">laptop</mark> engineered for developers with liquid retina XDR display...",
      "post_type": "product",
      "url": "https://example.com/product/macbook-pro-16/",
      "thumbnail_url": "https://example.com/wp-content/uploads/2026/01/macbook.jpg",
      "sku": "MBP-16-M3",
      "price": 2499.00,
      "stock_status": "instock"
    }
  ],
  "found": 1,
  "page": 1,
  "max_pages": 1,
  "facets": {
    "categories": [
      { "name": "Laptops", "count": 1, "slug": "laptops" }
    ],
    "post_types": [
      { "name": "Products", "count": 1, "type": "product" }
    ]
  },
  "did_you_mean": null,
  "took_ms": 2.4,
  "cached": true
}
```

---

### `POST /wp-json/wpts/v1/track-click`
Records Click-Through-Rate (CTR) analytics for ranked search results. Supports asynchronous beacon dispatching via `navigator.sendBeacon()`.

#### Payload Schema:
```json
{
  "query": "macbook pro",
  "post_id": 101,
  "position": 1
}
```

#### Example Request:
```bash
curl -X POST "https://example.com/wp-json/wpts/v1/track-click" \
     -H "Content-Type: application/json" \
     -d '{"query":"macbook pro","post_id":101,"position":1}'
```

---

## 3. Admin & Configuration Endpoints (`manage_options`)

All administrative routes require authentication. Include the `X-WP-Nonce` header or standard Application Password authentication.

### Endpoint Matrix:

| HTTP Method | Route | Description |
| :--- | :--- | :--- |
| `GET` | `/wp-json/wpts/v1/config` | Retrieves all plugin configuration settings, active driver status, and post types. |
| `POST` | `/wp-json/wpts/v1/config` | Saves plugin settings and immediately invalidates outdated caches. |
| `GET` | `/wp-json/wpts/v1/synonyms` | Returns all registered synonym cluster groups. |
| `POST` | `/wp-json/wpts/v1/synonyms` | Creates a new synonym cluster (e.g. `{"terms":"couch, sofa, settee"}`). |
| `DELETE`| `/wp-json/wpts/v1/synonyms/{id}`| Permanently deletes a synonym cluster by ID. |
| `POST` | `/wp-json/wpts/v1/reindex-chunk`| Re-indexes a single batch of posts in the background (`{"page":1,"batch":50}`). |
| `POST` | `/wp-json/wpts/v1/flush-cache` | Wipes the entire search query cache across Redis, Memcached, and Transients. |
| `POST` | `/wp-json/wpts/v1/flush-engine`| Truncates and flushes the search index table. |
| `POST` | `/wp-json/wpts/v1/flush-tracking`| Prunes telemetry analytics search logs. |
| `POST` | `/wp-json/wpts/v1/reset-settings`| Restores all plugin settings to factory defaults. |
| `POST` | `/wp-json/wpts/v1/test-connection`| Tests connection to Typesense, Elasticsearch, Redis, or Memcached (`{"target":"typesense"}`). |
| `GET` | `/wp-json/wpts/v1/analytics` | Returns aggregated search volume, top queries, zero-result terms, and CTR stats. |
| `GET` | `/wp-json/wpts/v1/docs` | Returns full markdown documentation chapters for the admin UI. |
| `GET` | `/wp-json/wpts/v1/hooks` | Returns the complete developer hooks and actions reference. |

---

## 4. Error Responses & Status Codes

Turbo Search returns standard HTTP status codes and structured JSON errors:

```json
{
  "code": "rest_forbidden",
  "message": "Sorry, you are not allowed to do that.",
  "data": {
    "status": 403
  }
}
```

* **`200 OK`**: Request executed successfully.
* **`304 Not Modified`**: Search results have not changed since last ETag; client renders from browser cache.
* **`400 Bad Request`**: Missing required parameters (e.g. empty search query).
* **`403 Forbidden`**: Insufficient user capabilities for admin management endpoint.
* **`429 Too Many Requests`**: Search query rate limit exceeded (triggered by Bot Protection engine).
* **`500 Internal Server Error`**: Database or external search engine connection failure.

