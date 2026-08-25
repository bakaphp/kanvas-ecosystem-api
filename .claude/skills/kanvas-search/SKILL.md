---
name: kanvas-search
description: Add `@search` to a GraphQL list query — pick `DatabaseSearchableTrait` vs `DynamicSearchableTrait`, implement `searchableAs()`/`toSearchableArray()`/`shouldBeSearchable()`, and (critical) override `search()` to scope by `apps_id` + `companies_id` so search doesn't leak across tenants. Load when adding `search: String @search` to a query, adding a searchable trait to a model, or auditing/fixing multi-tenant search scoping.
---

# Adding @search to GraphQL Queries

All list queries should support the `@search` directive for text search. This requires three things: the trait on the model, the `search: String @search` parameter on the query, AND a `search()` override to enforce multi-tenancy.

## 1. Add the Trait to the Model

For simple database-only search (most models):
```php
use Baka\Traits\DatabaseSearchableTrait;
use Kanvas\Apps\Models\Apps;

class MyModel extends BaseModel
{
    use DatabaseSearchableTrait;

    public function searchableAs(): string
    {
        $app = $this->app ?? app(Apps::class);
        $customIndex = $app->get('app_custom_{model}_index') ?? null;

        return config('scout.prefix') . ($customIndex ?? '{model}_index');
    }

    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            // ... other searchable fields
        ];
    }

    public function shouldBeSearchable(): bool
    {
        return ! $this->isDeleted();
    }
}
```

For models that need Algolia/Typesense indexing (Products, Leads, Messages, etc.):
```php
use Baka\Traits\DynamicSearchableTrait;

class MyModel extends BaseModel
{
    use DynamicSearchableTrait {
        search as public traitSearch;
    }

    public function searchableAs(): string
    {
        $model = ! $this->searchableDeleteRecord() ? $this : $this->withTrashed()->find($this->id);
        $app = $model->app ?? app(Apps::class);
        $customIndex = $app->get('app_custom_{model}_index') ?? null;
        return config('scout.prefix') . ($customIndex ?? '{model}_index');
    }

    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            // ... other searchable fields
        ];
    }

    public function shouldBeSearchable(): bool
    {
        return ! $this->isDeleted();
    }
}
```

## 2. Add `search` Parameter to the GraphQL Query

```graphql
extend type Query @guard {
    myEntities(
        search: String @search
        where: _ @whereConditions(columns: ["id", "name"])
        orderBy: _ @orderBy(columns: ["id", "created_at", "name"])
    ): [MyEntity!]!
        @paginate(
            model: "Kanvas\\Domain\\Models\\MyModel"
            scopes: ["fromApp", "notDeleted"]
            defaultCount: 25
        )
}
```

## Which Trait to Use

| Trait | Use When | Examples |
|-------|----------|---------|
| `DatabaseSearchableTrait` | Simple models, no external search engine needed | Categories, Channels, Warehouses, Status, Pipeline, Action |
| `DynamicSearchableTrait` | Need Algolia/Typesense indexing, full-text search | Products, Leads, Messages, Agents |

## Algolia Index Configuration Requirement

When a model uses `DynamicSearchableTrait` with Algolia and the `search()` method filters by numeric attributes (e.g., `apps_id`, `companies_id`), those attributes **must be configured in the Algolia dashboard** for the index:

1. Go to the Algolia dashboard > select the index (e.g., `dev-prompt_messages`)
2. Navigate to **Configuration** > **Filtering and Faceting** > **Attributes for faceting**
3. Add `filterOnly(apps_id)` and `filterOnly(companies_id)`
4. If the attribute is purely numeric, also check **numericAttributesForFiltering** — by default all numeric attributes are filterable, but if a custom list is set, the attribute must be included

Without this, Algolia returns: `"invalid numeric attribute(apps_id), attribute not specified in numericAttributesForFiltering setting"`

**Note:** Each app can have a custom index name (e.g., `app_custom_message_index`), so this must be configured per-index in the Algolia UI.

## Typesense: declare every nested numeric child, or it locks to int64

Typesense types an undeclared child of an `object` / `object[]` field from the **first document that
carries it**, and that decision is permanent for the life of the collection. PHP's `json_encode`
drops the zero fraction — `json_encode(['p' => (float) 10])` is `{"p":10}`, not `10.0` — so whether a
money field lands as `int64` or `float` comes down to whether the first record indexed happened to
have a round value. When it did, every later `19.99` is rejected:

