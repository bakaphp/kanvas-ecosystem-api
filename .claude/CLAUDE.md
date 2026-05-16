# Kanvas Ecosystem API - Development Guide

Guidelines for working with the Kanvas Ecosystem API codebase.

## Architecture Overview

- **Multi-database**: Each domain has its own database connection (`action_engine`, `crm`, `inventory`, `social`, `ecosystem`)
- **Domain-driven design**: Code is organized by domain under `src/Domains/{DomainName}/`
- **GraphQL API**: Uses Lighthouse PHP framework with schema files in `graphql/schemas/`
- **PHP 8.4**: Use modern syntax (e.g., `new Foo(...)->execute()` not `(new Foo(...))->execute()`)
- **Method call formatting**: When a method call has 4 or more arguments, format vertically with one argument per line:
  ```php
  // 3 or fewer args — inline is fine
  $this->doSomething($a, $b, $c);

  // 4+ args — always vertical
  $this->uploadImageToEntity(
      $company,
      app(Apps::class),
      auth()->user(),
      $request['file'],
      'photo'
  );
  ```
- **PHP-CS-Fixer enforced** — config lives at `.php-cs-fixer.php`. A `PostToolUse` hook runs the fixer on every edited `.php` file automatically (see `.claude/settings.json`). If the binary isn't installed in the current environment, match the rules by hand:
  - Anonymous classes: `new class () extends Foo {` (parentheses + space before brace, brace on same line)
  - Multi-line closures passed as method arguments: place the closure on a new line, e.g. `->whereHas('rel', fn ($q) => ...)` becomes `->whereHas(\n    'rel',\n    fn ($q) => ...\n)`
  - `use` imports: alphabetical order **across the entire use block** (not just within each namespace group) — e.g. `Connectors\Zoho\...` must come after `Connectors\WooCommerce\...`
  - No superfluous phpdoc tags (strip `@var mixed $x` style annotations when the var is already typed by assignment; see `no_superfluous_phpdoc_tags` with `allow_mixed: true` — applies only when the variable is named)
  - No trailing blank line before the closing `}` of a class
  - `no_empty_comment`, `single_quote`, `array_syntax: short`, `trailing_comma_in_multiline`, and the other rules in `.php-cs-fixer.php`
- **Email rendering note**: `KanvasMailable` is HTML-first and uses `resources/views/emails/layout.blade.php`. If a feature needs true plain-text body delivery (for example raw ADF/XML in the body with no escaping/wrapping), use a dedicated plain-text view such as `resources/views/emails/plain.blade.php` instead of routing through the HTML layout.

## Skills (load on demand)

Big task-shaped recipes have been moved to skills under `.claude/skills/`. They are not pre-loaded; invoke them only when starting work that fits the description.

- **`kanvas-crud`** — Build a new domain CRUD (DTO + Create/Update Actions + GraphQL Mutation + schema + tests). Use when scaffolding a new entity under `src/Domains/{Domain}/{Entity}/`.
- **`kanvas-connector`** — Build a new external-service integration under `src/Domains/Connectors/{ConnectorName}/` (Handler + Client + DTO + Enums + Webhook job + Workflow activity + GraphQL setup mutation + `integrations` row). Use when adding/editing any connector. Includes the Octane "never cache SDK in static" rule and the "AgentRuntime is a primary domain not a connector" foot-guns.
- **`kanvas-search`** — Add `@search` to a list query with proper multi-tenant `search()` override. Use when adding `search: String @search`, choosing between `DatabaseSearchableTrait` and `DynamicSearchableTrait`, or auditing tenant scoping.

Sub-directory `CLAUDE.md` files load additively when work touches their tree:
- `tests/CLAUDE.md` — Docker test commands, `RefreshDatabase` ban, Bouncer setup, AppKey-guarded test pattern.
- `src/Domains/Connectors/CLAUDE.md` — connector-tree-specific gotchas (Octane SDK rule, Activities/ folder, AgentRuntime caveat).
- `graphql/schemas/CLAUDE.md` — directive conventions, FK-id-vs-relation rule, schema folder rule.

### Where to put new conventions (don't bloat this file)

Default to writing rules **outside** root CLAUDE.md. The root file should only carry conventions that apply to nearly every PHP edit; everything else has a more specific home.

