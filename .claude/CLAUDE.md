# Kanvas Ecosystem API - Development Guide

Guidelines for working with the Kanvas Ecosystem API codebase.

## Architecture Overview

- **Multi-database**: Each domain has its own database connection (`action_engine`, `crm`, `inventory`, `social`, `ecosystem`)
- **Domain-driven design**: Code is organized by domain under `src/Domains/{DomainName}/`
- **GraphQL API**: Uses Lighthouse PHP framework with schema files in `graphql/schemas/`
- **PHP 8.4**: Use modern syntax (e.g., `new Foo(...)->execute()` not `(new Foo(...))->execute()`)
- **Method formatting — the rule of 4**: When a method **call** or **signature** has **4 or more** arguments/parameters, format vertically with one per line. 3 or fewer stays inline. Applies to our own methods; native functions (`str_replace`, `preg_replace`, ...) stay inline regardless.
  ```php
  // 3 or fewer — inline is fine
  $this->doSomething($a, $b);
  $this->fetchPeopleCandidates($app, $company, $terms);

  // 4+ — always vertical, calls and signatures alike
  $this->uploadImageToEntity(
      $company,
      app(Apps::class),
      auth()->user(),
      $request['file'],
      'photo'
  );

  protected function assembleBulkResults(
      array $terms,
      array $candidates,
      int $maxMatches,
      callable $present
  ): array {
  ```
