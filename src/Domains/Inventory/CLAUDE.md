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

## 7. Product discovery

One pipeline serves both the storefront and the agent, so they cannot drift:

```
discoverProducts (GraphQL, @guard)  ─┐
ProductRecommendationLookupTool     ─┴─▶ RecommendProductsAction
                                            ├ ProductIntent      — budget out of the sentence
                                            ├ ProductDiscoveryResolver
                                            │    ├ TypesenseProductDiscoveryService  (multi_search + RRF)
                                            │    └ SqlProductDiscoveryService        (keyword fallback)
                                            ├ cache (non-empty results only)
                                            └ DB hydrate, tenant-pinned  ← the security boundary
```

Key points:
- **The engine is resolved per tenant** (`ProductDiscoveryResolver`, same precedence as
  `SearchEngineResolver`). There is no longer a separate tool per backend.
- **Search only nominates ids.** Hydration re-reads them with an explicit
  `where('companies_id', ...)` — deliberately NOT `fromCompany()`, which widens to
  `companies_id > 0` under an AppKey binding.
- **The vector half is opt-in via `query_by`.** Naming `embedding` when the collection does not
  declare it makes Typesense reject the *whole* search, so it is only added when
  `typesense_product_query_by` (or `config('inventory-discovery.typesense_query_by')`) includes it.
  Until then discovery runs lexically over `search_blurb`.
- **Out-of-stock / unpriced products stay in the result**, flagged `channel.is_available = false`.
- **Every response is logged** (`product_recommendation_impressions`) with its ordered ids and a
  `recommendation_uuid` for outcome attribution — including no-hit queries, which are the best
  signal for catalog gaps.
- `search_blurb` comes from the ProductEnrichment connector; the field name is owned by
  `Kanvas\Inventory\Recommendations\Enums\SearchFieldEnum` so the core model does not depend on
  a connector.

Scoring a change: `kanvas-inventory:evaluate-product-discovery {app} {company} --file=<golden-set>`
reports recall@k and MRR, and exits non-zero under `--min-recall`.

---

## 8. Turning discovery on for an app (production)

Everything is per-app settings — no deploy, no code. All of these are editable from the app
settings UI; the `$app->set()` form is what the UI writes.

### Required

| Setting | Value | Why |
|---|---|---|
| `products_search_engine` | `typesense` | Routes indexing AND discovery to Typesense for products only, leaving Leads/Users/Messages on whatever the app already used |
| `typesense_search_settings` | `{"typesense_api_key": "…", "typesense_nodes": [{"host":"…","port":443,"path":"/","protocol":"https"}]}` | Per-tenant credentials; falls back to `config/scout.php` (env) when absent |
| `app_custom_product_index` | e.g. `acme_product_index` | **Strongly recommended.** Without it every app on the same cluster shares one `product_index` collection |

### Enrichment (required for semantic quality)

Discovery matches a shopper's sentence against `search_blurb`. With no blurb it degrades to keyword
matching on the product name — see §7.

1. Ensure the global **"Product Enrichment"** agent type exists: `php artisan kanvas:intelligence:sync-agent-types`
2. Create an **Agent** of that type for the app (UI: Agents → New → type "Product Enrichment").
   Set its LLM provider on the agent. **Gemini cannot be used for any structured-output agent that
   also has tools** — it rejects `response_mime_type: application/json` combined with function
   calling. Enrichment itself is tool-free so Gemini works there; the Inventory Recommendation agent
   is not, and needs OpenAI/Anthropic.
3. Optional per-app tuning on the agent record:
   - `instructions` — **overrides the in-code prompt entirely** (`instructionsFromRecord`). Leave it
     EMPTY to keep the shipped prompt. A generic instruction here is the single most common reason an
     agent "ignores" its design.
   - `config.enrichment.facets` — the controlled vocabulary
4. Backfill: `php artisan kanvas-inventory:backfill-product-enrichment {app} [--company_id=] [--sync]`

### Recipient filtering (`audience`)

Enrichment tags every product with `audience` from a closed vocabulary — `male, female, unisex,
kids, baby, teen, senior` — and `Products::toSearchableArray()` copies it to a flat, facetable
`audience` field so `filter_by` can act on it. A product with no enrichment indexes as `unknown`
rather than empty, because Typesense has no dependable "this array is empty" test.

`ProductIntent` reads the recipient out of the sentence through the same lexicon that handles
budget phrases, and the filter admits `[<recipient>, unisex, unknown]` — neutral products ride
along, or a query for a man loses most of a catalog.

**The shipped lexicon is English only.** A Spanish storefront gets nothing from this until it adds
its own terms to `product_intent_lexicon` (merged, not replacing). Cover friends and coworkers, not
just family — "para un amigo" is one of the most common gift queries there is:

