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
| 1 | `kanvas-inventory:backfill-product-enrichment {app} [--company_id=] [--limit=] [--sync] [--force]` | LLM-writes the `search_blurb` + facets each product is found by.  Start with `--limit=5 --sync` — it prints each blurb so you can judge quality before paying for the catalog. |
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

## Index schema — prepare, reindex, verify

Adding a discovery field (`price_min`/`price_max`, `discovery_schema_version`, `num_<key>_*`,
`audience`) takes three steps in this order, once per app. Reindexing alone is not enough: a Typesense
collection never gains a field after creation, and an Algolia index needs the blurb searchable and the
facets declared.

| # | command | what it does |
|---|---|---|
| 1 | `kanvas-inventory:prepare-discovery-index {app} [--dry-run] [--from-default]` | Declares the fields on whichever engine the app runs (Algolia settings or a Typesense collection PATCH), prints the diff, asks before writing (skips the question under `--no-interaction`). **Append-only**: it never removes or reorders an entry in a storefront's hand-tuned Algolia settings. If Algolia's `searchableAttributes` is empty (which means "search every attribute") it refuses to append and prints a proposed explicit list; write that list only after reviewing it, with `--from-default`. Exits non-zero on a refusal. No-op on the SQL fallback. |
| 2 | `kanvas-inventory:scout-product-index-process {app} --action=reindex` | Writes the new values into every record. |
| 3 | `kanvas-inventory:verify-discovery-index {app}` | Counts, scoped to this app, the records carrying `discovery_schema_version >= Products::DISCOVERY_SCHEMA_VERSION` against all of the app's records, prints the older-version ids (max 50) and the published products in the DB. At 100% (and a non-empty index) it sets `product_discovery_range_filters` to the verified version, which is what turns range filters on; otherwise it leaves the setting and exits non-zero. Records missing the field entirely appear only in the count gap, not the id list. |

Re-run 1-3 whenever `Products::DISCOVERY_SCHEMA_VERSION` is bumped: the switch turns itself off until the
app is verified again. `SCOUT_QUEUE=true` records still waiting in the queue keep their old version, so
the verify count stays under 100% until the `scout` worker has written them.

### Turning discovery on

There is no enable command: the switches are plain company/app settings (table in
[`Recommendations/CLAUDE.md`](../../../../src/Domains/Inventory/Recommendations/CLAUDE.md#settings)), set from
the settings UI. Setting `product_enrichment_enabled` only covers products written from then on; run
`backfill-product-enrichment {app} --company_id=` per company for the existing catalog. The full
first-run checklist (queued steps and their workers, `--force` after a prompt change, how to check the
result) is in [`Recommendations/CLAUDE.md`](../../../../src/Domains/Inventory/Recommendations/CLAUDE.md#turning-it-on-per-app).

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
