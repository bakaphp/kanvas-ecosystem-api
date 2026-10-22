# Product Enrichment

Loads when work touches `src/Domains/Inventory/Recommendations/Enrichment/`. Also read
[`../CLAUDE.md`](../CLAUDE.md) (how discovery uses what this writes, activation, settings) and
`src/Domains/Inventory/CLAUDE.md` (search engine, index).

**Why it lives here and not in Connectors** (moved 2026-10-20): it calls no outside service of its own
and it writes the `search_blurb` discovery reads, and Inventory's product actions trigger it — a
connector must not own data the platform reads, and core must not depend on a connector. The class
names stored in `agent_types.handler` and workflow `actions.model_name` were repointed by
`2026_10_20_200000_repoint_*` (Intelligence + Workflow); the old row is kept, not duplicated.

## What it does

LLM-enriches each product into a **searchable "recommendation profile"** so vague/conversational
queries ("algo elegante para mi mamá", "un regalo de lujo para mi hermano") match well. It turns a
product (name/description/categories) into structured facets + a semantic blurb, written back onto
the product so `toSearchableArray()` carries them into the search index (Typesense).

This is *multi-vertical*: gift store today, but nothing here is gift-specific. The vertical framing
lives in the per-app prompt, **not** in code (fields are generic: `audience`, `blurb`, …).

## Not a real agent — one structured LLM call

No tools, no memory, no history: one prompt + the product + a JSON schema. `ProductEnrichmentAgent`
extends `KanvasLaravelAgent` only for structured output, per-tenant provider/key resolution and usage.

**Which model runs**, first match wins:
1. An **Agent row** of type "Product Enrichment" (optional, for editing the prompt / vocabulary /
   provider from the agents UI) — its own LLM config or model.
2. The company (then app) setting **`product_enrichment_llm_config_id`**
   (`Recommendations\Enums\ConfigurationEnum::ENRICHMENT_LLM_CONFIG`) naming one of the tenant's LLM
   configs — any provider (DeepSeek, Mistral, OpenAI-compatible, Ollama…), so a cheaper model needs no
   Agent row. Resolved tenant-scoped by `AgentProviderService::tenantConfig()`; another company's config
   is ignored. Check structured-JSON reliability with `--limit=20 --sync` before trusting a new model.
3. The app setting `product_enrichment_model` (`ENRICHMENT_MODEL`) on Gemini with the app's `GEMINI_KEY`.
4. `ProductEnrichmentAgent::DEFAULT_MODEL` (`gemini-2.5-flash-lite`).

The user that creates a missing attribute definition is the Agent row's user, else the company's AI agent
user, else the product's creator — enrichment never requires `ai-agent-user-id`. An Agent row is the override for a custom prompt, vocabulary or
model. An explicit `agent_id` that does not exist still throws. Per-tenant overrides flow from the
`Agent` record (resolved by `ProductEnrichmentAgentService`):

| Agent field | Drives |
|---|---|
| `instructions` | the prompt (domain/tone) — `instructionsFromRecord(default)`, default→override |
| `config.enrichment.facets` | the controlled vocabulary — `EnrichmentConfig::forAgent($agent)`, default→override |
| `model` / `provider` | which LLM |
| `user` | the actor for the attribute/tag writes (app-scoped) |

- The global **AgentType "Product Enrichment"** (`handler = ProductEnrichmentAgent::class`, declared by its
  `#[AgentTypeDefinition]` and synced by `kanvas:intelligence:sync-agent-types`) ships the defaults; an app overrides by editing its
  own `Agent` row. Apps with none → the in-code defaults (`DEFAULT_FACETS`, generic prompt).
- Pick a specific agent for a backfill with `--agent_id` — it is loaded **strictly within
  the app** (`Agent::fromApp($app)->where('id', …)`), never another tenant's.

## Where each enrichment value is stored (NOT all custom fields)

| Enrichment | Storage | Why |
|---|---|---|
| `audience`, `occasion`, `interests` | product **attributes** (plain names) | distinct, groupable facets → `filter_by audience:male && occasion:birthday` |
| `tags` (elegant, romantic, luxury…) | **Tags subsystem** (`HasTagsTrait`) | flat labels — exactly what tags are; one `tags` facet |
| `blurb` | custom field `search_blurb` (`Recommendations\Enums\SearchFieldEnum::BLURB`, which the index reads too) | semantic text → the **embedding source** for vector search. One `blurb_<lang>` per language in `product_discovery_query_languages`, joined into one field so a shopper matches in any of them without per-language query setup |
| `in_stock`, `price` | **derived at index time** in `toSearchableArray()` | stay in sync with price/stock without re-enriching |
| dedup hash | custom field `search_enrichment_hash` | skip the LLM when name/description/categories unchanged |

## Flow

