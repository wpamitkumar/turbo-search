# Chapter 2: Search Features, Shortcodes & Frontend UX

Turbo Search delivers an instant, ultra-responsive frontend search experience with sub-10ms query execution, Spotlight-style keyboard navigation, Web Speech voice recognition, category filtering tabs, WooCommerce 1-click cart addition, and full-text document discovery.

---

## 1. Embedding Search on Your Site

### A. Shortcodes

#### 1. Main Search Bar: `[wpts_search]`
Embeds an interactive instant-search input with live dropdown suggestions and modal support.

```text
[wpts_search]
```

#### Customized Shortcode Examples:
```text
# eCommerce Product Search with Grid Cards, Quick Cart, and Dark Theme
[wpts_search placeholder="Search products by title, SKU, or brand..." post_types="product" layout="grid" per_page="12" theme="dark" show_categories="true"]

# Multi-Post-Type Content Search (Posts, Pages, and Products)
[wpts_search placeholder="Search everything..." post_types="post,page,product" layout="list" theme="light"]

# Documentation & PDF Manuals Search
[wpts_search placeholder="Search manuals, PDF guides, and docs..." post_types="attachment,post" layout="grid" show_categories="false" theme="minimal"]
```

#### Complete `[wpts_search]` Attribute Reference:

| Attribute | Type | Default | Description |
| :--- | :--- | :--- | :--- |
| `placeholder` | `string` | `Search…` | Placeholder text inside the search input. |
| `post_types` / `post_type` | `string` | *(all configured)* | Comma-separated post types to query (e.g. `post,page,product,attachment`). |
| `layout` | `string` | *(from Settings)* | Display layout: `list` (List View), `grid` (Grid Cards), or `card` (Compact Card). |
| `per_page` | `int` | `8` | Maximum number of results displayed in the instant dropdown. |
| `debounce` | `int` | `200` | Typing delay in milliseconds before initiating the search request. |
| `throttle` | `int` | `0` | Maximum fire frequency while typing (0 = disabled). |
| `min_chars` | `int` | `2` | Minimum character length required before search fires. |
| `theme` | `string` | `light` | Visual theme: `light`, `dark`, `minimal`, or `glass`. |
| `category_tabs` | `bool` | `true` | Display instant category multi-tabs at the top of the search dropdown. |
| `quick_cart` | `bool` | `true` | Display 1-click WooCommerce AJAX add-to-cart button for products. |
| `show_voice` | `bool` | `true` | Display the Web Speech API voice search microphone button (works with HTTPS connection only). |
| `enable_command_k`| `bool` | `true` | Display the `⌘K` / `Ctrl+K` keyboard shortcut badge. |
| `show_excerpt` | `bool` | `true` | Display the highlighted Keyword-In-Context (KWIC) content snippet. |
| `show_type` | `bool` | `true` | Display the post type badge (Post, Page, Doc, etc.). |
| `new_tab` | `bool` | `false` | Open clicked search result links in a new browser tab (`target="_blank"`). |
| `class` | `string` | `""` | Custom CSS container class name for custom styling. |

---

#### 2. Dedicated Full-Page Archive: `[wpts_search_results]`
Embeds a complete search results archive page with faceted category filters, sorting options, instant client-side **⊞ Grid** / **☰ List** view switcher, and pagination:

```text
[wpts_search_results per_page="12" layout="grid"]
```

#### 3. Trending Searches Widget: `[wpts_trending]`
Displays a clickable list of the most popular search terms on your site:

```text
[wpts_trending limit="5" title="🔥 Popular Searches"]
```

---

### B. Gutenberg & Full Site Editing (FSE) Block

1. Open any Page, Post, or Site Editor template in the WordPress Block Editor.
2. Click the **`+` (Add Block)** button and search for **"Turbo Search Bar"**.
3. Use the block sidebar inspector to customize:
   * **Filter by Post Types (Multiple)**: Check any combination of post types (`Posts`, `Pages`, `Products`, `Media & Documents`) or use the 1-click quick toggle pills.
   * **Results Layout**: Select `🌐 Default (Inherit Global Settings)`, `📄 List View`, `⊞ Grid Cards`, or `🗂️ Compact Card`.
   * **Placeholder Text**
   * **Visual Theme Preset** (`Light`, `Dark`, `Minimal`, `Glassmorphism`, `Custom`)
   * **Categorized Multi-Tabs in Dropdown**
   * **WooCommerce 1-Click Add-to-Cart**
   * **Debounce & Throttle Timings**

