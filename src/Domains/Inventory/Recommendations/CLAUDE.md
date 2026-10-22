# Product Recommendations — discovery and enrichment

Loads when work touches `src/Domains/Inventory/Recommendations/`. The canonical guide to how product
discovery and enrichment are used and how they run. Read with:

- [`Enrichment/CLAUDE.md`](Enrichment/CLAUDE.md) — the write-time half in depth (prompt, model choice, fingerprint).
- [`../CLAUDE.md`](../CLAUDE.md) §2–6 and §8 — engine resolution, credentials, the index, Typesense specifics
  (audience lexicon, embeddings, tuning).

## Two halves

| | When | Cost | Writes / reads |
|---|---|---|---|
| **Enrichment** | product created / edited / duplicated / imported | one LLM call per *changed* product | writes `search_blurb`, facets, tags; reindexes |
| **Discovery** | every search (storefront or agent) | no LLM call; no DB read on Typesense/Algolia | reads the engine; returns products with their qualifying variants |

The blurb is how meaning reaches search: the shopper's words are matched against text written in a
shopper's register, so "algo elegante para mamá" finds a perfume whose name contains neither word.

## How it is used

### Storefront — `discoverProducts`, not `@search`

`@search` stays the plain keyword box and browse. `discoverProducts` is for a sentence or a filter UI:

```graphql
query ($input: ProductDiscoveryInput!) {
  discoverProducts(input: $input) {
    request_id
    recommendations {
      product { id name slug files { url } categories { name } }
      variants { id name sku channel { price discounted_price is_available quantity } }
    }
  }
}
```

- Only `query` is required; the budget is parsed out of it. A filter UI adds `search_query` (catalog
  terms), `preferences`, `min_price`/`max_price`, `category`, `audience`, `numeric_filters`
  (`[{field, min, max, unit}]`), `alternative_queries`.
- Logged-in user: `company_id` optional. App key (anonymous storefront): `company_id` **and** `request_id`
  required. `request_id` is one UUID per search, sent back with clicks/purchases for attribution.
- Under hard limits only the qualifying variants come back. Unavailable products stay, flagged.
- An invalid hard filter is a GraphQL validation error naming the allowed values.

### Agents — the "Product Recommendation Lookup" tool

- **Laravel**: `AgentInventoryRecommendation` has it built in (structured output + tools ⇒ OpenAI or
  Anthropic only — Gemini rejects that combination).
- **Neuron** (sales, shopping, receptionist, internal): grant **Product Recommendation Lookup** (category
  `inventory`) in the agent's Tools tab after `php artisan kanvas:nervous-system:sync-tools`.
- **Contract** (both runtimes, via `DiscoveryInputParser`): ONE call; `request` = the verbatim words,
  plus what the agent understood — `search_query`, `preferences`, price, `category`, `audience`,
  `numeric_filters`, up to 2 `alternative_queries`. "Around 60, 70, 80, under 100k miles" →
  `{field: mileage, max: 100000, unit: mi}`. An invalid filter returns
  `{"error": "invalid_filter", "field", "reason", "allowed"}` for the agent to fix and retry.
