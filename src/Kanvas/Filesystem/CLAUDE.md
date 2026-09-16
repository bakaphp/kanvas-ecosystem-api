# Filesystem Mapper — Kanvas Ecosystem API

Loads when work touches `src/Kanvas/Filesystem/`. Covers `FilesystemMapper` as a generic,
multi-tenant field-mapping mechanism — not just the CSV import path it originally shipped for.

## Two different things share this tree

- **`filesystemImport` / `FilesystemImportObserver` / `ImportProductFromFilesystemAction`** — the
  original, **file-specific** pipeline. Needs a real uploaded `Filesystem` row with CSV content.
  Only `Products` is wired to it today, via `Kanvas\Filesystem\Contracts\EntityImportFilesystemInterface::getImportHandler()`
  (`Products::getImportHandler()` is the only implementation — `People` does not implement it).
- **`ApplyFilesystemMapperAction`** — the generic, **file-agnostic** entry point. Takes a
  `FilesystemMapper` + one raw associative array (a CSV row, a connector's API response, a webhook
  payload — anything) and creates/updates the Kanvas entity it describes. No file, no
  `FilesystemImports` row, no queue required. This is what a connector (Salesforce today, Odoo or
  anything else later) should call — never reach for the file pipeline just to process one record.

Both read the **same** `FilesystemMapper.mapping` shape, via `FilesystemMapperWalkerService` — one
implementation, used by both paths. Don't reintroduce a second copy.

`ApplyFilesystemMapperAction` derives `app` and `company` from the mapper itself; only the acting
`user` is passed, because the mapper's own `users_id` is whoever authored it, not whoever is
importing now.

## `mapping` syntax — the field that's easy to get wrong

`mapping` is a **nested array**, not dot-notation strings. A plain key maps straight to a source
field; a nested array recurses.

```json
{
  "name": "Property_Name__c",
  "description": "Brand__c"
}
```

### `attributes` — the actual gotcha

The `attributes` key is special-cased (`FilesystemMapperWalkerService::walk()`), and its shape is **not**
`{ "AttrName": "source_field" }` — that produces an empty result, because `mapAttributes()` requires
each entry to already be an array. The correct shape is a **list of single-key dicts, the key is the
literal attribute name**:

```json
{
  "attributes": [
    { "Deal Status": "Deal_Status__c" },
    { "Marketing Status": "Marketing_Status__c" }
  ]
}
```

Verified empirically (`ApplyFilesystemMapperActionTest`) — anything else (a flat dict, or a
`{"name": "...", "value": "..."}` wrapper per entry) either silently drops the attribute or creates
one literally named `"name"`/`"value"`. `fromProduct: true|false` is an optional third key per entry
— it only matters for the CSV variant→product promotion in
`ImportProductFromFilesystemAction::buildProductFromVariants()`; `ApplyFilesystemMapperAction`
ignores it (it calls `Products::addAttributes()` directly, bypassing that promotion step).

## `configuration` — free JSON, three known keys

`FilesystemMapper.configuration` is an arbitrary JSON column. Three keys `ApplyFilesystemMapperAction`
actually reads:

| Key | Read by | Meaning |
|---|---|---|
| `external_id_field` | `ApplyFilesystemMapperAction::externalIdField()` | **Required.** The custom field holding the source system's own id for the record. Throws `ValidationException` if missing. |
| `product_type_id` | `ProductsTypesRepository::getFromConfiguredId()`, shared with `ImportProductFromFilesystemAction` on the CSV path | Required when the mapper targets `Products`. Throws `ValidationException` if missing. |
| `links` | `ApplyFilesystemMapperAction::applyLinks()` | Describes a related entity to also create from the *same* source record family — see below. |

### `external_id_field` — why it is mandatory

The same source record arrives repeatedly: a webhook re-fires on every upstream edit, a backfill
re-runs. Without an id of its own to match on, a re-import falls through to whatever the create
actions dedupe on — a product's slug (derived from its **name**) and a person's **email/phone**.
All of those are mutable upstream, so the first rename or address change silently forks the record
into a second one, with nothing left to link them by. The field is written on every apply and
matched before create, using the **transaction-safe** custom-field lookup (`apps_custom_fields`
lives on `ecosystem` while the entity lives on `inventory`/`crm`; the plain joining builder reads
stale across an open transaction — see `HasCustomFields`).

### `links` — the multi-entity "recipe"

```json
{
  "links": [
    {
      "mapper_id": 62,
      "source_object": "Location_Contact__c",
      "match_field": "Location__c",
      "link_field": "broker_people_id"
    }
  ]
}
```

- `mapper_id` — which other `FilesystemMapper` builds the linked entity (must belong to the same
  app/company — resolved via `getByIdFromCompanyApp`, never a raw `find()`).
- `source_object` / `match_field` — **only used for a live fetch**, when the caller supplies a
  `$relatedRecordFetcher` closure instead of a pre-correlated record. They describe, in the source
  system's own vocabulary, how to find the related raw record (`source_object` = what to query,
  `match_field` = the field on it that equals the primary record's id). `ApplyFilesystemMapperAction`
  never talks to any API itself — it only calls the closure the caller gave it. **Whoever builds that
  closure is responsible for escaping/validating these two values before they reach a query string**
  — see `SalesforceOutboundMessageWebhookJob::applyMapper()`, which routes both through
  `Kanvas\Connectors\Salesforce\Support\Soql`; they aren't safe to interpolate raw.
- `link_field` — the custom field on the primary entity where the linked entity's id gets stored
  (`$entity->set($linkField, $linkedEntity->getId())`).

Two ways to supply the related raw record, both handled in `applyLinks()`:
1. **Pre-correlated** (`$correlatedRecords[$mapperId] => array`) — the caller already fetched
   everything (a bulk pull that queried both objects up front and matched them in memory).
2. **Live fetch** (`$relatedRecordFetcher` closure) — the caller only has the primary record (a
   single webhook event) and fetches the related one on demand, using `source_object`/`match_field`.

**Recursion has a cycle guard.** `applyLinks()` passes `visitedMapperIds` down through nested
`ApplyFilesystemMapperAction` calls and skips any `mapper_id` already visited on the chain — a mapper
that links to itself, directly or through another mapper, would otherwise recurse forever. **The
fetcher closure is NOT propagated to nested links** (a link-of-a-link can't do a live fetch, only
`correlatedRecords` works more than one level deep) — deliberate scope limit, not an oversight; widen
it only if a real two-level chain shows up.

## Entity dispatch is a small `match`, not the file-pipeline interface

`ApplyFilesystemMapperAction::execute()` matches on `$mapper->systemModule->model_name` —
`Products::class` / `People::class` today. This is deliberately **not** routed through
`EntityImportFilesystemInterface::getImportHandler()`: that interface is shaped for the file
pipeline (`FilesystemImports`), and reusing it for a single in-memory record would be an interface
lying about its own contract. Adding a third entity type means one more `match` arm plus a
`syncX()` method here — small, contained growth.

People go through `SyncPeopleByThirdPartyCustomFieldAction` (match-or-create by custom field, under
a lock) rather than `CreatePeopleAction` directly — the same path the standard Salesforce objects
use. Don't fork it.