```
Create/Update/DuplicateProductAction ─┐  dispatchIfEnabled(): flag explicitly on + fingerprint changed
backfill-product-enrichment ──────────┴─▶ EnrichProductJob   (unique per product 5 min, afterCommit)
                                                     │
                                                     ▼
                                                EnrichProductAction
                                                  ├ hash gate (skip if unchanged)
                                                  ├ resolve app's Agent (+ optional agent_id)
                                                  ├ promptWithConfig() → StructuredAgentResponse
                                                  ├ facets → addAttributes(actor, …)   (agent's user, else company AI user, else product creator; validated via EnrichmentConfig::clean)
                                                  ├ tags  → removeTags(vocab)+addTags(clean)   (NEVER syncTags — it wipes merchant tags)
                                                  ├ blurb → set(search_blurb)
                                                  └ searchable()  (re-index)
```

## Triggers and the on/off flag

Resolve the flag only through `ProductDiscoverySettings::for($app, $company)` (company setting, then app
setting, then unset); never read `product_enrichment_enabled` raw. Unset keeps what runs today and never
starts new spend:

- **Automatic trigger**: `CreateProductAction`, `UpdateProductAction` and `DuplicateProductAction` call
  `EnrichProductJob::dispatchIfEnabled($product)` after their transaction, regardless of `runWorkflow`
  (imports go through `CreateProductAction` with workflows off). It queues only on an explicit `true`.
  It skips products whose fingerprint is unchanged, and the job is unique per product until it starts
  processing, so repeated touches enrich once.
- **There is no workflow step.** `EnrichProductActivity` was removed 2026-10-20: it missed imports, ran on
  every product save and wrote an `entity_integration_history` row each time. The removal migration
  turned the flag on for every company that had a rule using it. Don't add a workflow trigger back;
  per-tenant conditions belong in the flag (company → app).

## Cost

- **Thinking is a model-dependent knob and `thinkingBudget: 0` cannot be sent.** laravel/ai reaches
  Gemini through the Interactions API, which rejects `thinkingBudget`/`thinking_config` as unknown
  parameters and only takes `generation_config.thinking_level`. Measured: 2.5-flash spends ~1,900
  thought tokens (billed as output) on a 3-token answer; flash-lite spends 0 by default and answers
  400 to `low` (it requires a 512 budget); 2.5 models reject `minimal`; Gemini 3 takes `minimal`.
  `ProductEnrichmentAgent::providerOptions()` encodes exactly that: none for flash-lite, `low` for other
  2.5 models, `minimal` for `gemini-3*`. A new Gemini family needs a live check before it is added.
- **The prompt sends the description stripped of HTML and capped at 1,500 chars**, but the fingerprint
  (`ProductEnrichmentFingerprintService`) hashes the raw one, so changing the cap never re-enriches a catalog.
- **Nothing is queued for a product whose fingerprint is current.** `dispatchIfEnabled()` and the backfill
  check it before dispatching, so price/stock edits, re-saves and backfill re-runs cost no job at all
  (the backfill reports them as skipped; `--force` redoes them). The job checks again, for the race.
- `backfill-product-enrichment --sync` prints tokens and estimated cost per product plus totals
  (`EnrichmentUsageService`, priced from `model_pricing` through `ModelPricingCalculator`; an unknown
  model prints `n/a`). Run `--limit=20 --sync` first on any new tenant.

## Gotchas

- **A record that is not a real product gets no blurb.** The model sets `insufficient_information`
  for placeholders and test data; the action clears `search_blurb` and stores the hash, so the
  product is skipped until its content changes. Writing the model's "please add details" reply as the
  blurb made it match every query containing "product".
- **The language list joins the fingerprint only when it differs from `es,en`**, so catalogs enriched
  before languages were configurable keep a current hash.
- **Never `syncTags()`** — it detaches ALL tags incl. a merchant's own. Reconcile only OUR vocab:
  `removeTags($config->facets['tags'])` then `addTags($config->clean('tags', …))`.
- **Vocab is enforced** — `EnrichmentConfig::clean($facet, $values)` drops anything outside the
  app's vocabulary, so the LLM can't pollute facets/tags.
- **`addAttributes()` forces `is_visible=true`** → enrichment attributes currently show on the PDP.
  `@todo` write them `is_visible=false` (replicate the `CreateAttribute`+`AddAttributeAction` path
  with the flag) so they stay internal.
- **Activity registration is automatic** via `#[WorkflowAction]` (`kanvas:workflow-sync-actions`).
  Do NOT also register it in `KanvasWorkflowSynActionCommand`.
- **`blurb` is generic on purpose** — no `gift_` prefix; gift framing comes from the per-app prompt.

## Still pending (not yet wired)

1. `Products::toSearchableArray()` indexes `search_blurb`, `audience`, `price*` and `in_stock`; the
   `occasion`/`interests` attributes, `tags` slugs and `EnrichmentConfig::priceTier()` are not indexed
   yet. See `src/Domains/Inventory/CLAUDE.md`.
2. `DiscoverVocabularyAction`, to auto-derive an app's vocabulary from its catalog.