```
Error importing document: Field `items.unit_price_net_amount` must be an array of int64.
```

That killed Order indexing in production (Sentry KANVAS-ECOSYSTEM-628). The engine can't work around
it — the Typesense client calls plain `json_encode`, so no PHP-side cast can force `10.0` onto the
wire. **Declaring the child in `typesenseCollectionSchema()` is the only fix.**

```php
['name' => 'items', 'type' => 'object[]', 'optional' => true],
// children of an object[] are arrays: float[], not float
['name' => 'items.unit_price_net_amount', 'type' => 'float[]', 'optional' => true],
['name' => 'items.tax_rate', 'type' => 'float[]', 'optional' => true],
```

- Nesting goes as deep as the payload: `Products.variants` embeds each variant's own searchable
  array, so it declares `variants.warehouses.price`.
- Dynamically-named children take a regex field — `['name' => 'prices\\..*', 'type' => 'float']`.
- Declare the parent object too; Typesense rejects a child whose parent the schema never declares.
- Only the *auto-typed* children are at risk. A top-level field the schema declares as `float`
  happily accepts an integer.

### Repairing collections that already drifted

Scout creates a collection once and never revisits it, so editing the schema only helps *new*
collections. For the ones already in the wild:

```bash
php artisan kanvas:search:typesense-sync-schema "Kanvas\Souk\Orders\Models\Order" --dry-run
php artisan kanvas:search:typesense-sync-schema "Kanvas\Souk\Orders\Models\Order"
```

It drops and re-adds each drifted field (Typesense re-indexes it from the stored documents — no data
loss), defaulting to widening re-types only (`int64 → float`). One field per request is deliberate:
batching two nested fields whose names share a prefix — `items.quantity` and
`items.quantity_fulfilled` — fails with "There are duplicate field names in the schema" and leaves
the second registered twice, under both types.

## 3. Add Search Scoping to Prevent Data Leaks

**Every model that uses `@search` MUST override the `search()` method** to scope results by `apps_id` and `companies_id`. Without this, search queries can leak data across apps and companies.

Both `DatabaseSearchableTrait` and `DynamicSearchableTrait` alias `search as traitSearch`, so the pattern is the same.

### Multi-Tenant Search Patterns

**Standard pattern** (most models — Templates, simple entities):
```php
use Baka\Users\Contracts\UserInterface;
use Kanvas\Apps\Models\Apps;

public static function search($query = '', $callback = null)
{
    $query = self::traitSearch($query, $callback)->where('apps_id', app(Apps::class)->getId());
    $user = auth()->user();
    if ($user instanceof UserInterface && ! $user->isAppOwner()) {
        $query->where('companies_id', $user->getCurrentCompany()->getId());
    }

    return $query;
}
```

**Branch-aware pattern** (Lead model — uses `CompaniesBranches` binding when available):
```php
use Kanvas\Companies\Models\CompaniesBranches;

public static function search($query = '', $callback = null)
{
    $query = self::traitSearch($query, $callback)->where('apps_id', app(Apps::class)->getId());
    $user = auth()->user();

    // When CompaniesBranches is bound (request scoped to a branch), use that company
    if ($user instanceof UserInterface && app()->bound(CompaniesBranches::class)) {
        $query->where('companies_id', app(CompaniesBranches::class)->company->getId());
    } elseif ($user instanceof UserInterface && ! $user->isAppOwner()) {
        $query->where('companies_id', $user->getCurrentCompany()->getId());
    }

    return $query;
}
```

**Product pattern** (supports opt-in company-bound search via app config + Algolia callback):
```php
public static function search($query = '', $callback = null)
{
    $app = app(Apps::class);
    $searchQuery = self::traitSearch($query, $callback)->where('apps_id', $app->getId());
    $user = auth()->user();

    if (
        $user instanceof UserInterface &&
        (
            ! $user->isAppOwner() ||
            (app()->bound(CompaniesBranches::class) && $app->get('enable_company_bound_search', false))
        )
    ) {
        $searchQuery->where('company.id', $user->getCurrentCompany()->getId());
    }

    return $searchQuery;
}
```

