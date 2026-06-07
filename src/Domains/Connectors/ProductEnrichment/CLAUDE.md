# ProductEnrichment Connector

Loads when work touches `src/Domains/Connectors/ProductEnrichment/`. Also read the tree-level
`src/Domains/Connectors/CLAUDE.md` and `src/Domains/Inventory/CLAUDE.md` (search engine + index).

## What it does

LLM-enriches each product into a **searchable "recommendation profile"** so vague/conversational
queries ("algo elegante para mi mamá", "un regalo de lujo para mi hermano") match well. It turns a
product (name/description/categories) into structured facets + a semantic blurb, written back onto
the product so `toSearchableArray()` carries them into the search index (Typesense).

This is *multi-vertical*: gift store today, but nothing here is gift-specific. The vertical framing
lives in the per-app prompt, **not** in code (fields are generic: `audience`, `blurb`, …).

## The one knob per app: the enrichment **Agent**

Everything per-tenant flows from a single `Agent` record (resolved by `ProductEnrichmentAgentService`):

| Agent field | Drives |
|---|---|
| `instructions` | the prompt (domain/tone) — `instructionsFromRecord(default)`, default→override |
| `config.enrichment.facets` | the controlled vocabulary — `EnrichmentConfig::forAgent($agent)`, default→override |
| `model` / `provider` | which LLM |
| `user` | the actor for the attribute/tag writes (app-scoped) |

- The global **AgentType "Product Enrichment"** (`handler = ProductEnrichmentAgent::class`) ships the
  defaults; an app overrides by editing its own `Agent` row. Apps with none → the in-code defaults
  (`DEFAULT_FACETS`, generic prompt).
- Pick a specific agent per workflow rule via `params['agent_id']` — it is loaded **strictly within
  the app** (`Agent::fromApp($app)->where('id', …)`), never another tenant's.

## Where each enrichment value is stored (NOT all custom fields)

| Enrichment | Storage | Why |
|---|---|---|
| `audience`, `occasion`, `interests` | product **attributes** (plain names) | distinct, groupable facets → `filter_by audience:male && occasion:birthday` |
| `tags` (elegant, romantic, luxury…) | **Tags subsystem** (`HasTagsTrait`) | flat labels — exactly what tags are; one `tags` facet |
| `blurb` | custom field `search_blurb` | semantic text → the **embedding source** for vector search |
| `price_tier`, `in_stock`, `price` | **derived at index time** in `toSearchableArray()` | stay in sync with price/stock without re-enriching |
| dedup hash | custom field `search_enrichment_hash` | skip the LLM when name/description/categories unchanged |

## Flow

```
product.created/updated  →  (workflow rule)  →  EnrichProductActivity   #[WorkflowAction], auto-discovered
                                                     │ executeIntegration (status + retry)
                                                     ▼
                                                EnrichProductAction
                                                  ├ hash gate (skip if unchanged)
                                                  ├ resolve app's Agent (+ optional agent_id)
                                                  ├ promptWithConfig() → StructuredAgentResponse
                                                  ├ facets → addAttributes($agent->user, …)   (validated via EnrichmentConfig::clean)
                                                  ├ tags  → removeTags(vocab)+addTags(clean)   (NEVER syncTags — it wipes merchant tags)
                                                  ├ blurb → set(search_blurb)
                                                  └ searchable()  (re-index)
```

## Gotchas

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

1. `Products::toSearchableArray()` — emit the `audience`/`occasion`/`interests` attributes + `tags`
   slugs + `blurb` + derived `price_tier`/`in_stock`; add the Typesense schema (facets + auto-embed
   on `blurb`). See `src/Domains/Inventory/CLAUDE.md`.
2. `ProductEnrichmentHandler` + `integrations` seed row + the global "Product Enrichment" AgentType
   (+ optional `DiscoverVocabularyAction` to auto-derive an app's vocab from its catalog).
3. End-to-end test faking the structured agent response.