- **Use PHP 8+ named arguments to skip optional positional `null`s.** When you would otherwise pass `null` for an optional middle parameter just to reach a later one, switch to named arguments instead. The positional `null` is a readability and refactor-safety footgun (rename a parameter or add a new optional in between, every caller silently breaks).
  ```php
  // WRONG — null in the middle just to reach $user
  $handler->setConfiguration(
      $this->agent,
      $this->message->entity()->people,
      null,
      $this->message->company->getAiAgentUserOrFail(),
  );

  // CORRECT — named args, skip the optional you don't need
  $handler->setConfiguration(
      agent: $this->agent,
      entity: $this->message->entity()->people,
      user: $this->message->company->getAiAgentUserOrFail(),
  );
  ```
  Apply the same rule to LLM-tool param defaults: prefer `?type $param = null` over `type $param = 'default'` so the LLM can pass explicit `null` without a TypeError (see [no-non-nullable-defaults rule](#)). Normalize the default inside the body: `$param ?? 'default'`.
- **PHP-CS-Fixer enforced** — config lives at `.php-cs-fixer.php`. A `PostToolUse` hook runs the fixer on every edited `.php` file automatically (see `.claude/settings.json`). **The hook only fires on Edit/Write tool calls.** If you batch-edit via `sed`/`perl -i`/`awk`/any shell script, the hook does NOT run and StyleCI will fail the PR for import ordering, brace placement, etc. After any bash batch edit of `.php` files: run `php-cs-fixer fix <files>` (binary at `/Users/kaioken/Tools/php-cs-fixer/vendor/bin/php-cs-fixer` on host) on every touched file BEFORE finishing the task. Prefer Edit/Write tool calls over batch shell edits when possible. If the binary isn't installed in the current environment, match the rules by hand:
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
- **`kanvas-backend-review`** — Pre-PR cleanup pass: duplicated methods, overly complex logic, unnecessary comments, and violations of the conventions on this page (rule of 4, named arguments, no inline FQCNs, `overwriteAppService`). Fixes what it finds and reports the diff. Use before pushing, or when asked to review/clean up backend code.

Sub-directory `CLAUDE.md` files load additively when work touches their tree:
- `tests/CLAUDE.md` — Docker test commands, `RefreshDatabase` ban, Bouncer setup, AppKey-guarded test pattern.
- `src/Domains/Connectors/CLAUDE.md` — connector-tree-specific gotchas (Octane SDK rule, Activities/ folder, AgentRuntime caveat).
- `graphql/schemas/CLAUDE.md` — directive conventions, FK-id-vs-relation rule, schema folder rule.
- `src/Domains/Intelligence/FollowUp/CLAUDE.md` — generic-core vs per-entity-executor split for the agent-driven follow-up engine. Recipe for adopting follow-up on a new entity (Deal, Order, etc.).
- `src/Domains/Guild/Leads/CLAUDE.md` — receiver → lead → email flow: why the email template comes from the **rotation config** (not the job/receiver), the `user-`/`lead-` template-name prefixing, the `notification_mode`/`notification_user_mode` knobs, and how company onboarding differs from the `kanvas:sa-setup-receivers` default.
- `src/Domains/Inventory/CLAUDE.md` — product search engine (dynamic per-tenant Algolia/Typesense/Meilisearch resolution + precedence), index naming, `shouldBeSearchable` gating, the tenant-aware reindex command, and Typesense Natural Language Search config for the recommendation agent.
- `app/Console/Commands/Inventory/CLAUDE.md` — what each inventory command does and the order the discovery ones must run in (enrich → index → search → score).
- `src/Domains/Insurance/CLAUDE.md` — provider-agnostic insurance layer (quote → policy). Why it is a top-level domain rather than Souk/Inventory/a connector, why only 2 direct queries exist and everything else is a workflow activity, and the hybrid generic-vs-connector custom-field split.

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

### Don't Pass a Model AND Its Own Relationships

When an action/service already receives an entity, **do not also pass references that entity can
reach through its own relationships.** An `Agent` carries `->app`, `->company`, and `->user`; a
`Lead` carries `->app`, `->company`, `->user`, `->people`; most `KanvasModelTrait` models expose
`->app` / `->company` / `->user`. Passing them alongside the model is redundant, and worse, it lets a
caller hand in an app/company that disagrees with the model's own — a silent tenant-mismatch bug.

**This only applies when the model actually has the relationship.** Verify it exists (a real
`app()` / `company()` / `user()` relation or accessor on that model) before deriving — some models
legitimately lack one (a global `apps_id=0` catalog row has no single company; an append-only event
may carry no user). If the relationship isn't there, passing the reference separately is correct, not
a violation. Derive what the model exposes; pass what it doesn't.

```php
// WRONG — app/company/user are all reachable from $agent
new ConnectSlackAgentAction(
    agent: $agent,
    app: $app,
    company: $company,
    user: $user,
    botToken: $token,
)->execute();

public function __construct(
    private readonly Agent $agent,
    private readonly Apps $app,          // $agent->app
    private readonly Companies $company, // $agent->company
    private readonly Users $user,        // $agent->user
) {}

// CORRECT — the entity is the single source of truth; derive the rest
new ConnectSlackAgentAction(agent: $agent, botToken: $token)->execute();

public function __construct(
    private readonly Agent $agent,
) {}

private function doWork(): void
{
    $app = $this->agent->app;
    $company = $this->agent->company;
    $user = $this->agent->user;   // the agent's own user, not "whoever called this"
}
```

**The only legitimate reason to pass app/company separately is to *look the entity up*** — a mutation
resolver needs `app` + `company` for `Model::getByIdFromCompanyApp($id, $company, $app)` because it
doesn't have the model yet. Once you hold the model, stop passing them. Same rule for **DTOs**: a DTO
that holds an `Agent` should not also declare `app`/`company` properties.

Corollary for "actor" params: when the entity implies who acts (an agent's `->user`, a lead's
`->user`), derive it from the entity rather than threading the request's auth user through — the
former is the semantically correct actor and can't drift from the entity's tenant.

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

**Call via Spatie's `::from()` magic, NOT `::fromMultiple()` directly.**

Spatie Data's `BaseData::from(mixed ...$payloads)` is the public factory. When you call `Plan::from($app, $user, $company, $request['input'])`, Spatie's creation pipeline inspects the runtime types of the args and routes to whichever `from*` magic method on the class has a matching signature. Our `fromMultiple` method is one of those magic methods — naming it `fromMultiple` makes it the canonical entry for the "multiple typed-objects + data array" shape.

**Exact routing rules** (verified in `Spatie\LaravelData\Resolvers\DataFromSomethingResolver::createFromCustomCreationMethod` + `Spatie\LaravelData\Support\DataMethod::accepts`):

1. Method must be `public static`, name must start with `from`, return type must be `self` / `static` / the class itself (anything else is skipped as a candidate).
2. Spatie iterates the candidates and calls `accepts(...$payloads)` on each — that returns true when arg count ≤ required param count AND every runtime arg's type satisfies the declared parameter type (or the parameter has a default).
3. **First matching method wins** in declaration order. Iteration stops.
4. The matched method is invoked as `$class::$methodName(...$payloads)`.

So:
- The name `fromMultiple` carries no special meaning — `fromRequest`, `fromLead`, `fromImport`, etc. all work the same. A DTO can declare several `from*` methods with different signatures and Spatie routes each `::from(...)` call to the right one by parameter types.
- You only call `Foo::fromMultiple(...)` directly when you specifically want to bypass the Spatie router (which also skips pre-pipeline normalization/casting). For normal use, `Foo::from(...)` is the entry point and the router does the dispatch.
- Spatie's iteration order = PHP's class method declaration order. If two `from*` methods could both accept the same args, the one declared **earlier** wins — keep their signatures disjoint to avoid silent routing surprises.

**Do NOT try to rename `fromMultiple` to `from`.** PHP will fatal at class load because Spatie's parent signature `from(mixed ...$payloads): static` cannot be narrowed to a fixed typed signature (LSP violation: child can't have more required params than parent, and can't narrow variadic mixed to specific types).

**Do NOT call `Plan::fromMultiple(...)` directly from outside the class** — use `Plan::from(...)`. Spatie's pipeline handles the routing. Direct calls work but bypass any Spatie-side normalization/casting and are non-idiomatic.

`forUpdate` is NOT a Spatie magic method (magic methods are `from*` prefixed, not `for*`). Call it directly: `Plan::forUpdate($existing, $app, $user, $data)`.

**Resolve the acting context with the `ResolvesActingContext` trait — never re-derive app/user/company by hand.**

Every mutation resolver needs the same three things: the current app, the logged-in user, and their
current company. Do **not** copy-paste `app(Apps::class)` / `auth()->user()` / `$user->getCurrentCompany()`
into each method — use the shared trait [`App\GraphQL\Concerns\ResolvesActingContext`](../app/GraphQL/Concerns/ResolvesActingContext.php).
It exposes `actingContext(): ActingContext` (a readonly `{ user, app, company }` value object) plus
`normalizeDate()` for Date-scalar inputs. The context's `app` is a concrete `Apps`; `company` is a
`CompanyInterface` — so type your DTO's context params as `AppInterface` / `CompanyInterface` (matching
`Plan`/`Project`) and everything flows without casts.

```php
use App\GraphQL\Concerns\ResolvesActingContext;

class PlanMutation
{
    use ResolvesActingContext;

    public function create(mixed $rootValue, array $request): Plan
    {
        $ctx = $this->actingContext();

        return new CreatePlanAction(
            PlanData::from($ctx->app, $ctx->user, $ctx->company, $request['input']),
        )->execute();
    }

    public function update(mixed $rootValue, array $request): Plan
    {
        $ctx = $this->actingContext();

        /** @var Plan $plan */
        $plan = Plan::getByIdFromCompanyApp((int) $request['id'], $ctx->company, $ctx->app);

        return new UpdatePlanAction(
            $plan,
            PlanData::forUpdate($plan, $ctx->app, $ctx->company, $request['input']),
        )->execute();
    }
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

**Always declare cascades for owned children.** When you add a model that owns child rows via `HasMany` / `HasOne` (and the children have no other parent — i.e. they'd be orphaned and invalid if the parent is gone), wire the cascade at model-creation time. Skipping this leaks orphan rows that break non-null GraphQL relations later — e.g. `AgentSwarmMember.agent: AgentAi!` crashed with `InvariantViolation` because soft-deleted `Agent` rows left dangling membership rows (Sentry KANVAS-ECOSYSTEM-5GS).

Rule of thumb — add the child relation to `$cascadeDeletes` when:
- The child's FK points only at this parent (no shared ownership / no multi-tenant pivot).
- A surviving child row without the parent would be semantically invalid (orphan task without plan, sleep phase without daily cycle, agent-skill grant without skill).
- The GraphQL type for the child exposes a non-null `@belongsTo` back to the parent — orphans will fatal the resolver.

Skip the cascade when:
- The child is shared (pivot rows where deleting one side shouldn't kill the row; the *other* side's cascade handles it — e.g. `AgentSwarm.members` cascades, so `Agent.swarmMemberships` covers the agent side and the pivot is fully owned by whichever ends first).
- The child is append-only and intentionally outlives the parent (ledger events, audit logs, daily metric snapshots).
- The child has its own independent lifecycle (e.g. `Plan.agent` — agent isn't cascade-deleted when a plan is deleted; only the reverse holds for owned children of the agent).

When in doubt, check whether the GraphQL schema marks the back-relation as non-null. If it does, you need the cascade.

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

### Workflow Activities — Don't Throw/Report Expected Skips; Return `failWorkflow`

A `KanvasActivity` (anything running through `executeIntegration`) MUST NOT throw an exception or `report()` to Sentry for an **expected business condition** — an empty inbound message, "AI mode off", "already responded", "nothing to sync", a record that legitimately isn't there. Those aren't faults; they're normal control-flow outcomes. `executeIntegration`'s `catch` calls `report($exception)`, so letting an expected skip bubble up floods Sentry with non-actionable noise (real incident: `Message has no content...` threw a `ValidationException` 457×, KANVAS-ECOSYSTEM-5XF).

**Reserve throwing / `report()` for genuinely system-critical faults** — a DB write that failed, a downstream API that errored unexpectedly, a corrupt-state invariant. Those you *want* in Sentry.

For an expected skip, catch it inside the `integrationOperation` closure and **return `$this->failWorkflow([...])`** instead. `failWorkflow` sets the activity's status to `FAILED` and returns your message array — so it's flagged FAILED in the workflow/integration-history UI for humans and future agents to see, *without* an exception or a Sentry report.

```php
// WRONG — expected skip bubbles to executeIntegration's report() → Sentry flood
$reply = new InternalAgentChannelResponderAction($agent, $message, $entity)->execute();

// CORRECT — catch the expected condition, flag FAILED in the UI, no Sentry
try {
    $reply = new InternalAgentChannelResponderAction($agent, $message, $entity)->execute();
} catch (ValidationException $e) {
    return $this->failWorkflow([
        'message' => $e->getMessage(),
        'entity' => null,
    ]);
}
```

A plain non-fail early `return ['message' => ..., 'entity' => null]` (status stays CONNECTED) is fine for a benign no-op that isn't even worth flagging (e.g. "message is from the agent side, skipping"). Use `failWorkflow` when you want it visibly marked FAILED; use a plain return for a silent skip. Either way — **not** an uncaught throw.

`SilentWorkflowException` (records FAILED, skips `report()`) still exists for the case where the skip signal must originate *deep* in a call stack you don't want to unwind by hand — but prefer the local `try/catch → failWorkflow` in the activity when the throw site is one call away.

### Broadcast Channel Names Built From User Data Must Be Sanitized

Pusher rejects a channel name containing anything outside `[A-Za-z0-9_\-=@,.;]`, or longer than 164
chars, with `PusherException: Invalid channel name` — thrown at broadcast time, inside the queue
worker or mid-request. A name built purely from ids is always safe; one that interpolates a **slug,
email, or any other user-controlled string** is not. `Str::sanitizeEmail()` only rewrites `@` and `.`,
so a plus-addressed sender (`ap+caf_=x@example.com`) reaches the broadcaster with its `+` intact.

```php
use Baka\Support\Str;

// WRONG — an email-derived channel slug throws at broadcast time
new Channel('app-' . $channel->apps_id . '-new-message-channel-' . $channel->slug);

// CORRECT
new Channel(Str::sanitizeChannelName('app-' . $channel->apps_id . '-new-message-channel-' . $channel->slug));
```

Sanitize where the name is **assembled**, not at the broadcast — when a helper hands the same name to
clients (`AgentChatBroadcastChannel::nameFor()` feeds both `broadcastOn()` and the `broadcast_channel`
the `userChat` mutation returns), sanitizing anywhere else desyncs publisher from subscriber. Never fix
this in the slug generator itself: channel slugs are dedup keys for Sessions and Social channels, so
changing them forks every existing conversation.

### Code Style
- **No section separator comments** — do not add `// --- SectionName ---`, `# --- SectionName ---`, or similar decorative dividers in code, tests, or schema files. Test methods and code sections are self-documenting by their names. If a file grows too large, split it into separate files instead.
- **Comment why, not what.** Class/method docblocks and inline comments exist to capture decisions a reader can't recover from the code itself — gotchas, "why this weird approach", invariants, external constraints. Code that's self-evident from the names + body should carry no doc. If you find yourself paraphrasing the signature ("Fetch the X from Y" on a method called `fetchXFromY`), delete the comment and let the names do the work. The first design instinct should be to make the code clear enough not to need a comment; reach for the comment only when the design can't be simplified further.
  - **Litmus test: if the code is easy to understand or straightforward, do NOT comment it.** Before writing any comment ask "would a future reader be confused without this?" — if no, delete it. A loop guard, a `??=`, a self-evident `collectXUrls()` need no prose. Default to zero comments; let a comment earn its place. (A blunt one Max repeats: "if the shit is easy to understand or straightforward, why do we have comments?")
  - **Keep** comments that explain: non-obvious ordering ("OpenClaw rows first so OpenClaw wins on tie"), external-system quirks ("Node prints warnings ahead of JSON, strip before decode"), cache TTL rationale, the "why" behind an interface (cross-runtime adoption is on the *target* not the source), schema/input shapes contributors must conform to.
  - **Delete** comments that restate code: `// Loop over the items`, `// Set the status to running`, `// Fetch logs via SSH`, class docblocks that describe what the class name already says, method docblocks that paraphrase the signature.

### Never Fetch a Remote URL Without the SSRF Guard

Any server-side fetch of a URL that could be influenced by a user (GraphQL input, a DB field set from input, a webhook/message payload, a company logo, an agent image, an attachment) MUST go through the SSRF guard in `Baka\Http`. Never call `file_get_contents($url)`, `Http::get($url)`, `curl_exec`, etc. directly on such a URL.

```php
use Baka\Http\SafeUrlFetcher;

// Fetches with scheme allow-list + private/reserved-IP block + DNS-rebind pinning
// + redirect re-validation + timeout + a hard byte cap (config/ssrf.php). Returns the body.
$bytes = SafeUrlFetcher::fetch($url);
```

When you can't use the fetcher's transport (you keep Laravel's `Http`/Guzzle, or a `stream_context` Range read), gate it first:

```php
use Baka\Http\SafeUrl;

SafeUrl::assertSafe($url);          // throws SsrfException on a disallowed scheme / private host
$response = Http::timeout(30)->get($url);
```

Why: a raw fetch on a user URL lets an attacker hit `http://169.254.169.254` (cloud-metadata → IAM creds), internal hosts, or `file://` — and unbounded reads are a memory-DoS. The guard rejects loopback/RFC1918/link-local/CGNAT/reserved ranges **and** the IPv6 transition forms that smuggle a private IPv4 (NAT64 `64:ff9b::/96`, 6to4 `2002::/16`, Teredo `2001::/32`, IPv4-compatible `::/96` — the class behind Symfony CVE-2026-48736).

- **Single chokepoint for image downloads:** `FilesystemServices::downloadImageFromUrl()` is already guarded — route new image/file downloads through it (or the `ImageOptimizerService` / `ImageConversionService` helpers that call it) and you inherit the protection.
- **Enforced by a coverage test:** [`tests/Baka/Unit/NoUnguardedUrlFetchTest.php`](../tests/Baka/Unit/NoUnguardedUrlFetchTest.php) fails CI if a file under `src/`/`app/` introduces a raw `file_get_contents($var)` / `curl_exec` without referencing the guard. A genuinely-local read (local file, hardcoded endpoint, admin-config CLI) goes in that test's allow-list with a one-line justification — never silence it by other means.

### Uploaded Image Handling — Trust Magic Bytes, and the ImageMagick Policy

Two coupled rules for anything that accepts or decodes uploaded images:

1. **Derive an image's stored `file_type` from magic bytes, never the client filename.** `CreateFilesystemAction::resolveFileType()` runs `finfo` on the real upload and, for `image/*`, stores the content-derived extension. This is what stops a file named `evil.heic` but carrying crafted TIFF/MVG bytes from steering the decoder (e.g. `ConvertHeicToJpgActivity` branches on `file_type`). When you validate an **image-only** upload, gate on `$file->extension()` (Symfony's magic-byte guess), not `getClientOriginalExtension()`. Do NOT apply magic-byte validation to the `WORK_FILES` allow-list — `.docx`/`.xlsx` are ZIP containers that finfo reports as `application/zip`, so client-extension validation stays for those.

2. **ImageMagick is hardened by [`docker/imagemagick-policy.xml`](../docker/imagemagick-policy.xml)** (installed into `/etc/ImageMagick-{7,6}/policy.xml` by both Dockerfiles). It disables the RCE/SSRF coders+delegates the app never uses (MSL, MVG, URL/HTTP(S)/FTP, PS/PDF/EPS, the Ghostscript delegate, SVG) and caps resources against decompression bombs. The image coders the app *does* convert (JPEG, PNG, WEBP, GIF, HEIC, HEIF, AVIF, TIFF, BMP) stay enabled. **If you add support for a new image format, whitelist its coder here or conversions will fail with "not authorized by the security policy".** Verify a policy change against the live `imagick` extension (`new Imagick(...)`), not the `convert` CLI — the CLI isn't installed in the image.

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

### Every job that operates on a specific app MUST call `overwriteAppService($this->app)` first

Any queued job that has an `Apps` model in its constructor — and any code it runs touches `Role`/`Ability`/Bouncer-scoped models (which includes most channel/agent/permission paths) — MUST start `handle()` with `$this->overwriteAppService($this->app);`. The trait lives in `Baka\Traits\KanvasJobsTrait`.

```php
use Baka\Traits\KanvasJobsTrait;

final class MyAppScopedJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use KanvasJobsTrait;    // ← required
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly Apps $app, /* ... */) {}

    public function handle(): void
    {
        $this->overwriteAppService($this->app);   // ← FIRST LINE of handle()
        // ... rest of the job
    }
}
```

**Why** — the queue worker is a long-running PHP process. Bouncer's `Scopable` trait auto-appends `WHERE scope = <process-current scope>` to every Role/Ability query. The container-bound `app(Apps::class)` is similarly process-global. Whatever the previous job (or kernel boot) set leaks into the next job. Without `overwriteAppService`, a job for app 141 might query Roles with the previous job's scope (`app_1_company_0`) auto-applied — explicit `where('scope', 'app_141_company_0')` AND the leaked auto-scope combine into an impossible match, throwing `ModelNotFoundException: Role` deep inside `CreateChannelAction` / `RolesRepository::getByNameFromCompany`. The error usually surfaces nowhere near the actual job code because it's inside agent/channel internals.

**Symptoms of the bug** (when you forget this):
- `ModelNotFoundException: No query results for model [Kanvas\AccessControlList\Models\Role]` inside `CreateChannelAction` or anywhere that calls `RolesRepository::getByNameFromCompany`
- Random "works on local but fails in production" because dev workers might run one job at a time while prod workers handle many
- Agent flows returning generic fallback strings (e.g. `RunNeuronChatAction`'s "I ran into a hiccup processing that...") because the actual exception was swallowed and a friendly fallback was substituted
- Cross-tenant data writes — Bouncer scope from app A bleeding into queries that "should" return app B data

**Same rule applies to commands — and the bar for commands is blunt, no judgment call: EVERY command, anywhere under `app/Console/Commands/` (every domain, no exceptions), that resolves or operates on a specific `Apps` MUST `use Baka\Traits\KanvasJobsTrait` and call `$this->overwriteAppService($app)`** — once after resolving the app (single-app commands), or per-iteration inside the loop (multi-app commands), before doing any work. This is not scoped to NervousSystem/Intelligence; it holds for AccessControl, Souk, Social, Connectors, Ecosystem, and any future domain. Don't reason about "but this one only writes a custom field / only emits a ledger event, so it's probably fine" — if it touches an app, add the call. The cost is one line; the failure mode (silent cross-tenant scope leak that drops 90% of tenants and is invisible until someone notices missing emails weeks later) is not worth the judgment call. The ONLY commands that skip it are ones with no concrete app in scope at all: global `apps_id=0` catalog syncs, row-chunk backfills with no `Apps` model, ledger archive/maintenance, and pure queue dispatchers (the dispatched job rebinds in its own `handle()`).

The trap is that the scoped work that breaks is often **one layer down**, inside an Action/Service the command runs *inline* — a Bouncer-scoped `Role::firstOrFail()` (`RolesRepository::getByNameFromCompany`, `UsersRepository::getCompanyAppUserByRole`, `Bouncer::assign`, `CreateChannelAction`) resolves against the leaked `app_1_company_0` scope, throws `ModelNotFoundException`, and a well-meaning `try/catch` in the Action swallows it into a "no results" return — so the command exits `0` having silently done nothing. Canonical fixed example: [`EnsureAgentReportRoleCommand`](app/Console/Commands/NervousSystem/EnsureAgentReportRoleCommand.php). Real incident: [`SendDailyLearningDigestCommand`](app/Console/Commands/NervousSystem/SendDailyLearningDigestCommand.php) fanned the daily-learning digest over ~90 (app, company) tuples without rebinding scope → every non-app-1 tenant silently got zero emails for weeks while roles, assignments and recipients were all correct. Regression test: `tests/Intelligence/NervousSystem/SendDailyLearningDigestCommandTest.php`. See [`feedback_overwrite_app_service_when_iterating_apps`](.claude/projects/-Users-kaioken-Code-kanvas-kanvas-ecosystem-api/memory/feedback_overwrite_app_service_when_iterating_apps.md). Jobs → `overwriteAppService($this->app)` once at the top of `handle()`.

If your job operates on a Company without an Apps reference, you still need this if it triggers anything that resolves the app via the container or Bouncer scope. Easiest fix: take `Apps` on the constructor.

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