**Users pattern** (uses `whereIn` for array-based Algolia/Typesense filters):
```php
public static function search($query = '', $callback = null)
{
    $query = self::traitSearch($query, $callback)->whereIn('apps', [app(Apps::class)->getId()]);
    $user = auth()->user();
    if ($user instanceof UserInterface && ! $user->isAppOwner()) {
        $query->whereIn('companies', [$user->currentCompanyId()]);
    }

    return $query;
}
```

### Key rules for `search()`

- **Always filter by `apps_id`** — no exceptions
- **Always filter by `companies_id`** for non-app-owners — prevents cross-company data leaks
- **Use `isAppOwner()`** (not `isAdmin()`) for the company-scoping check — `isAppOwner()` returns `true` only for `@guardByAppKey` requests with Owner role
- `isAdmin()` returns `true` for any Admin/Owner role regardless of auth method, which would skip company filtering on `@guard` endpoints
- **Check `CompaniesBranches` binding** when the entity is branch-scoped — this ensures the correct company context when a request targets a specific branch
- **Filter field names vary by search engine**: Algolia uses nested paths like `company.id`, Typesense/database use flat `companies_id`. Match what's in `toSearchableArray()`
- **`@search` bypasses `@paginate(builder:)` scoping** — When Lighthouse's `@search` directive is active, it calls `Model::search()` and results come entirely from the search engine. The custom builder specified in `@paginate(builder: ...)` is **NOT applied**. The `search()` method is the **only** place to enforce multi-tenancy during search
- **When using `search()` in a custom builder** (not via `@search`), call `traitSearch()` directly with explicit filters instead of the model's `search()` method, since `search()` auto-scopes to the logged-in user's company which may not be the target company

**Typesense schema requirement:** Models using `DynamicSearchableTrait` that may use the Typesense engine **MUST implement `typesenseCollectionSchema()`**. Without it, the Typesense engine throws `Parameter 'fields' is required` when creating the collection. The method should define fields matching `toSearchableArray()`.

**Typesense `id` MUST be a string — the #1 recurring production break.** Typesense rejects any document whose `id` field is an integer with `Document's 'id' field should be a string` (import fails silently mid-reindex, e.g. KANVAS-ECOSYSTEM). This bites because `toSearchableArray()` usually does `...$this->toArray()` which spreads the numeric primary key. Two things are required together:

1. **Cast in `toSearchableArray()`** — after any `...$this->toArray()` spread, override `id`:
   ```php
   public function toSearchableArray(): array
   {
       return [
           ...$this->toArray(),
           'id' => (string) $this->id, // Typesense requires the document id to be a string
           // ... other fields
       ];
   }
   ```
2. **Declare `string` in `typesenseCollectionSchema()`** — the `id` field must be `'type' => 'string'`, NOT `'int64'`:
   ```php
   public function typesenseCollectionSchema(): array
   {
       return [
           'name' => $this->searchableAs(),
           'fields' => [
               ['name' => 'id', 'type' => 'string'],
               ['name' => 'name', 'type' => 'string', 'optional' => true],
               // query_by fields must be 'string' or 'string[]' and exist here
           ],
       ];
   }
   ```

**`query_by` requirement (Typesense only):** Typesense — unlike Algolia — requires an explicit `query_by` on every search naming which fields to match, or it 400s with `No search fields specified for the query` (KANVAS-ECOSYSTEM-626). Set it inside the `search()` override behind an `isTypesense()` gate, and again in any place that calls `traitSearch()` directly (`traitSearch` bypasses `search()`):
```php
$query = self::traitSearch($query, $callback)->where('apps_id', app(Apps::class)->getId());
if ($query->model->isTypesense()) {
    $query->options(['query_by' => 'name,email,slug']); // only string/string[] fields in the schema
}
```

**Typesense collections are immutable after creation** — `getOrCreateCollectionFromModel()` only *creates* a missing collection; it never alters an existing one. Changing `typesenseCollectionSchema()` (new field, `int64`→`string` on `id`, new `query_by` target) has NO effect on a live collection until you **drop it and reindex**. Code change alone won't fix a schema mismatch in production.

**Placement:** Place the `search()` method at the **end of the class**, not at the top. Properties (`$table`, `$guarded`, `casts()`) and relationships should come first.

## Analytics/aggregation queries — stricter rule

For analytics queries that aggregate across rows (counts, sums, distributions), filtering by `apps_id` + `companies_id` is **mandatory at the base action level** — no app-owner bypass, no exceptions. Every analytics endpoint needs a cross-tenant leak test.