---

### C. PHP Template Tag & Theme Integration

To embed the search bar directly into your theme's `header.php`, navigation menu, or template files:

```php
<?php
if ( function_exists( 'wpts_render_search_shortcode' ) ) {
    echo wpts_render_search_shortcode( [
        'placeholder'      => __( 'Search store...', 'my-theme' ),
        'theme'            => 'light',
        'post_type'        => 'product,post',
        'results_limit'    => 8,
        'show_categories'  => true,
    ] );
}
?>
```

---

## 2. Interactive Frontend Features

### ⌨️ Command + K / Ctrl + K Spotlight Modal
Visitors can press **`⌘K` (Mac)** or **`Ctrl+K` (Windows/Linux)** from anywhere on your website to launch an Apple Spotlight-inspired search modal overlay with backdrop blur:
* **`↑` / `↓` Arrow Keys**: Navigate up and down through search results.
* **`Enter`**: Open the currently highlighted result.
* **`Esc`**: Instantly close the modal.
* **Mobile Support**: Clicking the search input on mobile opens a full-screen, touch-optimized search overlay.

### 🎙️ Web Speech API Voice Search
Clicking the microphone icon in modern browsers (Chrome, Edge, Safari) activates native browser voice recognition. Speech is transcribed in real-time into the search input with instant results.

> [!NOTE]
> **HTTPS Requirement**: Under modern W3C browser security standards, Voice Search works only with an **HTTPS connection** (or `localhost` during local development). On plain HTTP connections, browsers strictly block microphone access.

### 🏷️ Category Multi-Tabs (Zero-Latency Filtering)
Displays multi-tabs (`All`, `Products`, `Posts`, `Documentation`) at the top of the search dropdown. Switching tabs filters results instantly in the browser without making a second network request.

### 🛒 WooCommerce 1-Click "Add to Cart" & Live Inventory
When products appear in search results, visitors see live pricing, sale badges, and stock availability (`In Stock`, `Out of Stock`). Simple products include an AJAX **"Add to Cart"** button that updates the cart without page reloads.

### 📜 Recent Search History & Favorites
Visitors and logged-in users automatically see their recent queries and can click a star icon (`★`) to bookmark frequently used searches for quick access.

### 📄 PDF, Word (.docx), TXT & CSV Full-Text Document Search
Turbo Search extracts and indexes the full text of media attachments and documents attached to posts and products:
- **PDF Documents (`.pdf`)**: Parses compressed `/FlateDecode` zlib streams, TJ arrays, and hex strings from modern PDF files with automatic fallback to `pdftotext`.
- **Microsoft Word Documents (`.docx`)**: Decompresses Word XML streams (`word/document.xml`) to extract paragraphs, lists, and table contents.
- **Spreadsheets & Data (`.csv`, `.tsv`)**: Indexes raw tabular records, SKUs, and data cells.
- **Plain Text & Documentation (`.txt`, `.md`, `.log`)**: Extracts clean text up to 500KB per file.
- **Direct Downloads in Search**: Results link directly to the media file or parent post with keyword highlights.

---

## 3. CSS Customization & Theme Variables

Turbo Search uses standard CSS variables for seamless theme integration. You can override these variables in your child theme's `style.css` or the WordPress Customizer:

```css
:root {
    --wpts-primary: #3b82f6;          /* Accent & Highlight Color */
    --wpts-primary-hover: #2563eb;    /* Primary Button Hover */
    --wpts-bg: #ffffff;               /* Dropdown & Modal Background */
    --wpts-text: #1e293b;             /* Primary Text Color */
    --wpts-text-muted: #64748b;       /* Excerpt & Metadata Color */
    --wpts-border: #e2e8f0;           /* Border & Divider Color */
    --wpts-radius: 12px;              /* Border Radius */
    --wpts-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
}
```
