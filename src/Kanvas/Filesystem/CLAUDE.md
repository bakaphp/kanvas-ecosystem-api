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

Both read the **same** `FilesystemMapper.mapping` shape, via `FilesystemMapperWalker` (extracted
from `ImportProductFromFilesystemAction::mapper()`, used by both paths — don't reintroduce a second
copy; `src/Kanvas/Filesystem/Actions/ImportDataFromFilesystemAction.php` is exactly that mistake
already made once — it has its own duplicate `mapper()`/`getJob()` and is **dead code**, unreachable
from anywhere in the app because `People` never implemented the interface it expects. Don't revive
it; don't copy its shape as precedent).

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

The `attributes` key is special-cased (`FilesystemMapperWalker::walk()`), and its shape is **not**
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

## `configuration` — free JSON, two known keys

`FilesystemMapper.configuration` is an arbitrary JSON column. Two keys `ApplyFilesystemMapperAction`
actually reads:

| Key | Read by | Meaning |
|---|---|---|
| `product_type_id` | `ApplyFilesystemMapperAction::createProduct()` (and `ImportProductFromFilesystemAction::resolveProductType()` on the CSV path — near-duplicate logic, not yet unified across the two domains) | Required when the mapper targets `Products`. Throws `ValidationException` if missing. |
| `links` | `ApplyFilesystemMapperAction::applyLinks()` | Describes a related entity to also create from the *same* source record family — see below. |

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
  — see `SalesforceOutboundMessageWebhookJob::applyMapper()` for the SOQL-injection guard pattern
  (`assertValidSoqlIdentifier()` + `escapeSoqlLiteral()`); they aren't safe to interpolate raw.
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
`createX()` method here — small, contained growth.
