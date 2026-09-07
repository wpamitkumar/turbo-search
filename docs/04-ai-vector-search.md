# Chapter 4: Hybrid Semantic AI Vector Search

Turbo Search features native **Hybrid Semantic AI Vector Search**, combining the speed and exact precision of keyword fulltext search with the conceptual intelligence of deep AI embeddings.

---

## 1. Lexical vs Semantic vs Hybrid Search

| Search Type | Query Example | Matches Found | Weakness |
| :--- | :--- | :--- | :--- |
| **Traditional Lexical (Fulltext)** | *"cordless lawn trimmer"* | Matches only articles/products containing the exact words *"cordless"*, *"lawn"*, or *"trimmer"*. | Misses synonyms, intent, and colloquial phrases like *"battery weed whacker"*. |
| **Pure Vector (AI Embeddings)** | *"how to tidy overgrown grass without cords"* | Understands intent and matches *"Battery-Powered Weed Eater"*. | Slower on large databases, struggles with exact SKU numbers or model codes (`MBP-M3-16`). |
| **Hybrid Search (Turbo Search)** | *"how to tidy overgrown grass without cords"* | **Combines both!** Instant keyword scoring + AI semantic reranking. | **Best of both worlds**: Sub-10ms response, exact SKU matching, and deep semantic intelligence. |

---

## 2. Mathematical Hybrid Reranking Formula

Turbo Search uses **Reciprocal Rank Fusion (RRF)** and normalized vector cosine similarity to merge results:

$$\text{FinalScore} = (1 - w) \times \text{FulltextScore}_{\text{norm}} + w \times \text{CosineSimilarity}(\vec{Q}, \vec{D})$$

Where:
* $w$ is the **Semantic Weight** configured in settings ($0.0 \le w \le 1.0$, default: $0.3$).
* $\vec{Q}$ is the high-dimensional embedding vector of the search query.
* $\vec{D}$ is the cached embedding vector of the indexed document.
* $\text{CosineSimilarity}(\vec{u}, \vec{v}) = \frac{\vec{u} \cdot \vec{v}}{\|\vec{u}\| \|\vec{v}\|}$.

```
                 ┌─────────────────────────────────────────┐
                 │       User Query: "laptop for coding"   │
                 └────────────────────┬────────────────────┘
                                      │
            ┌─────────────────────────┴─────────────────────────┐
            ▼                                                   ▼
 ┌───────────────────────────────┐               ┌───────────────────────────────┐
 │ 1. Lexical Keyword Query      │               │ 2. AI Embedding Generator     │
 │    • MySQL FULLTEXT / Typesense│               │    • OpenAI text-embedding-3  │
 │    • Score: 0.85              │               │    • Ollama nomic-embed-text  │
 └──────────────┬────────────────┘               └──────────────┬────────────────┘
                │                                               │
                │ Fulltext Score (70%)                          │ Vector Similarity (30%)
                └───────────────────────┬───────────────────────┘
                                        ▼
                         ┌─────────────────────────────┐
                         │  Hybrid Rank Normalizer     │
                         │  Weighted Blended Results   │
                         └─────────────────────────────┘
```

---

## 3. Supported Embedding Providers & Setup

Navigate to **Turbo Search → Settings → AI Vector Search** (`/wp-admin/admin.php?page=wpts-settings&tab=vector`).

### Provider 1: OpenAI (Cloud AI)
* **Best for**: Out-of-the-box cloud setup with state-of-the-art semantic accuracy.
* **Supported Models**:
  * `text-embedding-3-small` *(Recommended)*: 1,536 dimensions. Extremely fast, low cost (~$0.02 / 1M tokens).
  * `text-embedding-3-large`: 3,072 dimensions. Highest semantic precision for specialized technical docs.
  * `text-embedding-ada-002`: Legacy 1,536 dimension model.
* **Setup**:
  1. Set **AI Embedding Provider** to `OpenAI`.
  2. Enter your OpenAI API Key (`sk-proj-...`).
  3. Select your model.
  4. Click **Test AI Connection**.

---

### Provider 2: Local Self-Hosted Ollama (Zero Cost & 100% Private)
* **Best for**: Self-hosted servers, privacy-conscious enterprise intranets, HIPAA/GDPR environments with zero data egress.
* **Supported Models**: `nomic-embed-text`, `all-minilm`, `bge-m3`, `mxbai-embed-large`.
* **Setup**:
  1. Install and start Ollama on your server or local machine:

```bash
# Install Ollama
curl -fsSL https://ollama.com/install.sh | sh

# Pull the high-performance embedding model
ollama pull nomic-embed-text

# Start Ollama server
ollama serve
```

  2. In Turbo Search, set **Provider** to `Local Ollama`.
  3. Enter the Endpoint URL: `http://localhost:11434/api/embeddings` (or server IP).
  4. Set Model Name: `nomic-embed-text`.
  5. Click **Test AI Connection**.

---

### Provider 3: Custom HTTP Embedding Microservice
* **Best for**: Connecting custom Python FastAPI/Flask endpoints, HuggingFace Text Embeddings Inference (TEI), or vLLM deployments.
* **Setup**:
  1. Set **Provider** to `Custom HTTP API`.
  2. Provide your endpoint URL and authorization headers.

---

## 4. Vector Caching Architecture & Cost Optimization

To prevent recurring API fees and eliminate latency during searches:

1. **Persistent PostMeta Caching**: Generated vector arrays are cached directly in WordPress postmeta (`_wpts_embedding`).
2. **Incremental Vector Generation**: When a post or document is edited, only that specific item generates a new embedding. Unchanged posts never re-query the AI provider.
3. **Batch Vector Generation**: During bulk re-indexing, documents are processed in memory-safe batches of 20 to prevent rate-limit errors.

---

## 5. Tuning the Semantic Reranking Weight

You can fine-tune the balance between exact keyword matching and conceptual semantic similarity via the **Vector Semantic Weight** slider:

* **`0.0`**: Pure keyword search (0% AI vector influence).
* **`0.3`** *(Default Recommended)*: 70% Keyword Fulltext + 30% AI Vector. Perfect balance of exact SKU/model matching and conceptual query understanding.
* **`0.5`**: 50% Keyword + 50% AI Vector. Ideal for blogs, knowledgebases, and educational content.
* **`1.0`**: Pure semantic vector similarity.
