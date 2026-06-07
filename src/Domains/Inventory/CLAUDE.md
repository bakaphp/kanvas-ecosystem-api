# Inventory — Product Search Engine, Indexing, Configuration & Typesense

Loads when work touches anything under `src/Domains/Inventory/`. Reference for how
product search works: the dynamic per-tenant engine, configuring Algolia /
Typesense / Meilisearch, indexing, and how Typesense Natural Language Search powers
the conversational gift-recommendation agent.

> Scope: focused on `Products`, but the engine-resolution model applies to every
> model using `Baka\Traits\DynamicSearchableTrait` (Users, Companies, Messages,
> Leads, Discounts, Agents, …). The recommendation **tools** live under
> `src/Domains/Intelligence/Agents/Laravel/Tools/Inventory/` (§7); that tree's
> CLAUDE.md points back here.

---

## 1. The big picture

```
Model::search($q)
   └─ DynamicSearchableTrait::searchableUsing()
        └─ SearchEngineResolver::resolveEngine($model, $app)   ← picks the engine PER APP / PER MODEL
             ├─ algolia      → AlgoliaEngine
             ├─ typesense    → TypesenseEngine
             ├─ meilisearch  → MeilisearchEngine
             └─ (anything else / unset) → NullEngine  (returns nothing)
```

- `config('scout.driver')` is `dynamic` by default — a custom Scout engine
  (`SearchEngineResolver`) that resolves the **real** engine at query/index time
  from the app's settings.
- In `.env`, `SCOUT_DRIVER` may be set to `null`, which makes Scout a no-op
  globally **unless** a tenant overrides it (see precedence below). This is why
  raw-SQL fallbacks exist in some tools — search isn't guaranteed to be on.

Key files:
| Concern | File |
|---|---|
| Engine resolution | `src/Baka/Search/SearchEngineResolver.php` |
| Dynamic searchable trait | `src/Baka/Traits/DynamicSearchableTrait.php` |
| Product indexable payload | `Products::toSearchableArray()` |
| Product index name | `Products::searchableAs()` |
| Index gating | `Products::shouldBeSearchable()` |
| Tenant-aware reindex command | `app/Console/Commands/Inventory/ScoutProductIndexProcessCommand.php` |
| Scout config | `config/scout.php` |

---

## 2. Engine resolution & precedence

From `SearchEngineResolver::resolveEngine()`:

```php
$defaultEngine       = $app->get('search_engine') ?? config('scout.driver', 'algolia');
$modelSpecificEngine = $app->get($model->getTable() . '_search_engine');  // e.g. products_search_engine
$engine              = $modelSpecificEngine ?? $defaultEngine;
$searchSettings      = $app->get($engine . '_search_settings') ?? [];     // e.g. typesense_search_settings
```

**Precedence (highest first):**
1. **Model-specific** app setting — `<table>_search_engine` (e.g. `products_search_engine`).
2. **App default** app setting — `search_engine`.
3. **Global** — `config('scout.driver')` (env `SCOUT_DRIVER`).

> Use the **model-specific** setting (`products_search_engine = typesense`) to put
> *only products* on Typesense without sending Leads / Messages / Users there too.

The engine is resolved from `$model->app` (falling back to `app(Apps::class)`),
so indexing/searching must run in the correct app context.

⚠️ Any tool that needs to know "is this tenant on Typesense?" must mirror this
exact precedence. See `TypesenseProductRecommendationTool::typesenseConfigured()`.

---

## 3. Credentials (per-tenant or global)

Credentials come from the `<engine>_search_settings` **app setting** (per-tenant),
falling back to `config/scout.php` (env, global).

### Typesense
Per-tenant app setting `typesense_search_settings`:
```json
{
  "typesense_api_key": "xxxxxxxx",
  "typesense_nodes": [
    { "host": "xxxx-1.a1.typesense.net", "port": 443, "path": "/", "protocol": "https" }
  ],
  "typesense_max_items_per_page": 1000,
  "typesense_timeout": 2
}
```
Global env fallback (`config/scout.php` → `typesense`):
```
TYPESENSE_API_KEY=...
TYPESENSE_HOST=...    TYPESENSE_PORT=443    TYPESENSE_PROTOCOL=https    TYPESENSE_PATH=/
```

### Algolia
`algolia_search_settings` → `algolia_app_id`, `algolia_api_key` (else `scout.algolia.id/secret`).