| Type of rule | Goes in | Examples |
|---|---|---|
| Task-shaped recipe (a scaffold you'd follow start-to-finish) | New skill at `.claude/skills/<name>/SKILL.md` with a `description:` that matches when it should load | Building a new CRUD, scaffolding a connector, wiring `@search` |
| Tree-specific gotcha (only relevant inside one directory) | Subdir `CLAUDE.md` in that tree | Tests setup, connector Octane rule, schema directive conventions |
| Cross-cutting convention (every PHP file is subject to it) | Root `.claude/CLAUDE.md` under "Key Conventions" | No inline FQCNs, DTO conventions, no FK ids in GraphQL response types, queued-job Spatie Data rule |
| Deterministic behavior (formatter, linter, generator) | Hook in `.claude/hooks/` wired up via `.claude/settings.json` | php-cs-fixer auto-run |
| Personal session notes / project-state pointers | Memory under `~/.claude/projects/.../memory/` (one-line in `MEMORY.md`, detail in topic file) | "Importer migration PR3 ready", "OpenClaw shared image arch" |

Before adding a new section to this root file, ask: "would this still be relevant if I were editing a totally unrelated domain right now?" If no, it belongs in a skill or subdir CLAUDE.md.

When you do add a new skill or subdir CLAUDE.md, **also link it from the lists above** so future sessions can discover it.

## Notifications

Always extend `Kanvas\Notifications\Notification` (not `\Illuminate\Notifications\Notification`) for all notification classes in this codebase.

```php
use Kanvas\Notifications\Notification;

class MyNotification extends Notification
{
    public function __construct(
        protected SomeModel $entity,
        // ... other params
        protected Apps $app,
        protected Companies $company,
        protected ?Users $fromUser = null,
    ) {
        parent::__construct($entity, [
            'app' => $app,
            'company' => $company,
            'fromUser' => $fromUser,
        ]);

        // Set channels as slug strings; the base class maps them to channel classes via
        // NotificationChannelEnum::getNotificationChannelBySlug() in via()
        $this->channels = ['mail', 'sms', 'push'];
    }
}
```

**Key points:**
- `Kanvas\Notifications\Notification` implements `ShouldQueue`, includes SMTP config, OneSignal, Expo, SMS, and storage traits
- Set `$this->channels` with slug strings (`'mail'`, `'sms'`, `'push'`, `'expo'`, `'database'`) — the base `via()` maps them to channel classes automatically via `Kanvas\Notifications\Enums\NotificationChannelEnum::getNotificationChannelBySlug()`
- Override `toMail()` and/or `toOneSignal()` only when you need notification-specific content that differs from the template-based defaults
- Never use `\Illuminate\Notifications\Notification` directly

## Files on a Model — Lighthouse Cache Pattern

Any model that exposes `files: [Filesystem!]! @cacheRedis` (which is the default for `HasFilesystemTrait` models) **must** participate in the Lighthouse Redis cache invalidation protocol. Without this, uploaded files do not appear in the UI until the cache expires, because `@cacheRedis` returns stale data.

Canonical reference: [`Deal`](../src/Domains/Guild/Deals/Models/Deal.php) + [`DealObserver`](../src/Domains/Guild/Deals/Observers/DealObserver.php). Mirror this shape on every new model that has file uploads.

### Required on the Model

```php
use Baka\Traits\HasLightHouseCache;
use Override;

class Foo extends BaseModel
{
    use HasLightHouseCache;

    #[Override]
    public function getGraphTypeName(): string
    {
        return 'Foo';                     // MUST match the GraphQL type name exactly
    }
}
```

- `HasLightHouseCache` defines `abstract public function getGraphTypeName(): string` — implement it or PHP fatals. Return the GraphQL type name as it appears in the schema (e.g. `'Event'`, `'EventVersion'`, `'Facilitator'`).
- **Add `#[Override]`** on `getGraphTypeName()`. It implements an abstract trait method, which PHP treats as a valid override target — this is the opposite of concrete trait methods (see `#[Override] attribute` note under Key Conventions). The canonical `Deal` model does this.
- `BaseModel` for the domain typically already includes `HasFilesystemTrait`; if not, add it too (required for `getFilesQueryBuilder()` which the cache regeneration uses).

### Required on the Observer

```php
class FooObserver
{
    public function updating(Foo $foo): void
    {
        $foo->clearLightHouseCache(withKanvasConfiguration: false);
    }
}
```

- Use `updating()` (fires before the save) so the cache is gone before any listener reads it post-save.
- `withKanvasConfiguration: false` is the right default — file-relation regeneration is handled automatically by `AttachFilesystemAction` when files are attached. `true` eagerly regenerates custom_fields/files cache inside the observer, which is usually overkill and creates extra Redis writes.

### How file uploads trigger invalidation

[`AttachFilesystemAction`](../src/Kanvas/Filesystem/Actions/AttachFilesystemAction.php) already calls:
```php
if (method_exists($this->entity, 'clearLightHouseCache')) {
    $this->entity->clearLightHouseCacheJob();
}
```
So any `addMultipleFilesFromUrl()` / `addFileFromUrl()` call automatically invalidates the entity's cache — **as long as the model uses `HasLightHouseCache` and implements `getGraphTypeName()`**. Missing the trait silently disables invalidation. No direct resolver changes needed.

### Checklist for a new model with file uploads

- [ ] `HasLightHouseCache` trait on the model
- [ ] `getGraphTypeName()` returning the GraphQL type name
- [ ] `HasFilesystemTrait` (usually inherited from `BaseModel`)
- [ ] Observer `updating()` hook calling `clearLightHouseCache(withKanvasConfiguration: false)`
- [ ] GraphQL type uses `files: [Filesystem!]! @cacheRedis @paginate(...)` with the shared `FilesystemQuery@getFileByGraphType` builder (so the cache key shape matches what `generateFilesLighthouseCache()` writes)
- [ ] Smoke test: upload a file via `updateX(files: [...])`, query `x.files` in the same or next request, confirm the new file appears without a manual cache flush

## Key Conventions

### No Inline Fully-Qualified Class Names
Always use `use` imports at the top of the file instead of inline fully-qualified class names (FQCNs). This applies to both code **and** docblock `@property`/`@param`/`@return` annotations, **and** catch blocks.

```php
// WRONG — inline FQCN
$this->next_retry_at = \Illuminate\Support\Carbon::parse($retryAt);

// WRONG — FQCN in docblock
/** @property \Illuminate\Support\Carbon|null $approved_at */

// WRONG — inline FQCN in catch block
} catch (\Throwable $e) {

// CORRECT — use import + short name everywhere
use Illuminate\Support\Carbon;
use Throwable;

/** @property Carbon|null $approved_at */

$this->next_retry_at = Carbon::parse($retryAt);

} catch (Throwable $e) {
```

### PHP 8.4 Syntax
```php
// Correct (PHP 8.4)
new CreateActionAction(...)->execute();

// Unnecessary (old style - do NOT use)
(new CreateActionAction(...))->execute();
```

### DTO Naming
- Name DTOs after the entity: `Action.php`, `Pipeline.php` (NOT `ActionInput.php`)
- Alias when importing alongside the model: `use ...\DataTransferObject\Action as ActionData;`

### DTO Conventions
- **Always include context objects** (app, company, user) in DTOs that create entities — never pass them as separate action constructor params
- **Use model objects instead of raw IDs** for foreign key relationships (e.g., `TaskList $taskList` not `int $task_list_id`, `CompanyAction $companyAction` not `int $companies_action_id`)
- **Mutation resolvers look up models** from IDs and pass them to the DTO — do NOT use `::from($request['input'])` when the DTO has object properties
- **Actions receive only the DTO** (and optionally the existing model for updates) — they pull IDs via `$this->data->taskList->getId()`

#### Use `fromMultiple` for non-trivial DTOs (more than ~3 model lookups or enum casts)

When a DTO has multiple model-lookups, enum casts, date parsing, or conditional defaults, **put that assembly logic in a static `fromMultiple` factory on the DTO** rather than repeating it in every mutation resolver. This is the codebase convention — see [`Event::fromMultiple`](../src/Domains/Event/Events/DataTransferObject/Event.php), [`Deal::fromMultiple`](../src/Domains/Guild/Deals/DataTransferObject/Deal.php), [`Engagement::fromMultiple`](../src/Domains/ActionEngine/Engagements/DataTransferObject/Engagement.php), [`DraftOrder::fromMultiple`](../src/Domains/Souk/Orders/DataTransferObject/DraftOrder.php).

For updates, pair it with a `forUpdate(Model $existing, ..., array $data)` factory that overlays the input array onto the existing model's values so partial updates work without losing fields. See [`Plan::forUpdate`](../src/Domains/NervousSystem/Plan/DataTransferObject/Plan.php).

**Pattern shape:**

```php
class Plan extends Data
{
    public function __construct(
        public readonly AppInterface $app,
        public readonly CompanyInterface $company,
        public readonly string $title,
        public readonly ?Agent $agent = null,
        // ...
    ) {}

    public static function fromMultiple(
        AppInterface $app,
        Users $requestingUser,
        CompanyInterface $company,
        array $data,
    ): self {
        /** @var Agent|null $agent */
        $agent = isset($data['agent_id'])
            ? Agent::getByIdFromCompanyApp((int) $data['agent_id'], $company, $app)
            : null;

        return new self(
            app: $app,
            company: $company,
            title: (string) $data['title'],
            agent: $agent,
            status: isset($data['status']) ? PlanStatusEnum::from((string) $data['status']) : PlanStatusEnum::DRAFT,
            // ...
        );
    }

    public static function forUpdate(PlanModel $plan, AppInterface $app, CompanyInterface $company, array $data): self
    {
        return new self(
            app: $app,
            company: $company,
            title: (string) ($data['title'] ?? $plan->title),
            // ... overlay input on existing
        );
    }
}
```

**Mutation resolver becomes a 3-liner:**

```php
public function create(mixed $rootValue, array $request): Plan
{
    $app = app(Apps::class);
    $user = auth()->user();
    $company = $user->getCurrentCompany();

    return new CreatePlanAction(
        PlanData::fromMultiple($app, $user, $company, $request['input']),
    )->execute();
}

public function update(mixed $rootValue, array $request): Plan
{
    $app = app(Apps::class);
    $user = auth()->user();
    $company = $user->getCurrentCompany();

    /** @var Plan $plan */
    $plan = Plan::getByIdFromCompanyApp((int) $request['id'], $company, $app);

    return new UpdatePlanAction(
        $plan,
        PlanData::forUpdate($plan, $app, $company, $request['input']),
    )->execute();
}
```

For DTOs that are simple value objects (3 or fewer fields, no model lookups), inline construction in the resolver is still fine — don't add `fromMultiple` for the sake of it.

#### Trivial DTO example (still inline-construction, fine as-is)

```php
// DTO with context and model objects
class TaskListItem extends Data
{
    public function __construct(
        public readonly TaskList $taskList,
        public readonly CompanyAction $companyAction,
        public readonly string $name,
        public readonly ?string $status = null,
    ) {
    }
}

// Mutation resolver constructs DTO manually
$taskList = TaskList::getByIdFromCompanyApp((int) $input['task_list_id'], $company, $app);
$companyAction = CompanyAction::getByIdFromCompanyApp((int) $input['companies_action_id'], $company, $app);

return new CreateTaskListItemAction(
    new TaskListItemData(
        taskList: $taskList,
        companyAction: $companyAction,
        name: $input['name'],
    ),
)->execute();

// Action pulls IDs from objects
$taskListItem->task_list_id = $this->data->taskList->getId();
$taskListItem->companies_action_id = $this->data->companyAction->getId();
```

### Enum Usage in DTOs
When a domain defines PHP enums (e.g., in `src/Domains/{Domain}/{Entity}/Enums/`), **use the enum type in DTOs instead of raw strings**. This provides type safety and prevents invalid values.

```php
// DTO — use enum types with enum defaults
class Affiliate extends Data
{
    public function __construct(
        public readonly AffiliateTypeEnum $affiliate_type = AffiliateTypeEnum::BUSINESS,
        public readonly AffiliateStatusEnum $status = AffiliateStatusEnum::PENDING,
        public readonly CommissionTypeEnum $commission_type = CommissionTypeEnum::PERCENTAGE,
        // For nullable enum fields:
        public readonly ?PayoutMethodEnum $payout_method = null,
    ) {
    }
}

// Mutation — construct enums with ::from()
affiliate_type: AffiliateTypeEnum::from($input['affiliate_type'] ?? 'business'),
// For nullable enum fields:
payout_method: isset($input['payout_method']) ? PayoutMethodEnum::from($input['payout_method']) : null,

// Action — store the string value with ->value
$model->affiliate_type = $this->data->affiliate_type->value;
// For nullable enum fields:
$model->payout_method = $this->data->payout_method?->value;
```

Key rules:
- **DTO**: Use the enum type (e.g., `CommissionTypeEnum`) with an enum case as default (e.g., `CommissionTypeEnum::PERCENTAGE`)
- **Mutation**: Use `EnumClass::from($input['field'] ?? 'default')` to construct the enum from the GraphQL input string
- **Action**: Use `->value` to extract the string when assigning to the model (e.g., `$this->data->status->value`)
- **Nullable enums**: Use `?EnumClass` in DTO, `isset()` check in mutation, `?->value` in action

### Never Queue a Spatie Data DTO that holds Eloquent Models or Model Interfaces

`Spatie\LaravelData\Data` overrides `__serialize` / `__unserialize`. When a `Data` subclass has a property typed as an Eloquent model (`Apps`, `Companies`, ...) or model interface (`AppInterface`, `CompanyInterface`, ...), the serializer flattens the model to a primitive FK form (e.g. property `app` becomes a camelCased `appsId` entry in the serialized payload). On `__unserialize` in the worker, the primitive does **not** restore back into the typed property — it lands as a **dynamic property** (`$appsId`) and the typed `$app` stays uninitialized. The next read of `$dto->app` fatals with `Typed property X must not be accessed before initialization`. The breadcrumb tell is a `Creation of dynamic property ...::$xxxId is deprecated` warning logged from `CallQueuedHandler` right before the crash.

This is not Laravel's `SerializesModels` — that trait handles **direct** model properties on the **job** correctly via `ModelIdentifier`. The bug only appears when the model is **nested inside a Spatie Data DTO** that the job stores as a property.

**Rule:** for any `ShouldQueue` job, do not store a `Spatie\LaravelData\Data` DTO that contains Eloquent models or model interfaces. Take the models and primitives directly on the job, and rebuild the DTO inside `handle()`:

```php
// WRONG — DTO with AppInterface/CompanyInterface goes through queue, breaks on unserialize
class AppendToLedgerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly EventData $data) {}

    public function handle(): void
    {
        new AppendEventAction($this->data)->execute(); // $this->data->app uninitialized
    }
}

// CORRECT — Eloquent models + primitives on the job; SerializesModels round-trips them
class AppendToLedgerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly Apps $app,
        public readonly ?Companies $company,
        public readonly string $sourceDomain,
        public readonly string $eventType,
        public readonly EventStatusEnum $status,
        // ... rest as primitives (string, int, ?array, ?string for Carbon-as-ISO, ...)
    ) {}

    public function handle(): void
    {
        $data = new EventData(
            app: $this->app,
            company: $this->company,
            sourceDomain: $this->sourceDomain,
            // ... rebuild DTO from fields
        );

        new AppendEventAction($data)->execute();
    }
}
```

Notes:
- Use concrete Eloquent classes (`Apps`, `Companies`, `Users`) on the job constructor, not the interfaces — `SerializesModels` resolves them via `ModelIdentifier` which needs the class name.
- Pass `Carbon` instances as ISO-8601 strings (`Carbon::now()->toIso8601String()`) and re-parse with `Carbon::parse()` in `handle()`. Avoids the same Spatie-Data-style serialization quirk if the DTO has Carbon properties.
- Synchronous emission paths (e.g. trait helpers like `EmitsLedgerEventsForEntity::emitLedgerEvent`) are unaffected — there's no serialization, so passing the Spatie Data DTO directly to the action is still fine there.
- This isn't unique to the Ledger domain — apply the rule to **every** queued job that has a Spatie Data DTO in scope.

### Database Connections
Each domain has its own database connection defined in the domain's BaseModel:
- `action_engine` - ActionEngine domain
- `crm` - Guild domain (leads, pipelines, etc.)
- `inventory` - Inventory domain
- `social` - Social domain

Use the correct connection in `DB::connection('{connection}')->transaction()`.

### Model Base Classes & Traits
- **BaseModel** per domain (e.g., `Kanvas\ActionEngine\Models\BaseModel`) - sets DB connection, includes `KanvasModelTrait`
- **KanvasModelTrait** - provides `fromCompany`, `fromApp`, `notDeleted` scopes, `getById()`, `getByIdFromCompanyApp()`, `softDelete()`
- **UuidTrait** - auto-generates UUID on creation
- **SlugTrait** - auto-generates slug from `name` field
- **AppsIdTrait** - auto-sets `apps_id` from current app context on creation
- **DatabaseSearchableTrait** / **DynamicSearchableTrait** - `@search` support; see skill `kanvas-search`

### Authorization Directives
- `@guard` - any authenticated user
- `@guardByAdmin` - admin/owner only (uses `isAdmin()` check)
- `@guardByAppKey` - app key (super admin / system) only
- `@can(ability: "create", model: "Kanvas\\Domain\\Models\\Entity")` - Bouncer ability check per model

#### Using `@can` for Model-Level Permissions

Use `@can` on mutations that need model-level permission checks (create, edit, delete):

```graphql
extend type Mutation @guard {
    create{Entity}(input: {Entity}Input!): {Entity}!
        @can(ability: "create", model: "Kanvas\\{Domain}\\{Entity}\\Models\\{Entity}")
        @field(resolver: "App\\GraphQL\\{Domain}\\Mutations\\{Entity}\\{Entity}Mutation@create")
    update{Entity}(id: ID!, input: Update{Entity}Input!): {Entity}!
        @can(ability: "edit", model: "Kanvas\\{Domain}\\{Entity}\\Models\\{Entity}")
        @field(resolver: "App\\GraphQL\\{Domain}\\Mutations\\{Entity}\\{Entity}Mutation@update")
    delete{Entity}(id: ID!): Boolean!
        @can(ability: "delete", model: "Kanvas\\{Domain}\\{Entity}\\Models\\{Entity}")
        @field(resolver: "App\\GraphQL\\{Domain}\\Mutations\\{Entity}\\{Entity}Mutation@delete")
}
```

### Soft Deletes
All models use `is_deleted` boolean flag (not Laravel's `SoftDeletes` trait). Use `$model->softDelete()` and the `notDeleted` scope.

### Cascade Soft Deletes

Use `dyrynda/laravel-cascade-soft-deletes` to automatically soft-delete related records when a parent is deleted:

```php
use Dyrynda\Database\Support\CascadeSoftDeletes;

class Agent extends BaseModel
{
    use CascadeSoftDeletes;

    protected $cascadeDeletes = ['deployments'];
}
```

**Requirement:** The domain's BaseModel must use `Baka\Traits\SoftDeletesTrait` and override `trashed()`:
```php
use Baka\Traits\SoftDeletesTrait;

class BaseModel extends EloquentModel
{
    use SoftDeletesTrait;

    public function trashed()
    {
        return (bool) $this->{$this->getDeletedAtColumn()};
    }
}
```

**Delete call:** Use `$model->delete()` (not `softDelete()`) — `SoftDeletesTrait` makes `delete()` perform a soft delete via `runSoftDelete()`, which triggers the `deleting` event that `CascadeSoftDeletes` listens on.

### Scoping Patterns
- **Global entities** (companies_id = 0): scope queries with `fromApp` + `notDeleted`
- **Company-scoped entities**: scope queries with `fromCompany` + `fromApp` + `notDeleted`
- Lookups: `Model::getById($id, $app)` for global, `Model::getByIdFromCompanyApp($id, $company, $app)` for company-scoped

#### Never recreate `forApp` / `forCompany` scopes — `fromApp` / `fromCompany` already exist

`KanvasModelTrait` (via `KanvasAppScopesTrait` + `KanvasCompanyScopesTrait`) provides `fromApp(?$app = null)` and `fromCompany(?$company = null)` on every model that uses it — which is every domain BaseModel. They scope to a **single** `apps_id` / `companies_id` (no `apps_id=0` union), and accept either an `Apps` instance, an id, or `null` (falls back to `app(Apps::class)`). Always pass the resolved app/company in explicitly when you have one — don't rely on the global resolver.

```php
// CORRECT — use the canonical scopes
Tool::query()->where('id', $id)->fromApp($app)->firstOrFail();
Plan::query()->fromApp($app)->fromCompany($company)->notDeleted()->get();
```

```php
// WRONG — never add a per-model app/company scope
public function scopeForApp(Builder $query, mixed $appsId = null): Builder
{
    return $query->whereIn('apps_id', [0, /* current */]);
}
```

**Don't reach for the `apps_id=0` union unless the entity is actively designed to ship platform-global rows that every tenant sees.** A union scope on a per-tenant entity is how cross-app leaks happen. Today the only models with that pattern are `AgentType` and `ToolCategory`, each via an explicit `scopeFromAppOrGlobal` — copy that name (not `forApp`) if you genuinely need the union, and document *why* the entity ships platform globals in the docblock.

The legacy `Activity::scopeForApp` predates `KanvasModelTrait`; don't model new code on it.

### JSON/Array Fields
If a model has JSON columns, cast them with `Baka\Casts\Json::class` — **never** `'array'`. The Baka cast handles edge cases like double-encoded JSON, MariaDB's longtext-without-validity, and round-trip equality, which Laravel's built-in `'array'` cast does not.

```php
use Baka\Casts\Json;

protected function casts(): array
{
    return [
        'form_fields' => Json::class,
        'form_config' => Json::class,
    ];
}
```

### UUID Auto-generation
**Never call `Str::uuid()` manually** when assigning a `uuid` column. Use `Baka\Traits\UuidTrait`. The trait registers a `creating` Eloquent hook that auto-populates `$model->uuid` if not already set.

```php
use Baka\Traits\UuidTrait;

class Foo extends BaseModel
{
    use UuidTrait;
    // ...
}
```

In actions / factories: just `new Foo()->save()` — the UUID lands automatically. If you find `$x->uuid = (string) Str::uuid();` in a fresh action, delete it.

### Eloquent Lifecycle Listeners via Traits
Two foot-guns when wiring lifecycle events (created/updated/deleted) from a trait `boot{TraitName}` method:

**1. Don't call `static::observe(SomeObserver::class)` from `bootXxxTrait`.** Laravel's `Model::observe()` does `new static` internally to introspect the model — but the trait's boot is *running inside* `bootIfNotBooted()`, so `new static` triggers re-entrant boot. Laravel rejects with: "The `bootIfNotBooted` method may not be called on model [X] while it is being booted."

Use static event closures instead, delegating to a stateless service:

```php
public static function bootEmitsXxxEvents(): void
{
    static::created(fn (Model $m) => XxxDispatcher::recordCreated($m));
    static::updated(fn (Model $m) => XxxDispatcher::recordUpdated($m));
    static::deleted(fn (Model $m) => XxxDispatcher::recordDeleted($m));
}
```

The closures don't instantiate the model; they just append listeners. The dispatcher class holds the diff/payload/scrub logic — same testability, no recursion.

**2. Use `property_exists()` to read trait-defined override properties.** Eloquent's `__get` intercepts unknown property reads as relation lookups. If a trait method does `return $this->myList ?? []` and the model didn't declare `protected array $myList`, Eloquent thinks `myList` is a relation, calls the same method to resolve it, sees a non-`Relation` return type, and throws "must return a relationship instance."

```php
// WRONG — fails when the model doesn't declare $hiddenFields
public function hiddenFields(): array
{
    return $this->hiddenFields ?? [];
}

// CORRECT — property_exists checks the class definition without triggering __get
public function hiddenFields(): array
{
    if (property_exists($this, 'hiddenFields')) {
        return $this->hiddenFields;
    }
    return [];
}
```

### Custom Domain BaseModel for Append-Only Tables
For domains with append-only / immutable rows (event ledgers, audit logs, archives) that **don't** need soft-delete / custom-fields / files, create a lean per-domain BaseModel rather than extending the heavyweight Intelligence/Guild/Inventory BaseModels. Use `KanvasModelTrait` to get the company/user/app relations + scopes for free:

```php
namespace Kanvas\YourDomain\Models;

use Baka\Traits\KanvasModelTrait;
use Illuminate\Database\Eloquent\Model;

class BaseModel extends Model
{
    use KanvasModelTrait; // company(), user(), app() relations + KanvasScopesTrait

    protected $connection = 'intelligence'; // or whichever
    public $timestamps = false;             // tables track their own timestamps
}
```

Concrete models then `extend BaseModel` and inherit `fromApp()`, `fromCompany()`, `notDeleted()` scopes plus `company()`, `user()`, `app()` BelongsTo relations. **Caveat:** `KanvasModelTrait`'s static lookup helpers (`getById`, `getByIdFromCompanyApp`, etc.) call `notDeleted()` which expects an `is_deleted` column — they'll error on append-only tables. Use `Model::query()->fromApp($app)->where(...)` for direct lookups instead.

### GraphQL `@paginate` — Prefer Scopes over Custom Builder
When a list query's filtering can be expressed as named scopes on the model, use `@paginate(model: ..., scopes: [...])` instead of a custom `builder:` resolver class. Saves a class file and keeps the schema self-describing.

```graphql
# PREFERRED — no resolver class needed
extend type Query @guardByAdmin {
    ledgerEvents(...): [LedgerEvent!]!
        @paginate(
            model: "Kanvas\\NervousSystem\\Ledger\\Models\\Event"
            scopes: ["fromApp", "fromCompany", "recent"]
            defaultCount: 50
        )
}
```

`fromApp` / `fromCompany` from `KanvasModelTrait` already handle the AppKey-vs-user-context conditional internally — no need to re-implement that logic in a resolver. Reach for a custom `builder:` resolver only when the constraints genuinely can't be expressed as scopes (e.g. dynamic field selection, runtime config lookups).

### Don't Expose Tenant FK Ids in GraphQL Response Types
`apps_id` is **always** the current app in any tenant-scoped query — exposing it on the response type is dead bytes. For `companies_id`, expose the `company` relation instead via `@belongsTo(relation: "company")`. Same for `users_id` → `user` relation.

```graphql
# WRONG — apps_id and companies_id are redundant/raw
type LedgerEvent {
    apps_id: Int!
    companies_id: Int!
    ...
}

# CORRECT — drop apps_id, expose company as a relation
type LedgerEvent {
    company: Company! @belongsTo(relation: "company")
    ...
}
```

If a query truly needs cross-tenant visibility (super-admin dashboards), use a separate `@guardByAppKey` query — never expose `apps_id` as a `@whereConditions` column on a normal query.

### Don't Expose Any FK ID When the Relation Is Already There
Same principle generalizes beyond tenant fields. If a GraphQL type exposes a `@belongsTo` relation, **don't also expose the underlying `*_id` column** — it's redundant and clients should use the relation. Pick one (the relation, always).

```graphql
# WRONG — duplicating
type NervousSystemTask {
    plan_id: Int!
    plan: NervousSystemPlan! @belongsTo(relation: "plan")
    ...
}

# CORRECT — relations only
type NervousSystemTask {
    plan: NervousSystemPlan! @belongsTo(relation: "plan")
    ...
}
```

The only time it's OK to expose a raw `*_id`: **input types** for create/update mutations, where the client passes an ID before the relation exists. Even there, prefer letting the resolver look up the model and pass it explicitly to the action's DTO (per the DTO conventions section).

### Reduce Duplicate Ledger Emission with `EmitsLedgerEventsForEntity`
When a domain entity emits multiple lifecycle events from various actions (e.g. `plan.created`, `plan.updated`, `plan.approved`, `plan.task.completed`), don't construct `new AppendEventAction(new EventData(...))` in every action — that's 15 lines repeated per call site. Use the `EmitsLedgerEventsForEntity` trait on the model:

```php
class Plan extends BaseModel
{
    use EmitsLedgerEventsForEntity;
    // ...
}

// Then emit in one line:
$plan->emitLedgerEvent('plan.created', payload: [...]);
```

The trait pulls `app`/`company` from the model's KanvasModelTrait relations, sets `source_entity_type`/`source_entity_id` from the model itself, and resolves a default actor from `users_id`/`agent_id` columns. Override `resolveDefaultActorType()` / `resolveDefaultActorId()` per model when defaults aren't right (Tasks delegate to their parent plan; Grant pivots use `granted_by_users_id`).

Use the explicit `AppendEventAction` form **only** when there's no entity to attach to (e.g. system events with `actorType='System'`, no `source_entity_*`).

### GraphQL Query Naming
Check existing query names in `graphql/schemas/` before naming yours to avoid Lighthouse "Duplicate definition" merge errors.

### GraphQL Relation Directives — Always Name the Method
When exposing an Eloquent relation in GraphQL, **always pass `relation:` (for `@hasMany`/`@hasOne`/`@belongsTo`/`@belongsToMany`) or `method:` (for `@method`) explicitly**, even if the field name already matches the relation method. Do not rely on Lighthouse's implicit field-name → method-name inference — it breaks as soon as the field is renamed or aliased, and makes it harder to grep for relation usage.

```graphql
# WRONG — relies on implicit field-name → method-name inference
type Filesystem {
    settings: [FilesystemSettings!]! @hasMany
}

# CORRECT — method name is explicit
type Filesystem {
    settings: [FilesystemSettings!]! @hasMany(relation: "settings")
}
```

Same rule for `@belongsTo(relation: "company")`, `@hasOne(relation: "primaryAddress")`, `@belongsToMany(relation: "roles")`, and `@method(name: "createdAt")`.

### Code Style
- **No section separator comments** — do not add `// --- SectionName ---`, `# --- SectionName ---`, or similar decorative dividers in code, tests, or schema files. Test methods and code sections are self-documenting by their names. If a file grows too large, split it into separate files instead.
- **Comment why, not what.** Class/method docblocks and inline comments exist to capture decisions a reader can't recover from the code itself — gotchas, "why this weird approach", invariants, external constraints. Code that's self-evident from the names + body should carry no doc. If you find yourself paraphrasing the signature ("Fetch the X from Y" on a method called `fetchXFromY`), delete the comment and let the names do the work. The first design instinct should be to make the code clear enough not to need a comment; reach for the comment only when the design can't be simplified further.
  - **Keep** comments that explain: non-obvious ordering ("OpenClaw rows first so OpenClaw wins on tie"), external-system quirks ("Node prints warnings ahead of JSON, strip before decode"), cache TTL rationale, the "why" behind an interface (cross-runtime adoption is on the *target* not the source), schema/input shapes contributors must conform to.
  - **Delete** comments that restate code: `// Loop over the items`, `// Set the status to running`, `// Fetch logs via SSH`, class docblocks that describe what the class name already says, method docblocks that paraphrase the signature.

## Queue Workers

When a queued job sets `$this->onQueue('xxx')` (or is dispatched to a non-default queue), **a worker process must be configured to consume that queue**. Otherwise jobs pile up in Redis untouched.

**Three docker-compose files to update — all of them**:

| File | Used by |
|---|---|
| `docker-compose.yml` | Local dev (the canonical plain compose) |
| `docker-compose.development.yml` | Shared development/staging environment (replicas, no `container_name`) |
| `docker-compose.1.x.yml` | 1.x deployment target |

Each file has the same queue-service shape (with minor differences — `.development.yml` omits `container_name` because of replicas; the others include it). Add the new worker to all three:

```yaml
xxx-queue:
    <<: *common-queue-settings
    container_name: xxx-queue   # omit in docker-compose.development.yml
    command:
        - "sh"
        - "-c"
        - "php artisan config:cache && php artisan queue:work --queue=xxx --tries=3 --timeout=3750"
```

Helm (`helm/templates/`) is currently dormant — don't update it.

**Default to a dedicated service per queue**, not appending to an existing worker's queue list. Any queue handling its own volume class (events, audits, large payloads) will starve or be starved by mixed workloads. Isolating each queue type to its own service gives it its own retry/timeout/replica budget. Reserve the "append to default" shortcut for genuinely low-volume queues (a few jobs/hour) where a dedicated service would be wasteful.

### Adding a New Domain Namespace
When creating a new top-level domain folder (`src/Domains/YourDomain/`):

1. Register PSR-4 in `composer.json`:
   ```json
   "Kanvas\\YourDomain\\": "src/Domains/YourDomain"
   ```
2. Run `composer dump-autoload --no-scripts` (the `--no-scripts` flag avoids the `package:discover` step which can fail if any new class has unresolved deps mid-creation)
3. If Octane is running, also delete `bootstrap/cache/services.php` and `bootstrap/cache/packages.php` and restart the `phpkanvas-ecosystem` container so it re-discovers classes

Forgetting step 1 → "Trait/Class not found" at runtime even though the file exists. Forgetting step 3 under Octane → stale class cache breaks the container's boot loop.

## Testing

For docker test commands, suites, `RefreshDatabase` ban, AppKey-guarded patterns, and Bouncer setup, see `tests/CLAUDE.md` (loads automatically when work touches `tests/`).

### Tests are part of "done"

Code without tests is not done. Code where the relevant test suite has not been run green is not done either. Both are non-negotiable for:

- A new Action / Service / Observer / Job
- A new GraphQL mutation or non-trivial query resolver
- A new model behavior (scope, computed accessor, lifecycle hook, custom relation)
- A migration that does any data transformation beyond `ADD COLUMN` (backfills, joins, computed values)
- A bug fix — write a regression test that fails before the fix and passes after

**Workflow:** write the code, write the test in the same change set, run the relevant suite, fix anything broken, *then* declare done. Do not ask "want tests?" — just write them. Smoke-testing in `php artisan tinker` is not a test (no CI, no regression protection, no documented intent).

**Exceptions** — work that genuinely needs no tests:
- Pure migrations that only add/drop a column with no data transform
- GraphQL schema-only changes (new field that's a direct relation passthrough through `@belongsTo` / `@hasMany`)
- Doc / copy / typo fixes
- Work the user has explicitly tagged "spike" or "experimental"

If you ship code that should have tests and don't, the next step in the conversation is to write them — not to move on to a new feature.