- **Neuron output = the `inventory_search` shape** (`id, name, slug, url, is_published, is_available,
  total_stock, variants[{id, name, sku, stock, price, discounted_price, is_available}]`), so
  `variants[].id` goes straight into `variant_detail` and order/cart actions. Nothing found →
  `status: no_matches` (shared `RespondsWithNoInventoryMatches`, so a tenant's RAG handoff rule fires)
  plus a `relax` hint.
- Safe on a customer-facing agent: read-only, tenant-scoped, no files.

## Turning it on (per app)

Nothing changes on deploy: every new behaviour is opt-in and "unset" keeps what runs today. The
switches are plain settings from the [table below](#settings), set from the settings UI — there is no
enable command or mutation.

1. **Company setting** `product_enrichment_enabled = true` — or the app setting, to cover every company.
   Discovery itself needs nothing: `product_discovery_enabled` unset already means on.
2. Optional app settings: `product_discovery_numeric_attributes`, `product_discovery_query_languages`,
   `product_enrichment_llm_config_id`.
3. The commands — the flag only enriches products written from now on; the backfill covers the existing
   catalog, per company (an app-wide backfill would spend on companies that opted out):

```bash
php artisan kanvas-inventory:prepare-discovery-index {app} --dry-run   # then without --dry-run
php artisan kanvas-inventory:backfill-product-enrichment {app} --company_id={id} --limit=20 --sync   # sample quality + cost
php artisan kanvas-inventory:backfill-product-enrichment {app} --company_id={id}
php artisan kanvas-inventory:scout-product-index-process {app} --company_id={id} --action=reindex
php artisan kanvas-inventory:verify-discovery-index {app}              # 100% coverage ⇒ range filters on
```

What trips a first run:

- **Two steps are queued.** The backfill without `--sync` needs the `product-enrichment` worker; the
  reindex with `SCOUT_QUEUE=true` needs the `scout` worker, or the DB looks done and the index stays old.
- **Reindex is ~1s per product** (`sleep(1)` in the command). Scope it with `--company_id`.
- **`verify` counts the whole app.** Reindexing one company leaves it under 100%, which only keeps
  engine-side range filters off; search works and every limit is still re-checked on the results.
- **The backfill skips products whose fingerprint is current**, which includes blurbs written by an
  older prompt. After changing the prompt, or to clear blurbs written before the placeholder rule, run it
  with `--force`.
- **Check the result** on one record in the Algolia/Typesense dashboard: `search_blurb`, `audience`,
  `price_min`/`price_max`, `discovery_schema_version`. A placeholder product shows an empty blurb. Then
  query `discoverProducts` with a sentence that shares no word with any product name — only the blurb
  can match it, so that is the real test. `--sync` prints every blurb plus token cost
  (~$0.04 per 1,000 products on Flash-Lite).

- `prepare-discovery-index` — Algolia: appends `search_blurb` LAST to `searchableAttributes` and
  `filterOnly(audience|in_stock)` to `attributesForFaceting`, never removes or reorders; refuses an empty
  `searchableAttributes` (empty = "search every field") and proposes a list for `--from-default`.
  Typesense: PATCHes the missing discovery fields into the collection (Scout never migrates a schema).
- `verify-discovery-index` counts records carrying `Products::DISCOVERY_SCHEMA_VERSION`, scoped to the
  app on both counts (indexes are shared). Queued, not-yet-written records keep the old version, so it
  can't pass early. At 100% it stores the verified version in `product_discovery_range_filters`.
- `check_product_discovery_setup` (agent tool) reports every prerequisite with its fix, including each
  flag's value and source (`company` / `app` / `default`).

## How a search runs

```
caller ─▶ DiscoveryInputParser ─▶ ProductIntent (sentence parsed + explicit overrides)
        ─▶ RecommendProductsAction::executeIntent()
             ├ discovery flag explicitly off ─▶ SQL adapter
             ├ DiscoveryQueryBuilder ─▶ DiscoveryQuery  (the ONLY place business rules live)
             ├ adapter translates: Typesense `filter_by` + vectors │ Algolia `filters` │ SQL `LIKE` + exact SKU
             ├ cache: key = full normalized query + engine + index + settings
             │        ids live the configured TTL; documents ≤ 5 min (they carry price/stock)
             ├ ServesSearchDocuments (Typesense, Algolia) ─▶ presenter->document()   NO database read
             │   SQL fallback / engine failure            ─▶ presenter->loadProducts()   ~14 batched queries
             ├ HardConstraintVerifier::filter() — keeps only variants meeting ALL limits together
             └ excluded categories ─▶ demote unavailable ─▶ diversify ─▶ impression logged
```

## How enrichment runs

`Create/Update/DuplicateProductAction` call `EnrichProductJob::dispatchIfEnabled()` after their
transaction, regardless of `runWorkflow` (imports). It queues only when the company's flag is
explicitly on **and** the content fingerprint changed; the job is unique per product for 5 minutes.
`EnrichProductAction` makes one structured LLM call and writes facets, tags and the blurb, then
reindexes. There is no workflow step — see [`Enrichment/CLAUDE.md`](Enrichment/CLAUDE.md).

## Settings

| Setting | Scope | Default | Effect |
|---|---|---|---|
| `product_enrichment_enabled` | company → app | unset (off) | automatic enrichment on product writes; the backfill command runs regardless |
| `product_discovery_enabled` | company → app | unset (on) | explicit `false` ⇒ SQL keyword fallback only |
| `product_enrichment_llm_config_id` | company → app | — | any tenant LLM config (DeepSeek, Mistral, OpenAI-compatible…) for enrichment, no Agent row needed |
| `product_enrichment_model` | app | `gemini-2.5-flash-lite` | Gemini model when nothing above is set |
| `product_discovery_numeric_attributes` | app | — | `{"mileage": {"attribute": "Mileage", "unit": "mi"}}`; unit is canonical, inputs are converted |
| `product_discovery_query_languages` | app | `["es","en"]` | Algolia stop words / plurals, and the languages the blurb is written in (one sentence pair per language, joined into `search_blurb`); changing it re-enriches on the next backfill |
| `product_discovery_range_filters` | app | — | set by `verify-discovery-index`; never by hand |
| `product_discovery_cache_ttl` | app | 1800 | id cache; documents are capped at 300 |
| `souk_allow_cross_company_variants` | app | — | read only through `ProductDiscoverySettings::crossCompanyVariants()` |

Typesense-only tuning (vector alpha, group caps, excluded categories, intent lexicon, price bands) is in
[`../CLAUDE.md`](../CLAUDE.md) §8.

## Index fields this domain relies on

Written by `Products::toSearchableArray()` for every engine; land on reindex.

| Field | Level | Used for |
|---|---|---|
| `search_blurb`, `audience` | product | matching; recipient filter (`unknown` when not enriched) |
| `price`, `price_min`, `price_max` | product | storefront sort; price range overlap (0 = unknown) |
| `in_stock`, `companies_id` | product | stock filter; tenant filter and per-document tenant check |
| `num_<key>_min` / `_max` | product | numeric limits, canonical unit, absent when unknown |
| `discovery_schema_version` | product | migration coverage |
| `files[].id`, `channels[].discounted_price`, `quantity` | product + variant | rendering from the document |

## Where things live

| Concern | File |
|---|---|
| Entry point | `Actions/RecommendProductsAction.php` |
| Structured input → intent | `Discovery/DiscoveryInputParser.php`, `DataTransferObject/ProductIntent.php` |
| Business rules → engine-neutral query | `Discovery/DiscoveryQueryBuilder.php`, `Discovery/DiscoveryQuery.php`, `Discovery/Constraints/*` |
| Adapters | `Services/{Typesense,Algolia,Sql}ProductDiscoveryService.php`, chosen by `ProductDiscoveryResolver` |
| Rendering (document + database) | `Services/ProductRecommendationPresenterService.php` |
| Hard-limit check | `Discovery/HardConstraintVerifier.php` |
| Rank merge, pool size | `Discovery/RankFusion.php` |
| Numeric attributes, units | `Discovery/NumericAttributes.php`, `Baka\Support\Number` |
| Flags | `Services/ProductDiscoverySettings.php` |
| Index prepare / verify | `Contracts/DiscoveryIndexAdapter.php`, `Services/{Algolia,Typesense}DiscoveryIndexService.php` |
| Enrichment | `Enrichment/**` |
| Agent tools | `Intelligence/Agents/{Laravel,Neuron}/Tools/Inventory/ProductRecommendationLookupTool.php` |

## Rules

- **Business rules live only in `DiscoveryQueryBuilder`.** Tenant scope, the unknown-price policy,
  admitted audiences and which index field a bound lands on. An adapter that decides anything drifts
  from the others; adapters translate.
- **Capabilities, never engine checks.** `supportsVectors()`, `canFilterOn()`, `ServesSearchDocuments`.
  A new engine is one adapter plus one index adapter.
- **No database on the document path.** Search traffic must not land on MySQL. Anything a response needs
  has to be in the index (add it to `toSearchableArray()` and `DOCUMENT_FIELDS`), not read back.
- **Every hard limit is re-checked on the rendered result**, whichever path produced it. The engine's
  product-level ranges can pair a cheap variant with a low-mileage one that are different cars.
- **Identity terms are never relaxed** on the agent/filter path (`removeWordsIfNoResults: none`); only
  `preferences` are optional, and they must be in the query text for `optionalWords` to act.
- **Invalid hard filters are rejected, never dropped** — a dropped `odometer` silently removes the limit.
- **"Unset" keeps today's behaviour.** Discovery and legacy paths stop only on explicit `false`; new LLM
  spend starts only on explicit `true`.
- **Bump `Products::DISCOVERY_SCHEMA_VERSION`** when a discovery field is added or changes meaning: range
  filters switch off per app until that app is reindexed and re-verified.
- **Tenant setting reads go through `ProductDiscoverySettings`**, not `$app->get()` at the call site.

## Known gaps

- **Stock freshness on the document path** is the index's. Product, variant and channel-price writes
  reindex; a stock-only change may not.
- **Raw storefront sentences on Algolia without an agent** ("nice tv") can return nothing: filler words
  are required. Planned: filler words as stop words, plus a relaxed retry on the raw path only.
- **Not live-tested** against a real Algolia/Typesense index. First pilot: run the prepare → reindex →
  verify sequence, check the Algolia `NOT discovery_schema_version >= V` stale-id query, and measure
  real token cost with `--limit=20 --sync`.