### Meilisearch
`meilisearch_search_settings` → `meilisearch_host`, `meilisearch_key` (else `scout.meilisearch.*`).

---

## 4. The product index

### Index name — `Products::searchableAs()`
```php
return config('scout.prefix') . ($app->get('app_custom_product_index') ?? 'product_index');
```
- Default index/collection name: `<scout.prefix>product_index`.
- **Per-app isolation:** set `app_custom_product_index` on the app to give that
  tenant its own collection. Without it, apps sharing the same engine share one
  `product_index` collection — fine for Algolia/Meili filtering by `apps_id`, but
  for clean Typesense NL search prefer a per-app custom index.

### What gets indexed — `Products::toSearchableArray()`
Includes (non-exhaustive): `id`, `name`, `description`, `short_description`,
`translations` (multilingual name/description), `categories`, `categories_flat`,
`variants` (via `getVariantsData()`), `status`, `rating`, `weight`,
`is_published`, `apps_id`, `company`, `published_at`.

### What is *eligible* — `Products::shouldBeSearchable()`
```php
// published; AND if the company has index_product_must_have_price → must have a
// default-channel price > 0
```
⚠️ If `company.index_product_must_have_price` is truthy, **unpriced products are
not indexed at all** → they can't be searched/recommended. Turn it off if you
want unpriced products surfaced (e.g. shown as "out of stock" downstream).

---

## 5. Indexing — the tenant-aware command

`kanvas-inventory:scout-product-index-process` (calls `overwriteAppService($app)`
so Bouncer/container app context is correct — never use a bare `scout:import`
loop across apps, it leaks tenant scope).

```
php artisan kanvas-inventory:scout-product-index-process {app_id}
    [--company_id=ID]                 # omit → whole app
    [--action=delete|reindex|unpublished|delete-all]
    [--engine=algolia|typesense|meilisearch]   # temporary override for THIS run only
```

Examples:
```bash
# Reindex an entire app (uses the app's persisted products_search_engine)
php artisan kanvas-inventory:scout-product-index-process 49 --action=reindex

# Reindex one company
php artisan kanvas-inventory:scout-product-index-process 49 --company_id=11569 --action=reindex

# One-off index to Typesense WITHOUT changing the live setting (good for testing)
php artisan kanvas-inventory:scout-product-index-process 49 --action=reindex --engine=typesense
```

Notes:
- `--engine` **temporarily** sets `products_search_engine` for the run and
  restores it after (also on SIGINT/SIGTERM). The **live agent** reads the
  *persisted* setting, so for production you must `$app->set('products_search_engine','typesense')`
  permanently, then reindex.
- `reindex()` calls `$product->searchable()` / `unsearchable()` with a
  **`sleep(1)` per product** — a full app reindex is ~1s/product. Run in
  background/screen for large catalogs.
- Re-run after **any** schema/field change (e.g. adding filterable `price` /
  `in_stock` fields), or the new fields won't populate.

---

## 6. Typesense Natural Language (NL) Search

Typesense Cloud → **Natural Language Models** lets an LLM (e.g. Gemini) translate
a free-form sentence into structured `filter_by` / `sort_by` against the
collection — perfect for conversational queries like
*"un regalo para mi hermano mayor que le gustan las cosas caras"*.

### a) Register the NL model (Typesense Cloud UI or API `POST /nl_search_models`)
```json
{
  "id": "gemini-model",
  "model_name": "google/gemini-2.5-flash",
  "api_key": "YOUR_GOOGLE_AI_STUDIO_API_KEY",
  "max_bytes": 16000,
  "temperature": 0.0,
  "system_prompt": "Map 'caro/expensive/lujo' → sort price desc; 'barato' → price asc; prefer in_stock."
}
```

### b) Query with NL enabled (Scout `->options()`)
```php
Products::search($rawSentence)->options([
    'query_by'    => 'name,description,categories_flat',
    'nl_query'    => true,
    'nl_model_id' => 'gemini-model',
])->keys();
```
Typesense returns `parsed_nl_query.generated_params` with the `filter_by` /
`sort_by` it built. `q` and `query_by` are still required.

### c) Schema requirement — the LLM can only filter/sort on declared fields
For NL to honor budget / availability, the collection must define
**filterable / sortable** scalar fields:
- `price` (float) — filter + sort  ← needed for "caras" / "menos de 50"
- `in_stock` (bool) — filter
- `rating` (float) — sort
- `category` (string[]) — filter