```json
{
  "audience_female": ["mujer", "novia", "esposa", "mama", "madre", "hermana", "hija", "tia", "prima", "amiga", "jefa", "companera", "para ella"],
  "audience_male":   ["hombre", "novio", "esposo", "papa", "padre", "hermano", "hijo", "tio", "primo", "amigo", "jefe", "companero", "para el"],
  "audience_senior": ["abuela", "abuelo", "suegra", "suegro"],
  "audience_kids":   ["nino", "nina", "chico", "chica"]
}
```

Accents are folded before matching, so `mama` covers `mamá`. Longest match wins, so `grandmother`
is not read as `mother`.

⚠️ **Reindexing does NOT add the field to an existing collection.** Scout's
`getOrCreateCollectionFromModel()` returns early when the collection exists and never applies a
changed schema, so a collection built before `audience` existed will never gain it — the reindex
pushes a value Typesense ignores. Patch it in place (no drop, no re-embedding):

```bash
curl -X PATCH -H "X-TYPESENSE-API-KEY: $KEY" -H 'Content-Type: application/json' \
  "$HOST/collections/<prefix><index>" \
  -d '{"fields":[{"name":"audience","type":"string[]","optional":true,"facet":true}]}'
```

Discovery checks whether the collection declares the field (cached 10 min) and skips the clause when
it does not, so a missing field costs the feature rather than every result. `check_product_discovery_setup`
reports it with this command as the fix.

### Embeddings (required for cross-language and paraphrase matching)

Without an embedding field the search is lexical only — "a luxury SUV" will not match a Spanish
catalog. Two mutually exclusive ways to get one, both read at collection-creation time:

| Setting | Effect |
|---|---|
| `OPEN_AI_EMBEDDING_KEY` | `openai/text-embedding-3-small`, billed per call |
| `product_discovery_embedding_model` | A Typesense built-in, e.g. `ts/multilingual-e5-small` — runs inside the cluster, **no API key**, multilingual |

Then add `embedding` to the query fields:

| Setting | Value |
|---|---|
| `typesense_product_query_by` | `search_blurb,name,description,embedding` |

⚠️ **Order matters.** The embed field is only added when the collection is CREATED. Turning
embeddings on for an app that already has a collection requires deleting the collection and
reindexing — and naming `embedding` in `query_by` when the collection lacks it makes Typesense
reject **every** search with `Field \`embedding\` does not have a vector query index`.

### Optional tuning

| Setting | Default | Effect |
|---|---|---|
| `product_discovery_vector_alpha` | `0.75` | Vector vs keyword weight in the hybrid search |
| `product_discovery_max_results_per_group` | `2` | Max results sharing a group key — stops one model in five colours taking the page |
| `product_discovery_group_by_tokens` | `2` | How many leading name tokens form that group key. The whole name is too fine: "Perfume Premium 31/37/38" are three names and one product. `0` groups on the whole name |
| `product_discovery_unavailable_penalty` | `3` | Places an unpriced / out-of-stock product drops. A penalty, not a partition — sorting all buyable products first lets a weak match leapfrog a strong one. `0` disables; a value past the page size is a hard partition |
| `product_discovery_excluded_categories` | `[]` | Category names dropped from every result, however well they match. Gift wrap is the canonical case: on a gift catalog "Envoltura" scores highly on every gift query and is never the gift. Matched case- and accent-insensitively |
| `product_semantic_profile_strategy` | `generic` | Who the blurbs are written for — `gift` (buying for someone else, describe the recipient) or `generic` (buying for themselves, describe the need). Changing it invalidates every existing blurb; see below |
| `product_discovery_cache_ttl` | `1800` | Seconds a non-empty candidate list is cached |
| `product_intent_lexicon` | — | Tenant-language budget AND recipient phrases, MERGED over the shipped English (§`config/inventory-discovery.php`). **A non-English storefront must set the `audience_*` buckets or the recipient filter never fires** |
| `product_discovery_premium_min_price` / `_cheap_max_price` | config | Price band for vague signals ("de lujo", "barato") |

### Order of operations

```
1. set products_search_engine + typesense_search_settings + app_custom_product_index
2. set the embedding model (if wanted)  ← BEFORE first index
   set product_semantic_profile_strategy ← BEFORE enrichment
3. create + configure the enrichment Agent
4. backfill enrichment                   ← writes search_blurb
5. reindex                               ← creates the collection, builds vectors
6. set typesense_product_query_by to include `embedding`
7. verify: discoverProducts returns results; check impressions are landing
```

Reindex: `php artisan kanvas-inventory:scout-product-index-process {app} --action=reindex`

### Queue workers this depends on

`scout` (indexing), `product-enrichment` (blurbs), `product-discovery` (impression logging). All
three are in the compose files. **`SCOUT_QUEUE=true` with no `scout` worker silently queues every
index operation forever** — the catalog looks indexed in the DB and is empty in Typesense.

