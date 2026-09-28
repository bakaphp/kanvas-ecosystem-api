# Inventory Commands

Loads when work touches `app/Console/Commands/Inventory/`.

Every command here takes an **app id** and most take a **company id**, because
inventory is tenant-scoped. Any command that resolves an `Apps` MUST call
`$this->overwriteAppService($app)` before doing work — see the root `CLAUDE.md`;
skipping it leaks Bouncer/container scope from whatever ran before.

Product **discovery** (natural-language search) has its own pipeline documented
in [`src/Domains/Inventory/CLAUDE.md`](../../../../src/Domains/Inventory/CLAUDE.md) —
read §7 and §8 before touching the four discovery commands below.

---

## Discovery — run in this order

The order is load-bearing. Enrichment writes the text search matches on; indexing
copies it to Typesense. Reindexing an un-enriched catalog builds vectors from
product names alone, and the results look broken rather than empty.

| # | command | what it does |
|---|---|---|
| 1 | `kanvas-inventory:backfill-product-enrichment {app} [--company_id=] [--limit=] [--sync] [--force]` | LLM-writes the `search_blurb` + facets each product is found by. **Lives in `Commands/Connectors/ProductEnrichment/`**, not here. Start with `--limit=5 --sync` — it prints each blurb so you can judge quality before paying for the catalog. |
| 2 | `kanvas-inventory:scout-product-index-process {app} [--company_id=] [--action=reindex]` | Pushes products into the search engine. Creates the collection on first run from `Products::typesenseCollectionSchema()`. ~1s per product (`sleep(1)` in `reindex()`). |
| 3 | `kanvas-inventory:discover-products {app} {company} "query" [--limit=] [--explain] [--fresh]` | **Run one search and see what comes back.** `--explain` prints the blurb each result matched on — the only way to tell a bad search from a bad blurb. `--fresh` bypasses the 30-min query cache. |
| 4 | `kanvas-inventory:scaffold-golden-set {app} {company} [--cases=20] [--limit=10] [--out=] [--query=]` | Drafts the judged set for step 5, pre-filled with what discovery answers today, from the queries shoppers actually ran. **Every id it writes is a guess** — prune it before scoring anything. |
| 5 | `kanvas-inventory:evaluate-product-discovery {app} {company} --file=golden.json [--k=10] [--min-recall=] [--show-misses]` | Scores discovery against a human-judged query set (recall@k, MRR). Exits non-zero under `--min-recall`, so it can gate a change. Without it, prompt tuning is guesswork. |

`kanvas-inventory:benchmark-product-recommendation {app} {agent}` times the
recommendation **agent** (not the search) and reports tokens per turn. Completion
tokens are the number that matters — that is what response time scales with.

**Diagnosing setup:** `check_product_discovery_setup` (a Neuron agent tool, not a
command) reports every prerequisite with a fix. Faster than checking by hand.

---

## Indexing & search maintenance

| command | notes |
|---|---|
| `kanvas-inventory:scout-product-index-process {app} [--company_id=] [--action=delete\|reindex\|unpublished\|delete-all] [--engine=]` | The tenant-aware indexer. `--engine` overrides the engine for THIS run only and restores after; the live agent reads the persisted setting, so production needs `$app->set('products_search_engine', …)`. |
| `kanvas-inventory:scout-clean-legacy-inventory {app} {company_ids*}` | Removes stale index entries for products that should no longer be searchable. |
| `kanvas-inventory:backfill-variant-rating-from-category {app}` | Recomputes `Variant.rating` from product category weights. Rating feeds ranking, so run it before judging search quality. |

⚠️ **`SCOUT_QUEUE=true` means `searchable()` only queues.** Without the `scout`
worker running, the DB looks correct and the collection stays empty. That is the
single most common "indexing is broken" cause.

---

## Cross-environment product movement

Names, not ids — the two sides have different primary keys.

| command | direction |
|---|---|
| `kanvas-inventory:export-products-cross-env {app} {company}` | → JSONL |
| `kanvas-inventory:import-products-cross-env {app} {company}` | JSONL → target, remapping names to local ids |
| `kanvas-inventory:dev-to-prod-inventory-export` | the older dev→prod path |
| `kanvas-inventory:export-products` | CSV export, optionally emailed |

---

## Reporting & comparison

| command | notes |
|---|---|
| `kanvas-inventory:daily-report` | Expiration + low-stock notifications. Scheduled. |
| `kanvas-inventory:compare` | Compares inventory for a company. |
| `kanvas-inventory:shopify-check` | Compares against Shopify. |

---

## Attribute types

| command | notes |
|---|---|
| `kanvas:create-attribute-types` | Creates a set of attribute types, global (`apps_id=0`) or per app. |
| `kanvas:migrate-attribute-type` | Attribute update with rollback. |
| `kanvas-inventory:region-migration` | Moves inventory regions to ecosystem. |

---

## Adding a command here

- `use Baka\Traits\KanvasJobsTrait` + `$this->overwriteAppService($app)` first thing.
- Anything long-running (LLM calls, per-row indexing) should have a `--limit` and
  a `--sync`/dry equivalent, so it can be sampled before it is trusted.
- One bad row must not end the run: catch per item, `report($e)`, count failures,
  and say how many at the end. Half the value of these commands is surviving the
  data that is already wrong.