These must be added to `toSearchableArray()` **and** the collection schema, then
reindexed.

> Open item: this repo has no `typesense.model-settings.*.collection-schema` in
> `config/scout.php`, and the model's `typesenseCollectionSchema()` method is not
> referenced anywhere — confirm how the products collection schema is actually
> created in your Typesense environments before relying on field-level config.

### d) Optional: semantic / hybrid (vector) search
Independent of NL search. Add an auto-embedding field:
```json
{ "name": "embedding", "type": "float[]",
  "embed": { "from": ["name","description","short_description"],
             "model_config": { "model_name": "ts/multilingual-e5-small" } } }
```
Query hybrid (`alpha` = vector weight):
```json
{ "q": "...", "query_by": "name,description,embedding",
  "vector_query": "embedding:([], alpha:0.7)" }
```
Requires collection recreation + reindex (heavier than NL). Use a multilingual
model for ES/EN.

---

## 7. The recommendation tools

Two **separate, non-mixed** tools, both returning the **identical** JSON shape
(`{ product, variants[] }` with the same product/variant/channel keys) so the
agent's structured-output schema (and the frontend) work with either:

| Tool | Matching strategy | When |
|---|---|---|
| `ProductRecommendationLookupTool` | SQL term-filter, or Algolia/Scout when an engine is configured (hybrid), with in-PHP scoring | Default; works with `SCOUT_DRIVER=null` |
| `TypesenseProductRecommendationTool` | Typesense **NL search** — sends the customer's verbatim sentence, the cluster's LLM builds filters/sorts | Tenant on Typesense with an NL model |

Both:
- **Re-hydrate matched IDs from the DB**, re-scoped to the tenant (`fromApp` +
  `fromCompany`) — search only generates candidates; the DB is the source of
  truth and the tenant-safety boundary. A mis-scoped engine cannot leak rows.
- Resolve **stock from `getTotalQuantity()`** (warehouse total) and price from the
  default channel, flagging `channel.is_available = (price > 0 && quantity > 0)`.
- Keep out-of-stock / unpriced products in the result, flagged unavailable.

The Typesense tool reads `typesense_nl_model_id` (app setting, default
`gemini-model`) and is a no-op (clear message) when the tenant isn't on Typesense.

---

## 8. Switch an app to Typesense — checklist

1. `$app->set('products_search_engine', 'typesense')` (persisted; agent + indexer).
2. `$app->set('typesense_search_settings', { api_key, nodes })` — or global env.
3. (Optional, recommended) `$app->set('app_custom_product_index', '<per-app name>')`.
4. Create the Typesense **NL model** in Cloud; set `typesense_nl_model_id` if its id ≠ `gemini-model`.
5. Ensure the products collection schema has filterable/sortable `price`/`in_stock`/`rating`.
6. Confirm `company.index_product_must_have_price` is off if you want unpriced products indexed.
7. Reindex: `kanvas-inventory:scout-product-index-process <app_id> --action=reindex`.
8. Wire `TypesenseProductRecommendationTool` into the agent's `agentTools()` and
   update the agent prompt to pass the customer's **verbatim** sentence as `query`
   (do NOT pre-extract gender/price — the NL model does it).
9. Verify: collection doc count in Cloud, then run a Spanish NL query end-to-end.

---

## 9. Gotchas

- **`SCOUT_DRIVER=null`** in an env makes search a no-op unless a tenant sets an
  engine — expect SQL fallbacks to run there.
- **Precedence**: model-specific (`products_search_engine`) beats `search_engine`.
  A tool that checks them in the wrong order will disagree with how `::search()`
  actually routes.
- **`overwriteAppService($app)`** is mandatory when iterating apps in a command;
  the index command already does it.
- **`sleep(1)` per product** in `reindex()` — slow for large catalogs.
- **`shouldBeSearchable()`** can silently exclude unpriced products
  (`index_product_must_have_price`).
- **Index freshness**: search engines are eventually consistent — always hydrate
  stock/price from the DB for display, never trust the indexed copy for truth.
- **NL search cost/latency**: every NL query makes an LLM (Gemini) call (~hundreds
  of ms + token cost). Don't also pre-parse intent in the agent — pick one.
</content>
</invoke>
