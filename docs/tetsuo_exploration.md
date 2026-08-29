# Tetsuo Exploration Guide — Backend & Console/CLI Architecture

> A field guide to how the Kanvas Ecosystem API is put together on the backend,
> how its Artisan console layer is organized, and how to run/diagnose the
> system from the command line. Written from a full pass over `app/`, `src/`,
> `graphql/`, `config/`, `routes/console.php`, `composer.json`, and the
> repository's own `CLAUDE.md` convention files.

## Table of Contents

1. [Executive Summary](#1-executive-summary)
2. [Repository Layout](#2-repository-layout)
3. [Console/CLI Architecture](#3-consolecli-architecture)
4. [Command Discovery](#4-command-discovery)
5. [Scheduled Task Map](#5-scheduled-task-map)
6. [Architectural Patterns](#6-architectural-patterns)
7. [Environment Setup & Diagnostics](#7-environment-setup--diagnostics)
8. [Appendix: Full Command Catalog](#8-appendix-full-command-catalog)

---

## 1. Executive Summary

Kanvas Ecosystem API is a **Laravel 13 + GraphQL (Lighthouse) monolith** built
as a domain-driven "operational nervous system" — a single backend that unifies
authentication/multi-tenancy (Ecosystem), inventory, CRM (Guild), commerce
(Souk), social, workflow orchestration, AI agents (Intelligence /
NervousSystem), accounting (Scribe), and 40+ third-party connectors, each
behind its own database connection.

The console layer is not an afterthought bolted onto a web app — it is a
**first-class operational surface**. As of this pass there are **308 Artisan
commands** across **19 top-level domains** (and dozens of connector
sub-namespaces), plus 4 domain-grouped `Schedule` registrar classes that keep
`app/Console/Kernel.php` from becoming an unreadable list. Every long-running
/ scheduled / data-migration operation in the system is expressed as an
Artisan command, which makes the console tree simultaneously:

- The **operational runbook** (setup, backfills, health checks, reports).
- The **integration surface** for connectors that don't yet have (or don't
  need) a GraphQL mutation.
- The **scheduler's vocabulary** — every cron-like job in
  `Kernel::schedule()` is a command or a job, never an inline closure.

## 2. Repository Layout

```
app/                        Laravel "glue" layer — HTTP, GraphQL resolvers, Console commands, Providers
  Console/Commands/         308 Artisan commands, one directory per domain (see §4)
  GraphQL/                  Lighthouse resolvers (Mutations/Queries), one directory per domain
  Http/                     Controllers + Middleware (thin; most reads/writes go through GraphQL)
  Providers/                Service providers (Health, Search, Cart, Payment, Insurance, Kanvas Apps context)

src/
  Baka/                     Shared, domain-agnostic framework code (traits, casts, Discovery, Support, Users)
  Kanvas/                   The "ecosystem" core domain — Apps, Companies, Users, Roles, ACL, Filesystem,
                             Regions, Currencies, Notifications, SystemModules, Health checks, Sessions...
  Domains/                  Business domains, each independently namespaced & DB-connected (see below)
    Inventory/  Social/  Guild/  Souk/  Workflow/  ActionEngine/  Event/
    Intelligence/  NervousSystem/  Scribe/  Analytics/  HumanResources/
    Insurance/  Subscription/  ContentEngine/  Connectors/ (40+ third-party integrations)

graphql/
  schema.graphql             Root schema — scalars + `#import` directives pulling in every domain's schema
  schemas/{Domain}/*.graphql One schema file (or folder) per domain, following the same domain boundaries

config/database.php          12 named DB connections — one per domain (see §6.1)
routes/console.php           Closure-based commands (kept intentionally tiny; almost nothing lives here)
database/migrations/{Domain}/  Per-domain migration folders, matched 1:1 with composer's `migrate-*` scripts
```

The `App\` namespace (composer autoload) is Laravel's framework-facing shell:
Console commands, GraphQL resolvers, HTTP controllers, and Providers. The
actual domain logic — models, DTOs, Actions, jobs, enums — lives under
`Kanvas\*` namespaces mapped to `src/Kanvas` and `src/Domains/*` in
`composer.json`'s `autoload.psr-4` block. A console command's `handle()`
method is therefore almost always a thin orchestrator: resolve models from
IDs, call an Action/Setup class from `src/`, print a result. This mirrors the
GraphQL resolver pattern exactly (see the `ResolvesActingContext` +
`fromMultiple` DTO conventions in `.claude/CLAUDE.md`), so commands and
mutations that do the same job typically call into the *same* Action class.

## 3. Console/CLI Architecture

### 3.1 Registration entrypoint: `app/Console/Kernel.php`

```php
class Kernel extends ConsoleKernel
{
    #[Override]
    protected function schedule(Schedule $schedule) { /* see §5 */ }

    #[Override]
    protected function commands()
    {
        $this->load(__DIR__ . '/Commands');
        require base_path('routes/console.php');
    }
}
```

Two registration paths exist side by side:

1. **`$this->load(__DIR__ . '/Commands')`** — Laravel's directory autoloader.
   It recursively scans `app/Console/Commands/**/*.php`, and any class that
   extends `Illuminate\Console\Command` and defines a `protected $signature`
   is auto-registered with Artisan. **No manual registration list exists** —
   dropping a new `*Command.php` file anywhere under that tree, with a
   `$signature`, is sufficient. This is why the tree can hold 308 commands
   without a single `$commands = [...]` array to maintain.
2. **`routes/console.php`** — for one-off, closure-based commands
   (`Artisan::command('inspire', fn () => ...)`). The codebase deliberately
   keeps this file almost empty; every real command is a class, not a
   closure, so it can be unit-tested and composed (see `KanvasSetupCommand`
   below).

Files under `Commands/**/Schedules/*Schedule.php` and
`Commands/**/Concerns/*.php` are **not** commands themselves (no
`$signature`) — the directory loader still requires/parses them (they must be
valid PHP classes), but Artisan silently skips anything that isn't a
`Command` subclass with a signature. This is how the "Schedules" and
"Concerns" sub-folders coexist with real commands in the same tree without
polluting the CLI's command list.

### 3.2 Tenant-scoping pattern: `KanvasJobsTrait`

Almost every command that operates on a specific `Apps` needs to make that app
the "current" one for the container/queue/ACL for the duration of the run —
Kanvas is multi-tenant, and Bouncer (the ACL library) and the `Apps` binding
are otherwise resolved from the current HTTP request, which doesn't exist in
a CLI process. The fix is `Baka\Traits\KanvasJobsTrait::overwriteAppService()`:

```php
use Baka\Traits\KanvasJobsTrait;

class InventorySetupCommand extends Command
{
    use KanvasJobsTrait;

    public function handle()
    {
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);   // binds Apps + Bouncer scope for this run
        // ... rest of the command now resolves the right tenant everywhere
    }
}
```

`overwriteAppService()` does two things: `App::scoped(Apps::class, fn () =>
$app)` (rebinds the container's current-app singleton) and
`Bouncer::scope()->to(RolesEnums::getScope($app))` (repoints the ACL scope).
Skipping this call in a command that touches tenant data is a documented
foot-gun (see `app/Console/Commands/Inventory/CLAUDE.md`): it leaks whatever
app/ACL scope was left over from a previous run in the same worker.

### 3.3 Composite/orchestrator commands

Some commands don't do work themselves — they **sequence other Artisan
commands**, giving you a single entrypoint for a multi-step operation. The
canonical example is the ecosystem bootstrap command referenced in the
project README:

```php
// app/Console/Commands/KanvasSetupCommand.php — php artisan kanvas:setup-ecosystem
$commands = [
    'migrate',
    'migrate --path database/migrations/Inventory/ --database inventory',
    'migrate --path database/migrations/Social/ --database social',
    'migrate --path database/migrations/Guild/ --database crm',
    // ... one migrate line per domain database ...
    'db:seed',
    'db:seed --class=Database\\Seeders\\GuildSeeder --database crm',
    'kanvas:create-role Admin',
    'kanvas:create-role Users',
    'kanvas:create-role Agents',
    'kanvas:filesystem-setup',
    'kanvas:create-workflow-status',
    'kanvas:update-abilities',
    'kanvas:create-attribute-types',
];

foreach ($commands as $command) {
    $exitCode = Artisan::call($command);
    if ($exitCode !== 0) { $this->error(...); break; }
}
```

It first checks `Schema::hasTable('migration')` and no-ops if the ecosystem is
already migrated, so it's safe to re-run. This "one command calls many
commands via `Artisan::call()`" shape recurs across the `Setup/` namespace
(one `{Domain}SetupCommand` per domain database) and is the recommended
pattern any time an operation is really "run these N commands in order."

### 3.4 Interactive wizards

Not every command takes flags — several are designed to be run interactively
and prompt the operator for input via `$this->ask()` / `$this->choice()`:
`kanvas:app-create` (`KanvasAppSetupCommand`) walks through creating a new
Kanvas App end-to-end (name, description, domain, owner email...);
`agent:create` / `agent-type:create` under `Intelligence/Agents` are wizards
for provisioning AI agents and agent types; `nervous-system:tool-setup` is a
wizard for registering a new agent tool. This keeps rarely-run, high-stakes
setup operations safe (you're prompted for each field) without needing a
bespoke admin UI.

## 4. Command Discovery

### 4.1 How to list commands yourself

```bash
# Full list with descriptions
php artisan list

# Filter to one domain's namespace prefix
php artisan list kanvas-inventory
php artisan list kanvas:nervous-system

# Show usage/arguments/options for one command
php artisan help kanvas:status
```

Because registration is purely directory-scan + `$signature`-based (§3.1),
`php artisan list` is always the ground truth — grepping the source (as this
document does in §8) is a snapshot, `php artisan list` is live.

### 4.2 Directory-to-domain mapping

`app/Console/Commands/` is organized **one top-level folder per business
domain**, mirroring `src/Domains/` and `app/GraphQL/`. Large domains further
subdivide into topic folders. At the time of writing:

| Top-level folder | Commands | What lives there |
|---|---|---|
| `AccessControl/` | 5 | Bouncer roles/abilities — assign roles, grant owner permissions, sync ability templates |
| `Analytics/` | 1 (+ 1 schedule class) | Cross-domain usage reporting (Engage leaderboard) |
| `Connectors/` | 140 across 44 sub-folders | One folder per third-party integration — largest sub-folders: PromptMine (19), Movipass (12), ScrapperApi (10), NetSuite (8), Zoho (6); plus Acumatica, Shopify, Recombee, ESim, Apollo, VinSolution, DealerSocket, DriveCentric, WooCommerce, Google, Mailgun, OpenClaw, Hermes, and 30+ others |
| `Ecosystem/` | 19 total — 7 directly, +2 `Companies/`, +5 `Notifications/`, +5 `Users/` | Core tenancy — apps, system modules, email templates, users, companies, notifications |
| `Event/` | 2 | Booking system — time-slot generation, reminders |
| `Guild/` | 10 | CRM (leads/people/organizations) maintenance — dedup, imports, reporting |
| `Intelligence/` | 26 across 7 sub-folders | AI agents, knowledge indexing, lead AI-mode, follow-up engine, usage rollups |
| `Inventory/` | 16 | Product catalog — discovery/search pipeline, cross-env migration, reporting |
| `Lead/` | 2 (+1 schedule class) | Lead follow-up v2 dispatch + daily summary |
| `Movipass/` | 4 | Movipass-specific backfills (regions, warehouses, unpaid orders) |
| `NervousSystem/` | 26 across 8 sub-folders | Agent runtime health, ledger, learning pipeline, plans, provisioning, tools |
| *(root)* | 6 | `kanvas:setup-ecosystem`, `kanvas:status`, `kanvas:version`, `kanvas:app-create`, `kanvas:import`, `kanvas:app-ecosystem-update` |
| `Scribe/` | 4 (+1 schedule class) | Accounting — AR aging, PDF ingest, vendor approvers |
| `Search/` | 7 total — 6 directly, +1 `Algolia/` | Scout/Typesense/Algolia indexing across domains |
| `Setup/` | 11 | One `{domain}:setup` command per domain database (see §6.1) |
| `Social/` | 8 | Message feeds, reactions, counters |
| `Souk/` | 6 total — 5 directly, +1 `Cart/` | Commerce — order status, stale payments, B2B pricing, abandoned carts |
| `Support/` | 6 | Cross-cutting utilities — daily report, demo data seeding, Lighthouse cache regen |
| `Workflows/` | 9 total — 6 directly, +3 `Integrations/` | Workflow rules, receivers, action-catalog sync |

`Connectors/` alone is nearly half of the entire command tree, which reflects
the "unify fragmented systems" mission from the project README: almost every
external system Kanvas talks to (ERPs, CRMs, marketplaces, AI providers,
notification/comm platforms) gets its own connector folder with setup, sync,
and backfill commands.

### 4.3 Naming convention

Signatures follow a loose but consistent pattern:
`kanvas[-{domain}]:{verb}-{noun}` (e.g. `kanvas-inventory:discover-products`,
`kanvas:nervous-system:sweep-stale-intake`,
`kanvas-guild:recalculate-active-leads-count`). Prefixes seen in the wild:
`kanvas:`, `kanvas-{domain}:`, `kanvas:{domain}:`, `{domain}:` (e.g.
`nervous-system:`, `intelligence:`, `souk:`, `workflow:`, `azul:`), and a
handful of connector-specific short prefixes (`agent:`, `agent-type:`). When
adding a new command, check `php artisan list` for the closest existing
sibling before inventing a new prefix.

### 4.4 Diagnostic commands worth knowing first

| Command | Purpose |
|---|---|
| `kanvas:status` | **The** operational health snapshot — pings every one of the 12 domain DB connections + Redis, reports pending/failed counts for every named queue, and exits non-zero if a DB/Redis connection is down. See §7.3. |
| `kanvas:version` | Prints the running `AppEnums::VERSION` value. |
| `kanvas:setup-ecosystem` | Full ecosystem bootstrap (all migrations + seeders + base roles); no-ops if already migrated. |
| `kanvas-*:setup` (11 commands in `Setup/`) | Per-domain bootstrap for a given app/company (Inventory, Social, Guild, Souk, ActionEngine, Event, Scribe, HR). |
| `nervous-system:tool-setup` | Diagnoses/registers a missing agent tool interactively. |
| `kanvas:nervous-system:check-tool-drift` | Fails the run when the `#[AgentTool]` classes on disk disagree with the DB catalog — a CI-friendly drift check. |
| `kanvas:agent-runtime-check-health` | Probes every agent's runtime deployment and reconciles its awake/dead state. |
| `check_product_discovery_setup` | Not a CLI command — a Neuron *agent tool* invoked from chat, but the fastest way to diagnose the product-discovery pipeline; mentioned here because it's the natural next stop after `kanvas:status`. |

## 5. Scheduled Task Map

`app/Console/Kernel.php::schedule()` is kept intentionally thin. A comment at
the top of the method states the house rule directly:

> Domain-grouped schedules live in `App\Console\Schedules\*` — extract the
> next domain into its own class as soon as its entries hit 3+ here, so this
> method stays a thin dispatcher rather than a god-list.

The Kernel inlines only small (1–2 entry) domains directly, and delegates
anything with 3+ scheduled entries to a dedicated `Schedules\{Domain}Schedule`
class with a single static `register(Schedule $schedule)` method. As of this
pass there are four such registrar classes:

| Schedule class | Domain | Notable entries |
|---|---|---|
| `NervousSystem\Schedules\NervousSystemSchedule` | Agent lifecycle, ledger, learning | 5m: stalled-task sweep, project heartbeat · 10m: runtime health · hourly (staggered `:00`/`:05`/`:10`/`:15`): capability expiry, live counters, transcript/usage collection · daily (NY tz): dashboard/pulse rollups, ledger archive, model-pricing sync, the 06:04→06:30→07:30 daily-learning pipeline, inactive-plan nudge, stale-intake sweep |
| `Lead\Schedules\LeadFollowUpSchedule` | Lead follow-up v2 | Hourly fan-out (`withoutOverlapping(10)`, `onOneServer`, `runInBackground`) + `00:30` daily per-tenant summary |
| `Scribe\Schedules\ScribeSchedule` | Accounting AR aging | Daily fan-out per `(app, company)` tuple with open AR |
| `Analytics\Schedules\AnalyticsSchedule` | Cross-domain reporting | Weekly Engage-usage leaderboard email |

Entries still inlined directly in the Kernel (small enough to stay there per
the house rule): Spatie Health checks (`RunHealthChecksCommand`,
`DispatchQueueCheckJobsCommand` — every minute), account-deletion sweep,
signup-anomaly detection, social counter reset, order-expiry/stale-payment
sweeps for Souk, and the Event module's time-slot rollforward.

**Cross-cutting scheduling conventions** worth copying when adding a new
entry (extracted from the doc-comments inside `NervousSystemSchedule` and
`LeadFollowUpSchedule`):

- **`withoutOverlapping()`** on essentially everything — a slow run should
  never stack a second instance.
- **`onOneServer()`** for anything that must run exactly once across a
  multi-node deployment (most daily jobs; anything that sends an email/digest
  or debits a shared resource).
- **`runInBackground()`** for jobs that dispatch/enqueue rather than block —
  keeps the scheduler process itself from stalling.
- **Explicit `->timezone('America/New_York')`** on daily jobs whose semantics
  are calendar-day-based for US tenants; interval-based jobs (`everyN`,
  `hourlyAt`) stay UTC-anchored since "which hour" doesn't matter to them.
- **Deliberate minute-offsets** (`:05`, `:10`, `:15`, `06:04`, `06:30`,
  `07:30`...) to stagger jobs that would otherwise thunder-herd at `:00`, and
  to leave a buffer between pipeline stages that depend on each other
  finishing (e.g. the daily-learning pipeline's 26-minute and 60-minute
  buffers, documented inline with the throughput math that justifies them).

## 6. Architectural Patterns

This section captures the elegant, repeatable patterns that show up across
the backend — most codified explicitly in the repository's own `CLAUDE.md`
files (`.claude/CLAUDE.md` plus per-subdirectory ones), which double as the
project's architectural style guide.

### 6.1 Multi-database, domain-driven design

Every business domain owns its own MySQL connection, configured in
`config/database.php` and documented in `.claude/CLAUDE.md`:

| Connection | Domain |
|---|---|
| `mysql` / `ecosystem` | Core tenancy — Apps, Companies, Users, ACL, Filesystem, Notifications |
| `inventory` | Products, variants, channels, catalogs |
| `social` | Messages, feeds, reactions |
| `crm` | Guild (leads, people, organizations, pipelines) |
| `content_engine` | Content engine domain |
| `hr` | Human Resources domain |
| `workflow` | Workflow rules/activities + the vendored durable-workflow engine |
| `action_engine` | ActionEngine domain |
| `commerce` | Souk (orders, payments, discounts) |
| `event` | Booking/scheduling domain |
| `intelligence` | AI agents, NervousSystem ledger/plans, knowledge |
| `accounting` | Scribe (AP/AR, invoices, chart of accounts) |

Each domain's `BaseModel` hardcodes its `$connection`, so a query never
accidentally crosses domains — `DB::connection('{name}')->transaction(...)`
is the explicit way to opt into cross-domain writes when genuinely needed.
Domains are also independently namespaced in `composer.json`'s
`autoload.psr-4` (`Kanvas\Inventory\` → `src/Domains/Inventory`,
`Kanvas\Guild\` → `src/Domains/Guild`, etc.), and migrations live in matching
per-domain folders (`database/migrations/{Domain}/`) with a dedicated
`composer migrate-{domain}` script for each (see §7.2).

### 6.2 The `src/` split: `Baka` vs `Kanvas` vs `Domains`

- **`src/Baka/`** — framework-agnostic, domain-agnostic building blocks:
  traits (`KanvasModelTrait`, `UuidTrait`, `SoftDeletesTrait`,
  `KanvasJobsTrait`), casts (`Baka\Casts\Json`), the `Discovery` mechanism
  (see §6.6), generic HTTP/search/validation helpers.
- **`src/Kanvas/`** — the ecosystem *core* domain: Apps, Companies, Users,
  Roles/ACL, Regions, Currencies, Filesystem, Notifications, SystemModules,
  Health checks. This is the one domain every other domain depends on (every
  `BaseModel` in `src/Domains/*` uses `KanvasModelTrait`, which expects
  `app()`/`company()`/`user()` relations resolvable against this core).
- **`src/Domains/`** — the business domains proper, each independently
  namespaced and DB-connected as above.

`app/` is intentionally kept thin — Console commands, GraphQL resolvers, HTTP
controllers, and Providers, almost none of which contain real business logic.
A command's `handle()` and a mutation resolver's method both typically do:
resolve models from IDs/args → build or fetch a DTO → call one Action class
from `src/` → format the result for the caller. This means the *same* Action
class is often reachable from both a CLI command and a GraphQL mutation.

### 6.3 GraphQL via Lighthouse, schema-per-domain

`graphql/schema.graphql` is a thin manifest of scalars plus `#import`
directives pulling in one schema file/folder per domain from
`graphql/schemas/{Domain}/`, mirroring the `app/GraphQL/{Domain}/` resolver
folders and the `src/Domains/{Domain}/` model folders — the same domain
boundary is visible in all four trees (routes, resolvers, schema, models).
Authorization is directive-driven: `@guard` (any authenticated user),
`@guardByAdmin`, `@guardByAppKey` (system/service-to-service), and `@can` for
Bouncer per-model ability checks — see `graphql/schemas/CLAUDE.md` for the
full directive conventions.

### 6.4 DTOs, Actions, and the `fromMultiple` factory convention

Business logic lives in single-purpose **Action** classes
(`CreateXAction`, `UpdateXAction`) that receive a **DTO** (a
`Spatie\LaravelData\Data` subclass) and nothing else — never raw request
arrays, never separate app/company/user params alongside a DTO that already
holds the entity they belong to. Non-trivial DTOs expose a static
`fromMultiple(...)` factory (routed to automatically by Spatie's `::from()`
magic-method dispatch) that does all the model-lookup/enum-casting
assembly in one place instead of repeating it in every resolver/command. See
`.claude/CLAUDE.md`'s "DTO Conventions" section for the full rules, including
the important **queued-job caveat**: never let a `ShouldQueue` job hold a
Spatie `Data` DTO that itself holds Eloquent models — serialize the models
directly on the job and rebuild the DTO inside `handle()`.

### 6.5 Health checks: Spatie Health + a custom queue-depth check

`App\Providers\HealthProvider` registers `Health::checks([...])` with one
`DatabaseCheck` per domain connection (`ecosystem`, `inventory`, `social`,
`crm`, `content_engine`, `workflow`), a `RedisCheck`, and a bespoke
`Kanvas\Health\Checks\QueueSizeCheck` with per-queue warning/critical
thresholds (`default`, `scout`, `agent-runtime`, `batch-logger`, `ledger`).
This is Spatie's `spatie/laravel-health` package wired into the Kernel's
`RunHealthChecksCommand` (every minute) and `DispatchQueueCheckJobsCommand`
(every minute) schedule entries — results land in
`health_check_result_history_items` and can notify Slack/mail on failure
(`config/health.php`). This is the package-driven counterpart to the
hand-rolled `kanvas:status` command (§7.3): the Health package is the
scheduled/alerting path, `kanvas:status` is the on-demand human-readable one.

### 6.6 Attribute-driven discovery/registration

Several sub-systems avoid hand-maintained registries by scanning the
codebase for PHP attributes and syncing what they find into a DB catalog via
a dedicated Artisan command:

- `#[WorkflowAction]` classes → `kanvas:workflow-sync-actions` syncs them
  into the actions catalog.
- `#[AgentTypeDefinition]` handler classes → `kanvas:intelligence:sync-agent-types`
  syncs them into `agent_types` (global).
- `#[AgentTool]` classes → `kanvas:nervous-system:sync-tools` syncs them into
  `nervous_system_tools` (global), and `kanvas:nervous-system:check-tool-drift`
  fails CI/ops when disk and DB disagree — a lightweight "is the catalog
  stale" diagnostic.

This "discover from source, sync to DB, diff-detect drift" triad is a
recurring pattern any time a set of pluggable handlers needs both compile-time
type-safety (a real PHP class) and run-time discoverability (a DB row a
GraphQL query or agent prompt can list).

### 6.7 Multi-tenancy scoping helpers

`KanvasModelTrait` (composed of `KanvasAppScopesTrait` +
`KanvasCompanyScopesTrait`) gives every domain model `fromApp()` /
`fromCompany()` scopes plus `getById()` / `getByIdFromCompanyApp()` lookup
helpers, so tenant scoping is one line (`Model::query()->fromApp($app)`)
instead of a hand-rolled `where('apps_id', ...)` on every query — and, per
`.claude/CLAUDE.md`, models must **never** redefine their own `forApp`/
`forCompany` scope; the one exception (`apps_id=0` global-row union) has its
own explicitly-named `scopeFromAppOrGlobal` on the two models that legitimately
need it (`AgentType`, `ToolCategory`).

### 6.8 Soft deletes without Laravel's `SoftDeletes`

Every domain uses an `is_deleted` boolean column and `Baka\Traits\SoftDeletesTrait`
(`$model->delete()` performs a soft delete and fires the `deleting` event,
`notDeleted()` scope filters live rows) rather than Laravel's built-in
`SoftDeletes` trait/`deleted_at` convention — this is deliberate and
consistent across all 12 domain databases.

### 6.9 Octane-awareness

The stack runs under Laravel Octane (Swoole) — `php artisan octane:start
--server=swoole`. Because Octane workers are long-lived, several classes of
bugs that don't exist in a classic PHP-FPM request cycle are explicitly
called out in the connector conventions: never cache an external-SDK client
instance in a `static` property keyed by tenant (a credential rotation won't
invalidate it — see `src/Domains/Connectors/CLAUDE.md`), and any command that
switches the "current app" context (§3.2) must do so per-invocation rather
than assuming a fresh container.

## 7. Environment Setup & Diagnostics

### 7.1 Local bring-up (Docker)

Per the project README, the stack is Docker Compose-based
(`docker-compose.yml` / `docker-compose.1.x.yml` / `docker-compose.development.yml`
/ `docker-compose.local.yml`), with separate containers for: the PHP app
(Octane/Swoole), MySQL, Redis, and — per `docker-compose.1.x.yml` — a large
fan-out of dedicated `queue:work` containers, one per queue family (`scout`,
`imports`, `workflow`, `agent-runtime`, `agent-task-worker`, `agent-chat`,
`broadcasts`, `ledger`, `batch-logger`, `product-enrichment`,
`product-discovery`, ...), plus a `schedule:work` container that replaces
cron. Bring-up sequence (condensed from the README):

```bash
docker compose up --build -d
docker-compose ps                       # confirm containers healthy

# create the 7 per-domain databases inside the MySQL container
docker exec -it mysqlLaravel /bin/bash
#   inventory, social, crm, workflow, commerce, action_engine, event

# copy + fill in .env (DB + Redis host names must match the container names)
cp .env.example .env

docker exec -it phpLaravel bash
php artisan key:generate
# set APP_JWT_TOKEN / APP_KEY / KANVAS_APP_ID before the next step
php artisan kanvas:setup-ecosystem       # §3.3 — migrates + seeds every domain
```

`GET http://localhost:80/v1/` returning `"Woot Kanvas"` confirms the API is
serving. Individual domain modules (Inventory, Social, Guild) are then
initialized per-company with their own `{domain}:setup` command (§6/§4.4)
after their env vars and migrations are in place.

### 7.2 Migrations, one command per domain

`composer.json` defines one Composer script per domain migration path
(`migrate-inventory`, `migrate-social`, `migrate-crm`, `migrate-workflow`,
`migrate-commerce`, `migrate-action-engine`, `migrate-subscription`,
`migrate-events`, `migrate-intelligence`, `migrate-scribe`, `migrate-hr`,
`migrate-laravel-workflow` for the vendored durable-workflow engine's own
migrations), plus `migrate-kanvas` for the default connection and
`migrate-all-kanvas` that chains all of them in dependency order. Each is a
thin wrapper: `php artisan migrate --path database/migrations/{Domain}/
--database {connection}`. `kanvas:setup-ecosystem` (§3.3) is the same
sequence baked into a single idempotent Artisan command rather than a shell
one-liner, for use inside automation.

### 7.3 Diagnosing a running environment

`php artisan kanvas:status` is the primary hand-run diagnostic (full source
walked in §4.4): it prints a table of all 12 domain DB connections with
up/down + latency, a Redis ping, and a table of every named queue's pending
and failed-job counts (flagging any queue over a configurable
`--backlog=10000` threshold or carrying failed jobs), then exits non-zero if
any DB/Redis connection is down. This is the fastest single command to run
when something is "acting weird" in any environment.

For always-on monitoring rather than an on-demand snapshot, `spatie/laravel-health`
is wired up (`App\Providers\HealthProvider`, §6.5) and scheduled every minute
via the Kernel; its results are queryable/notifiable independent of anyone
running a CLI command. `config/health.php` controls result retention (5 days
by default) and optional mail/Slack notification throttling.

`kanvas:nervous-system:check-tool-drift` (§6.6) is a good pattern to reuse for
any "is generated/synced state stale" diagnostic: exit non-zero on drift so it
can gate a deploy or a scheduled alert, rather than only being useful when a
human remembers to run it.

### 7.4 Running tests

Per `tests/CLAUDE.md`, tests run **inside the PHP Docker container, never
locally**:

```bash
docker exec -it phpkanvas-ecosystem bash -c "cd /var/www/html && php vendor/bin/phpunit --filter testCreateAction"
docker exec -it phpkanvas-ecosystem bash -c "cd /var/www/html && php vendor/bin/paratest --testsuite=ActionEngine"
```

`phpunit.xml` defines one suite per domain (`Unit`, `Ecosystem`, `GraphQL` +
per-domain GraphQL suites, `Inventory`, `Social`, `Guild`, `Connectors`,
`Workflow`, `Intelligence`, `Scribe`, `Baka`, `Souk`, `Event`, `ActionEngine`,
`Insurance`), matching the same domain boundary used everywhere else in the
codebase. `RefreshDatabase` is explicitly banned (it wipes shared tables
across every domain connection); tests instead use `DatabaseTransactions` with
an explicit `$connectionsToTransact` array naming every non-default
connection the code under test writes to.

### 7.5 Static analysis & style

- **PHPStan / Larastan** (`phpstan.neon.dist`) scans `app` + `src` at level 0
  with a curated `ignoreErrors` list (mostly to tolerate the dynamic
  Bouncer/model-cast patterns used throughout).
- **PHP-CS-Fixer** (`.php-cs-fixer.php`) enforces PSR-12 + short array syntax
  + alphabetically-ordered `use` imports + trailing commas on multiline
  calls; CI (`static-analysis.yml`) and a local Claude-Code post-edit hook
  both run it.
- **StyleCI** (`.styleci.yml`) is the GitHub-side enforcement of the same
  rules on every PR.

### 7.6 CI (`tests.yml` / `static-analysis.yml`)

GitHub Actions provisions the full multi-database environment for every
push: one named database per domain connection (`kanvas`, `inventory`,
`social`, `crm`, `workflows`, `action_engine`, `commerce`, `event`,
`intelligence`, `accounting`), Redis, and Meilisearch, then runs the
per-domain PHPUnit suites in a matrix (one job per `testsuite` in
`phpunit.xml`). This is the same domain-per-connection shape from §6.1
reflected once more, this time in CI infrastructure.

---

## 8. Appendix: Full Command Catalog

Generated by scanning every `protected $signature` / `protected $description`
pair under `app/Console/Commands/` (308 commands as of this pass; a handful
of files in that tree — `*/Schedules/*Schedule.php`,
`Connectors/VinSolution/Concerns/*` — are helper classes, not commands, and
are excluded). Grouped by the same top-level/sub-folder structure described
in §4.2. Where a command's `$description` property was left as the
Artisan-generated placeholder (`"Command description"`), that is shown
verbatim — a good signal that the command deserves a real one-line
description, run `php artisan help {command}` for its actual argument/option
list.

### AccessControl

| Command | Purpose |
|---|---|
| `kanvas:assign-role` | Assign a role to a user for a specific app. {user} is an email or user id, {role} is a role name or id. |
| `kanvas:create-role` | Command description |
| `kanvas:grant-owner-all-permissions` | Grant all permissions to existing Owner roles using Bouncer everything() |
| `kanvas:update-abilities-templates` | Command description |
| `kanvas:update-abilities` | Command description |

### Analytics

| Command | Purpose |
|---|---|
| `kanvas:analytics:send-engage-usage-report` | Email the weekly Engage usage leaderboard to each company's managers. |

### Connectors

**Acumatica**

| Command | Purpose |
|---|---|
| `kanvas:acumatica-backfill` | Backfill Acumatica data (wide window) for one or all sync-enabled Kanvas companies. |
| `kanvas:acumatica-enable-sync` | Enable or disable the scheduled Acumatica sync for a company. |
| `kanvas:acumatica-pull` | Pull Acumatica products, warehouses, stock, customers, vendors, sales orders and GL (accounts, fiscal periods, journal entries) from the SQL replica into a Kanvas company. |
| `kanvas:acumatica-sync` | Dispatch incremental Acumatica syncs for every opted-in company. |

**Amplitude**

| Command | Purpose |
|---|---|
| `kanvas:amplitude-sync-events-to-google` | Sync all events from Amplitude to Google Dynamic Recommendation |

**Apollo**

| Command | Purpose |
|---|---|
| `kanvas:guild-apollo-backfill-job-change-events` | Backfill people.enriched ledger events from historical Apollo job changes (APOLLO_LAST_JOB_CHANGE) |
| `kanvas:guild-apollo-changes-report` | List the people whose current employer changed during Apollo enrichment (old → new job) |
| `kanvas:guild-apollo-clean-enrichment-noise` | Strip fake (empty/equal/past-employer) before→after changes from historical people.enriched ledger events |
| `kanvas:guild-apollo-enrich-person` | Enrich a single person from Apollo directly, bypassing the workflow/activity and the recently-screened gating |
| `kanvas:guild-apollo-people-sync` | Enrich all people in a company directly from Apollo (no workflow/integration setup required) |

**Azul**

| Command | Purpose |
|---|---|
| `azul:test-mtls` | Test Azul mTLS certificate connection |

**CMLink**

| Command | Purpose |
|---|---|
| `kanvas:cmlink-connector-download-destination-plans` | Download all destination plan as products example: [{"code":"us","limit":25,"page":1}]  |

**Credit700**

| Command | Purpose |
|---|---|
| `kanvas:credit700-regenerate-leads-link` | Regenerate leads link for 700 credit |

**DealerAppCenter**

| Command | Purpose |
|---|---|
| `kanvas-connectors:dealer-app-center-migrate-products-to-vehicles` | Map Kanvas Products (+ variants/attributes) to dealer-api Vehicle rows and insert them directly into the dealer-api MySQL database |

**DealerSocket**

| Command | Purpose |
|---|---|
| `kanvas:dealersocket-download-all-leads` | Download all leads from DealerSocket by searching customers alphabetically |
| `kanvas:dealersocket:setup` | Configure DealerSocket connector for an application |

**DriveCentric**

| Command | Purpose |
|---|---|
| `kanvas:drivecentric-download-leads` | Download all leads/deals from DriveCentric for companies with DriveCentric configuration |
| `kanvas:drivecentric-download-user-leads` | Pull every DriveCentric deal assigned to a specific salesperson, creating the leads we are missing locally |

**ESim**

| Command | Purpose |
|---|---|
| `kanvas:esim-connector-download-destination-plans` | Download all destination plan as products example: [{"code":"us","limit":25,"page":1}]  |
| `kanvas:esim-connector-sync-esim` | Sync Esim with providers |
| `kanvas:esim-connector-sync-orders` | Sync orders with providers |
| `kanvas:esim-generate-recommendation` | Generate eSIM recommendation |
| `kanvas:esim-generate-regional-country-info` | Generate eSIM recommendation |
| `kanvas:import-order-from-csv` | Command description |
| `kanvas:inject-insurance-to-order` | Inject insurance structure into an existing order metadata based on eSIM plan duration |

**Elead**

| Command | Purpose |
|---|---|
| `kanvas:elead-download-all-leads` | Download all leads from Elead for a specific company |
| `kanvas:elead-flush-cache` | Flush all Elead cached data (entities, reference data) for a company |

**Google**

| Command | Purpose |
|---|---|
| `kanvas:google-delete-all-message-document` | Send all messages to google recommendation as documents |
| `kanvas:google-generate-user-message-feed` | Using Google recommendations, generate a user message feed for a specific app |
| `kanvas:google-sync-all-message-document` | Send all messages to google recommendation as documents |
| `kanvas:google-sync-all-user-interaction` | Sync all user interaction to google recommendation as Events |

**Hermes**

| Command | Purpose |
|---|---|
| `kanvas:hermes-backfill-gateway-tokens` | Hoist per-deployment Hermes gateway tokens onto their owning agent custom field |

**InAppPurchase**

| Command | Purpose |
|---|---|
| `kanvas:iap-webhook:setup` | Set up Apple and Google Play subscription webhook receivers for an app |

**Internal**

| Command | Purpose |
|---|---|
| `kanvas:internal-people-clean-city-name` | Sync all people in company and clean up city name |
| `kanvas:internal-people-email-extract-sync` | Sync all people in company and extract company name from email |

**Intras**

| Command | Purpose |
|---|---|
| `kanvas:intras-backfill-participant-contacts` | Backfill email/phone contacts for People already imported from Intras (legacy stores them in participants_custom_fields, original sync skipped them) |
| `kanvas:intras-backfill-participant-profile-fields` | Backfill nivel/area (and other profile attributes) onto People already imported from Intras |
| `kanvas:intras-import` | Import data from legacy Intras/SIPGO database into Kanvas |
| `kanvas:intras-inspect-participant-fields` | Dump the columns, custom-field names and lookup catalogs behind Intras participants, so profile fields (nivel, area) are mapped against what an install really has |

**Mailgun**

| Command | Purpose |
|---|---|
| `kanvas:guild-mailgun-email-validate` | Validate the email addresses of all people in a company with Mailgun, flagging hard bounces / invalid addresses |
| `kanvas:mailgun:provision-agent-mailbox` | Give an agent its own email address on the company Mailgun domain, or take it away. |

**Mercury**

| Command | Purpose |
|---|---|
| `kanvas:mercury-pull` | Recovery sweep for the Mercury bank feed: re-pull, re-match, refresh balances. |

**Microsoft**

| Command | Purpose |
|---|---|
| `kanvas:sync-microsoft-email` | Sync emails from Microsoft Graph for companies with active OAuth tokens |

**Movipass**

| Command | Purpose |
|---|---|
| `kanvas:movipass-capture-payment` | capture authorized payment for Movipass orders |
| `kanvas:movipass-charge-late-orders` | Charge late fees for movipass orders |
| `kanvas:movipass-check-expiring-orders` | Check expiring orders |
| `kanvas:movipass-fix-order-items` | Fix order items for Movipass orders within a date range |
| `kanvas:movipass-generate-order-voucher` | Generate (or re-generate with --force) the release voucher PDF for a Movipass order by vehicle plate |
| `kanvas:movipass-reverse-payment` | reverse authorized payment for Movipass orders |
| `kanvas:movipass-setup-movipass-order` | Setup Movipass parking reservation order type and statuses |
| `kanvas:movipass-setup-roadside-assistance` | Setup Movipass roadside assistance order type and statuses |
| `kanvas:movipass-setup-roles` | Setup roles for movipass orders |
| `movipass:create-order-transitions` | Create order status transitions for all existing movipass orders |
| `movipass:scan-duplicate-orders` | Detect and flag duplicate impound orders based on order type config |
| `movipass:setup-impound-lot` | Setup impound_lot order type, statuses, and transitions (idempotent) |

**NetSuite**

| Command | Purpose |
|---|---|
| `kanvas:netsuite-download-customer` | Download customer from NetSuite to this company |
| `kanvas:netsuite-download-products` | Download products from NetSuite to this company |
| `kanvas:netsuite-sync-all-stock` | Sync all Kanvas product stock availability from NetSuite |
| `kanvas:netsuite-sync-barcodes` | Sync inventory for the company |
| `kanvas:netsuite-sync-customer` | Sync all customer names from NetSuite in a single optimized call |
| `kanvas:netsuite-sync-products` | Sync inventory for the company |
| `kanvas:netsuite-sync-stock` | Sync all products stock from NetSuite in a single optimized call |
| `netsuite:reconcile-customer-items` | Reconcile NetSuite customer items list against channel product variants |

**Notifications**

| Command | Purpose |
|---|---|
| `kanvas:internal-mail-caddie-lab` | Command description |

**OpenClaw**

| Command | Purpose |
|---|---|
| `kanvas-openclaw:telemetry-collect` | Dispatch a CollectAgentTelemetryJob for every running OpenClaw deployment |
| `kanvas:openclaw-collect-usage` | Collect daily usage data from all running OpenClaw deployments |

**PasoRapido**

| Command | Purpose |
|---|---|
| `kanvas-paso-rapido:company-limits` | Block a company from PasoRapido tag verification and/or override its rate limits. Every limit accepts 0 to disable that check, or "clear" to fall back to the app default. Run with no options to inspect the current state. |
| `paso-rapido:create-order-transitions` | Backfill order status transition history for existing paso_rapido paid orders |
| `paso-rapido:setup-order-status` | Create paso_rapido order type, statuses (draft, paid) and transitions |

**ProductEnrichment**

| Command | Purpose |
|---|---|
| `kanvas-inventory:backfill-product-enrichment` | Generate enrichment facets and search blurbs for products that do not have them yet |

**PromptMine**

| Command | Purpose |
|---|---|
| `kanvas:import-image-prompts-from-sheet` | Import image prompts from Google Sheets document. |
| `kanvas:import-prompts-from-sheet` | Import prompts from Google Sheets document. |
| `kanvas:prompt-agent-creator` | Generate and post viral AI prompts using creator agents |
| `kanvas:prompt-agent-engager` | Redistribute prompts from a Google Sheet |
| `kanvas:prompt-generate-top-videos-feed` | Generate tags for all messages in google recommendation |
| `kanvas:prompt-generate-trending-feed` | Generate tags for all messages in google recommendation |
| `kanvas:prompt-google-generate-tags-message` | Generate tags for all messages in google recommendation |
| `kanvas:prompt-index-recombee-message` | Index prompt to recombee |
| `kanvas:prompt-index-recombee-user-interactions` | Index prompt to recombee |
| `kanvas:prompt-populate-subcategories-feed` | Populate subcategories feed with a curated list of prompts |
| `kanvas:promptmine-change-media-url` | Import image prompts from Google Sheets document. |
| `kanvas:promptmine-credit-spotlight-creators` | Command to check recommbee for new spotlight creators |
| `kanvas:promptmine-fix-prompt-data` | Fix promptmine prompt data |
| `kanvas:promptmine-reset-free-image-credits` | Reset daily free image credits for unsubscribed users |
| `kanvas:promptmine-send-follow-recommendations-push-notification` | Send Push notification recommendations to follow other users with similar interests. |
| `kanvas:promptmine-send-push-monthly-prompt-count` | Send Push notification of montly count of prompts for each user. |
| `kanvas:promptmine-send-push-prompt-of-the-week` | Send Push notification of prompt of the week for each user. |
| `kanvas:prompts-user-credit` | Set user credit based on messages sent in the last 24 hours |
| `kanvas:redistribute-prompts-google-sheet` | Redistribute prompts from a Google Sheet |

**Recombee**

| Command | Purpose |
|---|---|
| `kanvas:recombee-index-messages` | Index messages to recombee |
| `kanvas:recombee-index-products` | Index products to Recombee for recommendations |
| `kanvas:recombee-index-tags` | Index tags to the recommendation engine |
| `kanvas:recombee-index-users-follows` | Index users follows to the recommendation engine |
| `kanvas:recombee-index-users` | Index users to the recommendation engine |

**SalesAssist**

| Command | Purpose |
|---|---|
| `kanvas:import-vehicle-attributes` | Import vehicle makes and models as product attributes from NHTSA API |
| `kanvas:sa-setup-leads` | Command description |
| `kanvas:sa-setup-receivers` | Command description |
| `kanvas:sales-assist-sync-channels` | Create social channels for leads in a company that do not have sessions |

**Salesforce**

| Command | Purpose |
|---|---|
| `kanvas:salesforce-backfill` | Bulk-pull Salesforce Accounts/Contacts and queue them for import into Kanvas |
| `kanvas:salesforce-import-properties` | Pull Location__c (+ primary Location_Contact__c) from Salesforce and queue them for import into Kanvas Products, for testing the Property mapping. |

**ScrapingDog**

| Command | Purpose |
|---|---|
| `kanvas:scrapingdog-amazon-bestsellers` | Scrape Amazon Best Sellers via ScrapingDog department by department, enrich each ASIN and import them |

**ScrapperApi**

| Command | Purpose |
|---|---|
| `kanvas:move-price-from-warehouse` | Command description |
| `kanvas:products-scrapper` | Download products from shopify to this warehouse |
| `kanvas:scrapper-amazon-bestsellers` | Scrape Amazon Best Sellers department by department, enrich each ASIN via the structured product endpoint and import them |
| `kanvas:scrapper-cleanup-product-images` | Command description |
| `kanvas:scrapper-custom-field` | Command description |
| `kanvas:scrapper-index-csv` | Command description |
| `kanvas:scrapper-product-inventory` | Sync all products inventory from Scrapper API |
| `kanvas:scrapper-rotate-homepage-tag` | Rotate the Homepage tag per category: remove it (any case) from current products and assign it to N others |
| `kanvas:scrapper-search` | Download products from shopify to this warehouse |
| `scrapper-api:test-custom-tax` | Test custom tax calculation for a specific product variant |

**Shopify**

| Command | Purpose |
|---|---|
| `"kanvas:upload-categories-to-shopify` | " " |
| `kanvas:inventory-download-from-shopify-sync` | Download products from shopify to this warehouse |
| `kanvas:inventory-shopify-inventory-level-sync` | Update all stocks from variants and added to warehouses |
| `kanvas:inventory-shopify-sync` | Send all our local inventory to shopify |

**SuperCarros**

| Command | Purpose |
|---|---|
| `kanvas:supercarros-vehicle-inventory` | Import vehicles from SuperCarros. With --company_id imports one company |

**TeeTime**

| Command | Purpose |
|---|---|
| `kanvas:teetime-setup-booking-rule` | Wire the workflow rule that runs ActivateBookingOnPaymentActivity when a booking order is paid (after-payment-intent). |

**Tookan**

| Command | Purpose |
|---|---|
| `kanvas:tookan-setup-giftea-order` | Setup Giftea delivery order types and statuses (parent + provider) |

**UniversalAssistance**

| Command | Purpose |
|---|---|
| `kanvas:process-pending-insurance` | Process pending insurance data from orders and create Universal Assistance vouchers |
| `kanvas:ua-cancel-voucher` | Cancel Universal Assistance vouchers by their voucher numbers (supports batch) |

**VAuto**

| Command | Purpose |
|---|---|
| `kanvas:vauto-inventory-attribute-generation` | Generate filterable inventory attributes (mileage and price ranges) for a specific app |

**VentaMobile**

| Command | Purpose |
|---|---|
| `kanvas:ventamobile-connector-download-destination-plans` | Download all destination plan as products  |

**VinSolution**

| Command | Purpose |
|---|---|
| `kanvas:vinsolution-download-all-leads` | Download all leads from VinSolution for one or multiple companies (processed sequentially to avoid rate limiting). Omit company_ids to auto-discover every company opted-in via the DOWNLOAD_ALL_LEADS_USER setting. |
| `kanvas:vinsolution-sync-lead-sources` | Download all VinSolution lead sources for the configured companies and create the matching Kanvas lead sources. |
| `kanvas:vinsolution-sync-lead-types` | Download all VinSolution lead types and create the matching Kanvas lead types for the configured companies. |
| `kanvas:vinsolution-sync-users` | Sync VinSolution dealer users into Kanvas: match by email and store the VinSolution user id, creating missing users with a default password and no email. |
| `kanvas:vinsolution-webhook` | Manage VinSolution webhooks - add webhook or list active webhooks |

**VoiceBridge**

| Command | Purpose |
|---|---|
| `kanvas:voice-bridge:setup` | Configure and validate the VoiceBridge integration for an app |
| `kanvas:voicebridge:trigger-call` | Trigger a VoiceBridge outbound call for a specific lead (sets transcript delay to 1 minute) |

**WooCommerce**

| Command | Purpose |
|---|---|
| `kanvas:pull-woocomerce-orders` | Pull orders from WooCommerce |
| `kanvas:pull-woocomerce-products` | Pull products from WooCommerce |
| `kanvas:pull-woocomerce-users` | Pull users from WooCommerce |

**WordPress**

| Command | Purpose |
|---|---|
| `kanvas:wordpress-download-inventory` | Download vehicle inventory from WordPress dealer sites, generate CSV files, upload via FTP/FTPS/SFTP, and notify via email |

**Yusen**

| Command | Purpose |
|---|---|
| `kanvas:connectors:yusen-inventory-report` | Report where a Yusen Item Balance XML disagrees with Kanvas and NetSuite |

**Zoho**

| Command | Purpose |
|---|---|
| `kanvas:guild-zoho-lead-file-sync` | Download all leads from Zoho to this branch |
| `kanvas:guild-zoho-lead-sync` | Download all leads from Zoho to this branch |
| `kanvas:zoho-agents-create-sync` | Create users from CSV if they don't exist, assign member numbers, and sync with Zoho if the agent exists there |
| `kanvas:zoho-agents-file-sync` | Download all agents from Zoho file to this branch |
| `kanvas:zoho-agents-sync-from-file` | Sync agents from a Zoho CSV export file, updating sponsor info and deactivating inactive agents |
| `kanvas:zoho-agents-sync` | Download all agents from Zoho to this branch |

### Ecosystem

| Command | Purpose |
|---|---|
| `kanvas:add-system-module-fields` | Add the fields of a system module |
| `kanvas:app-key-revoke` | Revoke a Kanvas App Key, immediately, on a given date, or by rotating its secret |
| `kanvas:app-key` | Create a new Kanvas App Key |
| `kanvas:create-global-system-modules-from-template` | Create system modules from the ModulesRepositories template with their modules_id. Global (apps_id=0) by default, or per-app via --app_id. |
| `kanvas:create-global-system-module` | Add the fields of a system module |
| `kanvas:email-template-sync` | Sync email templates from the app to the email service provider |
| `kanvas:import-email-templates` | Import email templates from a PHPMyAdmin JSON export into a specific app |

**Companies**

| Command | Purpose |
|---|---|
| `kanvas-company:add-admins` | Add admins to a company |
| `kanvas-company:delete` | Delete a user company |

**Notifications**

| Command | Purpose |
|---|---|
| `kanvas:mail-notification-to-all-app-users` | Send specific email to all users of an app or to a test email address with optional date filtering |
| `kanvas:mail-notification-to-app-users-csv` | Send specific email to unregistered users from a CSV file or to a test email address |
| `kanvas:mail-user-list-template` | Send specific email to recipients from a CSV file |
| `kanvas:unregistered-users-campaign-mail` | send specific email to unregistered users from third parties |
| `kanvas:users-engagement-reminder` | Simple daily email reminder to get users to engage with the app again |

**Users**

| Command | Purpose |
|---|---|
| `kanvas:detect-signup-anomaly` | Alert on apps whose signup rate in the last hour is far above their own baseline |
| `kanvas:user-migration` | Migrate legacy users to new kanvas niche structure |
| `kanvas:user:delete` | Delete a user |
| `kanvas:users-remove` | Remove User from Company |
| `kanvas:users` | Add User to Company |

### Event

| Command | Purpose |
|---|---|
| `kanvas:events:generate-upcoming-time-slots` | Roll the time-slot window forward for active schedule rules and refresh price snapshots within each app booking horizon |
| `kanvas:events:send-booking-reminders` | Send due booking reminder emails for upcoming appointments |

### Guild

| Command | Purpose |
|---|---|
| `kanvas-guild:agent-lead-count` | Calculate total leads count for all active agents |
| `kanvas-guild:backfill-people-driver-license` | Backfill the peoples license columns from the get_docs_drivers_license custom field |
| `kanvas-guild:daily-report` | Send daily report to the guild |
| `kanvas-guild:import` | Import agents from a csv file |
| `kanvas-guild:match-people` | Match people in the database with the csv file |
| `kanvas-guild:people-export` | Export all people to a single CSV and send via email |
| `kanvas-guild:recalculate-active-leads-count` | Recompute peoples.active_leads_count from leads (leads_status_id IN (1, 2), not deleted) |
| `kanvas-guild:repair-people-addresses` | Collapse duplicate default addresses, demote previous homes, and unmangle multi-line streets |
| `kanvas:guild-merge-duplicate-organizations` | Conservatively merge normalized-duplicate organizations (same name after suffix/casing/accent) within a company |
| `kanvas:guild:detect-duplicates` | Sweep Guild Organizations + People for likely duplicates and queue them for review. |

### Intelligence

**Agents**

| Command | Purpose |
|---|---|
| `agent-type:create` | Wizard to create a new agent type (and optionally an agent record) |
| `agent:create` | Wizard to create a new agent record (supports global agents with apps_id=0 / companies_id=0) |
| `agent:inventory-chat` | Interactive chat with the Inventory AI agent |
| `kanvas:agent:create-default-channel` | Command description |
| `kanvas:agent:events-versions-reminder` | Command description |
| `kanvas:agent` | Interact with a Kanvas agent |
| `kanvas:intelligence:daily-agent-config-backup` | Dispatch end-of-day config backups for active agents whose company local time is 23:xx. |
| `kanvas:intelligence:setup-voice` | Setup required agents and integrations for the voice workflow (LeadAgentFirstMessageOutreachActivity + LeadVoiceFollowUpJob) |
| `kanvas:intelligence:sync-agent-types` | Discover #[AgentTypeDefinition] handler classes and sync them into the agent_types catalog (global, apps_id=0). |

**FollowUp**

| Command | Purpose |
|---|---|
| `intelligence:notification-engagement` | Refresh the content of a session by its ID |
| `kanvas:intelligence:migrate-follow-up` | Migrate pipeline stages notification_engagement_rules to Follow Up structure |

**Knowledge**

| Command | Purpose |
|---|---|
| `intelligence:knowledge:index` | Build or replace knowledge documents for a registered entity |
| `kanvas:intelligence:setup-agent-knowledge-rule` | Wire the workflow rules that keep the company knowledge base in sync: index on Agent attach-file, prune on Filesystem delete. |

**Leads**

| Command | Purpose |
|---|---|
| `intelligence:clone-pipeline` | Clone a pipeline with all its stages, follow ups, days and templates |
| `kanvas:create-fake-lead-context` | Command description |
| `kanvas:intelligence:backfill-lead-ai-mode-keys` | Copy legacy V2 prefixed lead settings (internet_/phone_/showroom_ ai_mode and follow-up variants) into the generic ai_mode and ai_follow_up keys |
| `kanvas:intelligence:set-lead-ai-mode` | Set ai_mode to SUPPORT and ai_follow_up to 1 for leads that do not have ai_mode set |

**Messaging**

| Command | Purpose |
|---|---|
| `intelligence:refresh-session-content` | Refresh the content of a session by its ID |
| `kanvas:intelligence:send-delay-message` | Command description |
| `kanvas:intelligence:send-unresponde-message` | Command description |

**Social**

| Command | Purpose |
|---|---|
| `kanvas:social-agent-creator` | Execute social content creation automation using AI agents |
| `kanvas:social-agent-engager` | Execute social engagement automation using AI agents |

**Usage**

| Command | Purpose |
|---|---|
| `kanvas-intelligence:collect-deployment-usage` | Collect token/cost usage from every running container-runtime deployment (OpenClaw, Hermes) into agent_usage_snapshots. |
| `kanvas-intelligence:collect-session-transcripts` | Pull conversation transcripts from every running container-runtime deployment into agent_conversations/agent_conversation_messages. |
| `kanvas-intelligence:rollup-local-agent-usage` | Roll up Neuron/Laravel per-message token usage into agent_usage_snapshots for a given day. |
| `kanvas:backfill-dashboard-metrics` | Populate nervous_system_dashboard_metrics_daily for past dates from existing plan data. |

### Inventory

| Command | Purpose |
|---|---|
| `kanvas-inventory:backfill-variant-rating-from-category` | Recompute Variant.rating from product category weights for an app |
| `kanvas-inventory:benchmark-product-recommendation` | Time the inventory recommendation agent and report tokens + tool calls per query |
| `kanvas-inventory:compare` | Compare inventory for the company |
| `kanvas-inventory:daily-report` | Send daily inventory report including expiration and low stock notifications |
| `kanvas-inventory:dev-to-prod-inventory-export` | Migrate inventory from to development to prod |
| `kanvas-inventory:discover-products` | Run a natural-language product search from the console and show the results |
| `kanvas-inventory:evaluate-product-discovery` | Score product discovery against a judged query set (recall@k and MRR) |
| `kanvas-inventory:export-products-cross-env` | Export inventory products (names, not ids) to a JSONL file for cross-environment migration |
| `kanvas-inventory:export-products` | Export inventory products to CSV and optionally send the file by email |
| `kanvas-inventory:import-products-cross-env` | Import a cross-environment inventory JSONL export, remapping names to destination ids |
| `kanvas-inventory:region-migration` | Migrate inventory region to ecosystem |
| `kanvas-inventory:scaffold-golden-set` | Draft a product-discovery golden set from the impression log for a human to prune |
| `kanvas-inventory:scout-clean-legacy-inventory` | Clean up scout index for legacy inventory products |
| `kanvas-inventory:shopify-check` | Check inventory for the company |
| `kanvas:create-attribute-types` | Create a new set of attribute types global or to an App |
| `kanvas:migrate-attribute-type` | Test attribute update query with rollback |

### Lead

| Command | Purpose |
|---|---|
| `lead:dispatch-follow-ups` | Hourly tick: fan out follow-up jobs to every app+company inside its work-hours window. |
| `lead:follow-up-daily-summary` | Emit yesterday's follow-up activity rollup ledger event per (app, company). |

### Movipass

| Command | Purpose |
|---|---|
| `kanvas-movipass:backfill-company-default-region` | Copy the legacy movipass_region_id company custom field into the generic default_region_id read by RegionResolutionService. |
| `kanvas-movipass:backfill-unpaid-orders` | Backfill orders with a PAID payment that were never marked paid (regression from feat/payment-improvements: removed inline Order::markAsPaid call relied on a broken Payment::markAsPaid cascade). |
| `kanvas-movipass:relink-corporate-variant-warehouses` | Repoint variants whose product changed company (corporate migration) to a warehouse of their own company, so they can be ordered again. |
| `kanvas-movipass:seed-global-regions` | Seed global regions (AR, SV, PR) and configure country map for multi-country Movipass support. DR region must already exist. |

### NervousSystem

**Agents**

| Command | Purpose |
|---|---|
| `kanvas:agent-runtime-check-health` | Reconcile awake_state across every agent — probes runtime deployments + in-process providers |
| `kanvas:agent-runtime-flag-dead-deployments` | Flag dead running agent deployments as failed so they stop being probed and reported |
| `kanvas:nervous-system:ensure-agent-report-role` | Ensure the AgentReport Bouncer role exists for the targeted app(s). |
| `kanvas:nervous-system:ensure-orchestrator-agent` | Provision one orchestrator agent + Inbox + routing receiver per company. |
| `kanvas:nervous-system:refresh-agent-counters` | Refresh today's volatile counters on agent_daily_cycles for awake agents. |
| `nervous-system:expire-capabilities` | Sweep agent skill/tool grants past their expires_at timestamp, mark them inactive, and emit skill.expired / tool.expired ledger events |

**Learning**

| Command | Purpose |
|---|---|
| `kanvas:nervous-system:record-agent-cycles` | Compile yesterday's ledger activity into per-agent daily-cycle journals. |
| `kanvas:nervous-system:send-daily-learning-digest` | Email the daily agent-learning digest to AgentReport role members. |
| `kanvas:nervous-system:summarize-agent-daily-learning` | LLM-summarize yesterday's conversations per agent and push durable facts back into the agent's memory bank. |

**Ledger**

| Command | Purpose |
|---|---|
| `kanvas:backfill-ledger-event-categories` | Populate nervous_system_events.category for legacy rows. |
| `nervous-system:archive-old-ledger-events` | Archive Nervous System ledger events older than the retention window to long-term storage and prune them from MySQL |
| `nervous-system:restore-ledger-events-from-archive` | Re-hydrate archived Nervous System ledger events (e.g. people.enriched) back into MySQL from cold storage |

**Metrics**

| Command | Purpose |
|---|---|
| `kanvas:backfill-pulse-metrics` | Populate nervous_system_pulse_metrics_daily for past dates from existing ledger + plan data. |
| `nervous-system:sync-model-pricing` | Sync LLM model pricing from LiteLLM / OpenRouter into the model_pricing table. |

**Plans**

| Command | Purpose |
|---|---|
| `kanvas:nervous-system:nudge-inactive-plans` | Ping owners of open plans that have had no activity past the inactivity threshold. |
| `kanvas:nervous-system:project-heartbeat` | Wake project PM agents that have stalled or waiting work, on each project's cadence. |
| `kanvas:nervous-system:sweep-stale-intake` | Chase unanswered intake plans, and cancel the ones that stay unanswered. |
| `kanvas:nervous-system:sync-kanban` | Mirror running Hermes agent kanban boards into NervousSystem plans/tasks |
| `nervous-system:detect-stalled-plan-tasks` | Detect Nervous System plan tasks that have been in_progress longer than the threshold and emit plan.task.stalled events |

**Provisioning**

| Command | Purpose |
|---|---|
| `kanvas:nervous-system:provision-default-consumer-modules` | Backfill companies_kanvas_modules for every company of an app with the default consumer modules (is_default=1, is_internal=0). Existing rows are left untouched unless --reactivate-deleted is passed. |
| `nervous-system:seed-swarm-demo-data` | Seed dummy cost / budget / daily-cycle data for a swarm — used to unblock frontend integration on the dashboard. |

**Scheduling**

| Command | Purpose |
|---|---|
| `kanvas:nervous-system:sweep-scheduled-actions` | Dispatch due scheduled agent actions (reminders / agent tasks). |

**Tools**

| Command | Purpose |
|---|---|
| `kanvas:nervous-system:check-tool-drift` | Fail when the #[AgentTool] classes on disk and the nervous_system_tools catalog disagree. |
| `kanvas:nervous-system:sync-tool-modules` | Mirror tool handlers' kanvasModules() declarations into the pivot. |
| `kanvas:nervous-system:sync-tools` | Discover #[AgentTool] classes and sync them into the nervous_system_tools catalog (global, apps_id=0). |
| `nervous-system:tool-setup` | Wizard to register a tool in the nervous system catalog |

### Root

| Command | Purpose |
|---|---|
| `kanvas:app-create` | Create a new Kanvas App |
| `kanvas:app-ecosystem-update` | Run the Kanvas Ecosystem Updates |
| `kanvas:import` | Command description |
| `kanvas:setup-ecosystem` | Setup the ecosystem for Kanvas |
| `kanvas:status` | Health snapshot of Kanvas: databases, redis, queues, failed jobs — is everything good? |
| `kanvas:version` | Whats the current version of kanvas niche you are running |

### Scribe

| Command | Purpose |
|---|---|
| `scribe:backfill-snapshot` | Backfill NULL billable/vendor snapshot fields on Invoices + Bills from their linked Guild Org. Idempotent |
| `scribe:evaluate-invoice-aging` | Dispatch EvaluateInvoiceAgingJob for every (app, company) tuple with open AR. |
| `scribe:import-vendor-approvers` | Sets the ap_approver_email and ap_approver_vendor_name custom fields on vendor Organizations from a Vendor Name / Approver Email spreadsheet |
| `scribe:test-pdf-ingest` | Run a real PDF through the live Scribe ingest pipeline (Gemini classify + Propose + GL post). |

### Search

| Command | Purpose |
|---|---|
| `kanvas-inventory:scout-product-index-process` | Process scout products with actions |
| `kanvas-social:reindex-users-records` | Reindex social users records by app |
| `kanvas-social:scout-message-reindex` | Reindex social messages by app |
| `kanvas:index-companies` | Reindex company records for the given app |
| `kanvas:index` | Index the Kanvas system module |
| `kanvas:search:typesense-sync-schema` | Re-type Typesense fields whose live collection drifted from the type the model declares |

**Algolia**

| Command | Purpose |
|---|---|
| `kanvas-search:delete-algolia-records` | Delete records on algolia given the index and filter, if any |

### Setup

| Command | Purpose |
|---|---|
| `kanvas-action-engine:setup` | Initializes the Action Engine system |
| `kanvas-event:setup` | Initializes the event system |
| `kanvas-guild:setup` | Initializes the Guild system |
| `kanvas-hr:setup` | Initialize HR for a company — seed the default leave types (Vacation, Sick, Personal, Unpaid). |
| `kanvas-inventory:setup` | Initializes the inventory system |
| `kanvas-scribe:setup` | Initialize Scribe accounting for a company — seed Chart of Accounts (country-aware) and pre-open all 12 monthly fiscal periods for the year. |
| `kanvas-social:setup` | Initializes the social system |
| `kanvas-souk:setup` | Initializes the commerce system |
| `kanvas:commerce-default-update` | Set defaults entities value for commerce companies |
| `kanvas:filesystem-setup` | Command that setup the filesystem disk |
| `kanvas:inventory-default-update` | Set defaults entities value for inventory companies |

### Social

| Command | Purpose |
|---|---|
| `app:create-global-reaction` | Create Global Reaction  |
| `kanvas-social:generate-user-following-feed` | Generate a user message feed from users you are following |
| `kanvas-social:generate-user-message-feed` | Using Google recommendations, generate a user message feed for a specific app |
| `kanvas-social:reset-counter` | Reset the social counter for a specific app |
| `kanvas:remove-messages-by-keywords` | Remove messages by keywords |
| `kanvas:social:backfill-message-people-id` | Backfill messages.people_id from the associated Lead / Deal / People entity. |
| `kanvas:social:backfill-message-sender-type` | Backfill messages.sender_type from the JSON message payload. |
| `kanvas:sync-user-messages` | Sync user messages table with messages from the people a user follows |

### Souk

| Command | Purpose |
|---|---|
| `kanvas-souk:backfill-order-providers` | Backfill the order_providers pivot table for existing orders |
| `kanvas-souk:cancel-stale-payments` | Cancel payments stuck in processing beyond the configured TTL |
| `kanvas-souk:order-finish-expired` | Finish expired orders |
| `kanvas:create-order-status` | Create a new set of order status global or to an App |
| `kanvas:set-b2b-channel-variant-pricing` | Command description |

**Cart**

| Command | Purpose |
|---|---|
| `souk:abandon-cart` | Process abandoned cart notifications at specified intervals by applications |

### Support

| Command | Purpose |
|---|---|
| `kanvas:customFields-redis-regeneration` | insert a fake migration into the migrations table |
| `kanvas:daily-report` | Generate and display the Kanvas daily metrics report |
| `kanvas:dashboard-default-field` | Set default dashboard field |
| `kanvas:fake-migration` | insert a fake migration into the migrations table |
| `kanvas:lighthouse-redis-cache` | generate the lighthouse redis cache for a specific class |
| `kanvas:seed-demo-data` | Seed ~6 months of fully-linked demo volume (CRM ↔ Commerce ↔ Accounting) for a mission-control demo. |

### Workflows

| Command | Purpose |
|---|---|
| `kanvas:create-receiver-workflow` | Command description |
| `kanvas:create-workflow` | Command description |
| `kanvas:replay-receiver-workflow` | Command description |
| `kanvas:retry-webhook-call` | Command description |
| `kanvas:workflow-sync-actions` | Sync discovered #[WorkflowAction] classes into the actions catalog. |
| `workflow:copy-company-rules` | Copy rules (with their conditions and workflow activities) from one company to another |

**Integrations**

| Command | Purpose |
|---|---|
| `kanvas:create-integration-setup` | Create a new Integration |
| `kanvas:create-integration` | Create a new Integration |
| `kanvas:create-workflow-status` | Create a new set of status to an App |