### Verifying

```bash
# collection exists, has docs, has an embedding field
curl -H "X-TYPESENSE-API-KEY: $KEY" "$HOST/collections/<prefix><index>" | jq '{num_documents, fields: [.fields[].name]}'

# blurb coverage
SELECT COUNT(*) FROM apps_custom_fields WHERE name='search_blurb' AND value <> '' AND companies_id = ?;

# discovery is being used and what it returns
SELECT query_raw, results_count, engine FROM product_recommendation_impressions ORDER BY id DESC LIMIT 20;

# score a change instead of guessing
php artisan kanvas-inventory:evaluate-product-discovery {app} {company} --file=golden.json
```

A row with `results_count = 0` is the most useful thing in that table: it is either a catalog gap or
a blurb that failed to describe what the shopper asked for.

Drafting the judged set is a command, because pruning a list is much faster than building one:

```bash
php artisan kanvas-inventory:scaffold-golden-set {app} {company} --cases=20 --out=golden.json
# delete the wrong ids, add anything missing, then
php artisan kanvas-inventory:evaluate-product-discovery {app} {company} --file=golden.json
```

Queries come from the impression log, so the set scores what shoppers actually ask. Pass
`--query="..."` to draft from queries you supply instead.

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
- **`SCOUT_QUEUE=true` with no `scout` worker** queues every index operation and never runs it.
  The DB looks fine, the collection stays empty, and the backlog eventually pressures Redis. The
  worker exists in all three compose files — make sure it is actually up.
- **An agent's `instructions` field OVERRIDES the in-code prompt.** A record saved with something
  generic ("answer directly, ask clarifying questions") replaces the whole designed pipeline, and
  the agent behaves like a plain chatbot. Leave it empty to keep the shipped prompt.
- **Gemini rejects structured output + tools** (`response_mime_type: application/json` with
  function calling). Any `HasStructuredOutput` agent that also declares tools needs OpenAI or
  Anthropic. `KanvasLaravelAgent` therefore does NOT inject `CurrentTimeTool` into a tool-free
  structured agent.
- **The embed field is fixed at collection-creation time.** Enabling embeddings later means
  dropping and recreating the collection; until then, `embedding` in `query_by` breaks every search.
- **Typesense needs a minute after restart** when a local embedding model is configured — it loads
  the ONNX model from disk and answers `Not Ready or Lagging` until it finishes.
- **A required nested field breaks indexing** when it arrives empty. `categories` / `variants` /
  `attributes` are `optional` in `typesenseCollectionSchema()` for exactly this reason.
- **`product_semantic_profile_strategy` is part of the enrichment hash.** Flipping an app from
  `generic` to `gift` reopens the gate on every product, so a plain re-run of the backfill rewrites
  the blurbs — no clearing `search_enrichment_hash` by hand. Setting it *after* a catalog is already
  enriched costs a full re-enrichment, so set it before step 4.
- **An embedding cannot do negation.** "regalo para hombre" and a blurb reading "para mujeres" are
  SIMILAR to a vector — both are about gifting to a person — not opposite. That is why a Victoria's
  Secret gift card ranked on a man's query even though its blurb said who it was for. Recipient is
  enforced as a `filter_by` on the flat `audience` field, never left to ranking. Same reasoning
  applies to any other axis where being approximately right is actually being wrong.
- **Do NOT denylist a product that is wrong only in context.** `product_discovery_excluded_categories`
  is for things that are never the answer — wrap, shipping, warranties. A gift card IS a gift; it was
  wrong for a *man*, not wrong in general, and excluding it would also break "regalo para mi novia".
  Contextual wrongness is a filter problem, not a denylist problem.
- **Formulaic blurb openings poison the keyword half.** Nearly every blurb opened "Diseñado
  para…", so a shopper asking for *diseño* got gaming mice and hair supplements — the stem matched
  the opening, not the meaning. The prompt now bans that opening and asks for varied ones. When one
  phrase appears in every blurb it stops carrying meaning and starts adding noise to every query.
- **A blurb the model invented is worse than no blurb.** Seed rows like "Perfume Premium 38" carry
  no description and no attributes, so the enrichment agent used to invent an audience — "para
  quienes buscan una fragancia sofisticada, ocasiones especiales" — which matches every gift query
  equally and floods the page. The prompt now tells it to state only what the name says when there
  is nothing to differentiate on. Watch for that phrasing in `--explain` output; it is the tell.
- **Gift wrap outranks the gift.** A "regalo para mi suegra" query matches a gift-wrap blurb almost
  perfectly, because the blurb genuinely is about giving. Nothing in the ranking can tell the
  wrapping from the present — put those categories in `product_discovery_excluded_categories`.
</content>
</invoke>
