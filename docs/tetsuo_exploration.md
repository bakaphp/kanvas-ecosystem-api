# Tetsuo Console Environment Exploration

> A diagnostic guide to the Kanvas Ecosystem API's Artisan console: how it's
> wired, what's registered, the patterns worth stealing, and how to actually
> run any of it. Compiled by exploring `app/Console/`, `routes/console.php`,
> `config/health.php`, `.github/workflows/`, and the domain sources those
> commands call into.

## 1. Console/CLI Architecture

Kanvas is a Laravel 13 application, so the console entrypoint is the standard
`artisan` script plus `app/Console/Kernel.php`. Two things make this
particular Kernel worth reading closely: how it *registers* commands, and how
it keeps its `schedule()` method from becoming an unreadable wall of cron
lines.

### Registration

```php
// app/Console/Kernel.php
protected function commands()
{
    $this->load(__DIR__ . '/Commands');
    require base_path('routes/console.php');
}
```

- `$this->load(__DIR__ . '/Commands')` recursively scans **every** `*.php`
  file under `app/Console/Commands/` and auto-registers any class extending
  `Illuminate\Console\Command`. Nothing needs to be manually added to a
  `$commands` array — drop a file in the right namespace/folder and
  `php artisan list` picks it up. This is why the tree has grown to **313
  command classes** without the Kernel itself getting any bigger.
- `routes/console.php` is the escape hatch for one-off closure-based commands
  (Laravel's `Artisan::command(...)` style). Today it only defines the
  stock `inspire` command — every real command in this codebase is a full
  class, not a closure, which keeps them testable and reusable in the
  scheduler.
- Commands are namespaced by **domain**, not by verb:
  `App\Console\Commands\{Domain}\{...}\{Verb}Command`. Sub-namespaces exist
  for large domains (`Connectors\{Provider}`, `NervousSystem\{Agents,Ledger,
  Plans,Tools,...}`, `Intelligence\{Agents,FollowUp,Knowledge,Leads,...}`).
  This mirrors the `src/Domains/` layout used for the rest of the app and
  makes `find app/Console/Commands/{Domain}` a reliable way to discover
  everything a domain can do from the CLI.

### The schedule() method: domain-grouped, not a god-list

The `schedule()` method has a deliberate note at the top of it:

```php
// Domain-grouped schedules live in App\Console\Schedules\* — extract the
// next domain into its own class as soon as its entries hit 3+ here, so
// this method stays a thin dispatcher rather than a god-list.
```

In practice this means: once a domain accumulates 3+ scheduled entries, they
get extracted into a `{Domain}Schedule` final class with a single static
`register(Schedule $schedule): void` method, and the Kernel just calls it:

```php
NervousSystemSchedule::register($schedule);
LeadFollowUpSchedule::register($schedule);
ScribeSchedule::register($schedule);
AnalyticsSchedule::register($schedule);
```

These live at `app/Console/Commands/{Domain}/Schedules/{Domain}Schedule.php`
— e.g. `NervousSystem/Schedules/NervousSystemSchedule.php`,
`Lead/Schedules/LeadFollowUpSchedule.php`,
`Scribe/Schedules/ScribeSchedule.php`,
`Analytics/Schedules/AnalyticsSchedule.php`. Anything with fewer than 3
scheduled entries for a domain stays inlined directly in `Kernel::schedule()`
(Ecosystem, Social, Souk, a couple of Connectors).

**Notably good pattern:** every one of these `*Schedule` classes carries a
docblock "timing map at a glance" table documenting *why* each cron
expression was chosen — timezone anchoring rationale, stagger offsets to
avoid a thundering herd at `:00`, dependency ordering between jobs in a
pipeline, and `withoutOverlapping()` / `onOneServer()` / `runInBackground()`
choices explained inline. See `NervousSystemSchedule` for the fullest example
— it documents an entire "daily-learning pipeline" (record → summarize →
digest) with explicit buffer-time justifications between each step.

## 2. Command Discovery

313 command classes exist under `app/Console/Commands/`, organized into 17
top-level domain folders plus 6 ungrouped top-level `Kanvas*` commands.
Broad counts:

| Folder | Commands | What it covers |
|---|---|---|
| `Connectors/` | 141 (44 providers) | Third-party integrations — see §3 |
| `NervousSystem/` | 27 | Agent lifecycle, ledger, plans, tools, learning |
| `Intelligence/` | 26 | AI agents, knowledge, follow-up, messaging, usage |
| `Ecosystem/` | 19 | Companies, users, email templates, system modules |
| `Inventory/` | 16 | Product/catalog setup and maintenance |
| `Setup/` | 11 | Per-domain module bootstrap (`kanvas-*:setup`) |
| `Guild/` | 10 | CRM: people, leads, duplicates, exports |
| `Workflows/` | 9 | Workflow rules, integrations, receivers |
| `Social/` | 8 | Social module setup/maintenance |
| `Search/` | 7 | Scout/Algolia/Typesense indexing |
| `Souk/` | 6 | Commerce/orders |
| `Support/` | 6 | Ops utilities (fake migrations, reports, cache) |
| `AccessControl/` | 5 | Roles/abilities (Bouncer) |
| `Scribe/` | 5 | Invoice/AR aging |
| `Movipass/` | 4 | (top-level; see also `Connectors/Movipass`, 12 more) |
| `Lead/` | 3 | Lead follow-up dispatch/summary |
| `Event/` | 2 | Booking/time-slot generation |
| `Analytics/` | 2 | Usage reporting |
| top-level `Kanvas*.php` | 6 | Ecosystem setup, status, version, import |

### Top-level utility commands

| Command | Purpose |
|---|---|
| `kanvas:setup-ecosystem` | Bootstrap the base ecosystem (apps, keys, roles) — the first command run after a fresh clone (see §4). |
| `kanvas:app-create` | Create a new Kanvas App record. |
| `kanvas:app-ecosystem-update` | Run ecosystem-wide update routines. |
| `kanvas:status` | **The** diagnostic command — see below. |
| `kanvas:version` | Print the running `AppEnums::VERSION`. |
| `kanvas:import` | Bulk import utility. |

### `kanvas:status` — the diagnostic snapshot command

`app/Console/Commands/KanvasStatusCommand.php` is the closest thing to a
purpose-built "is everything OK?" command in the codebase:

```
php artisan kanvas:status [--backlog=10000]
```

It pings every per-domain database connection (`mysql`, `ecosystem`,
`inventory`, `social`, `crm`, `content_engine`, `workflow`, `action_engine`,
`commerce`, `event`, `intelligence`, `accounting`) with `SELECT 1` and reports
latency or the (truncated) error, pings Redis, and then renders a table of
every application queue (`default`, `kanvas-social`, `notifications`,
`agent-runtime`, `ledger`, `scribe-aging`, ... 24 queues total) with pending
and failed-job counts, flagging any queue whose backlog exceeds
`--backlog`. It exits `1` if any database/Redis connection is down, so it's
scriptable as a health gate, not just a human-readable report.

### Health checks (`Spatie\Health`)

Separately from `kanvas:status`, the app registers **Spatie Health** checks in
`app/Providers/HealthProvider.php` (`DatabaseCheck` per connection,
`RedisCheck`, and a custom `QueueSizeCheck` — see §3) and runs them via the
package's own commands, scheduled every minute in the Kernel:

```php
$schedule->command(RunHealthChecksCommand::class)->everyMinute();
$schedule->command(DispatchQueueCheckJobsCommand::class)->everyMinute();
```

Results are persisted to the `health_check_result_history_items` table
(`config/health.php` → `EloquentHealthResultStore`, 5-day retention) and
exposed at `/oh-dear-health-check-results` when the Oh Dear integration is
enabled. Where `kanvas:status` is a human-run snapshot, the Spatie checks are
the always-on machine-facing counterpart.

### Setup / bootstrap commands (`kanvas-{domain}:setup`)

Each major domain module ships its own idempotent setup command, run once per
company to provision default data (roles, categories, system fields, etc.):

| Command | Domain |
|---|---|
| `kanvas-inventory:setup {app_id} {user_id} {company_id}` | Inventory |
| `kanvas-social:setup {app_id} {user_id} {company_id}` | Social |
| `kanvas-guild:setup {app_id} {user_id} {company_id}` | CRM (Guild) |
| `kanvas-souk:setup {app_id} {user_id} {company_id}` | Commerce (Souk) |
| `kanvas-action-engine:setup {app_id} {user_id} {company_id} {actions?}` | ActionEngine |
| `kanvas-event:setup {app_id} {user_id} {company_id} {--type=}` | Event/booking |
| `kanvas-hr:setup {app_id} {user_id} {company_id}` | Human Resources |
| `kanvas-scribe:setup ...` | Scribe (accounting) |
| `kanvas:filesystem-setup {app_uuid?}` | Filesystem defaults |
| `kanvas:inventory-default-update {app_uuid}` | Inventory default-catalog refresh |
| `kanvas:commerce-default-update {app_uuid}` | Commerce default-catalog refresh |

> Note: `README.md`'s quick-start still documents the older, shorter aliases
> (`php artisan inventory:setup`, `social:setup`, `guild:setup`) — the actual
> registered signatures today are the `kanvas-{domain}:setup` form shown
> above. Worth a follow-up doc fix outside this exploration's scope.

### NervousSystem — agent operations control plane

The largest single-purpose cluster after Connectors. Grouped by sub-domain:

- **Agents/** — `CheckAgentRuntimeHealthCommand` (SSH health ping per
  deployment), `FlagDeadAgentDeploymentsCommand`, `ExpireCapabilitiesCommand`,
  `RefreshAgentLiveCountersCommand`, `EnsureOrchestratorAgentCommand`,
  `EnsureAgentReportRoleCommand` (Bouncer role bootstrap, per-app).
- **Ledger/** — `ArchiveOldLedgerEventsCommand`,
  `BackfillLedgerEventCategoriesCommand`,
  `RestoreLedgerEventsFromArchiveCommand` — maintenance for the append-only
  event ledger that powers agent memory/audit.
- **Learning/** — `RecordAgentDailyCyclesCommand`,
  `SummarizeAgentDailyLearningCommand`, `SendDailyLearningDigestCommand` — a
  three-stage daily pipeline (record → summarize → email digest), each step
  timed with a documented buffer window in `NervousSystemSchedule`.
- **Plans/** — `ProjectHeartbeatCommand`, `DetectStalledPlanTasksCommand`,
  `NudgeInactivePlansCommand`, `SweepStaleIntakeCommand`,
  `SyncKanbanDeploymentsCommand` — the "plan" lifecycle (agent task boards).
- **Tools/** — see §3, the `#[AgentTool]` discovery/sync/drift-check trio.
- **Provisioning/** — `ProvisionDefaultConsumerModulesCommand`,
  `SeedSwarmDemoDataCommand`.
- **Scheduling/** — `SweepScheduledActionsCommand` (per-minute dispatcher for
  scheduled agent reminders/tasks).
- **Metrics/** — `BackfillPulseMetricsCommand`, `SyncModelPricingCommand`.

### Connectors — one namespace per third-party integration

`Connectors/` alone holds 141 commands across 44 provider namespaces
(Acumatica, NetSuite, Shopify, Salesforce, Zoho, Movipass, ESim, Apollo,
Google, Mercury, WooCommerce, VinSolution, ScrapperApi, PromptMine, ...).
Typical command shapes per connector: a `Pull*`/`Download*` (import from the
external system), a `Sync*` (push/reconcile), and occasionally a
`Scheduled*Sync` wrapper that fans the sync out per opted-in company. See §3
for why this is a deliberately consistent pattern rather than 44 different
styles.

### Intelligence — AI agent domain (distinct from NervousSystem)

Where NervousSystem is the operational control plane (health, ledger,
scheduling, tools), `Intelligence/` holds the agent *content* commands:
`CreateAgentCommand`, `CreateAgentTypeCommand`, `SyncAgentTypesCommand`,
knowledge indexing (`IndexKnowledgeCommand`), lead AI-mode toggles
(`SetLeadAiModeCommand`), follow-up engagement, and usage rollups
(`CollectAgentDeploymentUsageCommand`, `RollupLocalAgentUsageCommand`).

### Support — grab-bag ops utilities

`kanvas:fake-migration {class}` (mark a migration as already-run without
executing it — useful when a migration ran manually against a database),
`kanvas:daily-report`, `kanvas:dashboard-default-field`,
`kanvas:lighthouse-redis-cache` (warm the Lighthouse GraphQL schema cache for
a class/app/company), `kanvas:seed-demo-data` (seeds ~6 months of linked
CRM/Commerce/Accounting demo data for a "mission-control" demo — a genuinely
fun one to know about if you ever need a populated sandbox company fast).

## 3. Cool Structures — Patterns Worth Copying

### a) Attribute-driven tool discovery + a CI drift check

The standout pattern in this codebase. Agent "tools" (things an AI agent can
call) are declared with a PHP 8 attribute on the tool class itself:

```php
// src/Domains/Intelligence/Agents/Attributes/AgentTool.php
#[Attribute(Attribute::TARGET_CLASS)]
final class AgentTool
{
    public function __construct(
        public readonly ?string $name = null,
        public readonly ?string $description = null,
        public readonly ?string $category = null,
        public readonly array $frameworks = [],
        public readonly string $toolType = 'system',
        public readonly string $version = '1.0.0',
        public readonly ?array $requiresPermission = null,
    ) {}
}
```

`AgentToolDiscoveryService` reflects over the codebase to find every class
carrying this attribute (deriving sensible defaults for anything left null —
framework from namespace, category from folder). Three console commands
consume that discovery service, each with a single job:

1. `php artisan kanvas:nervous-system:sync-tools [--force] [--prune]` —
   upserts every discovered tool into the `nervous_system_tools` catalog
   table (global, `apps_id=0`). `--prune` removes duplicate catalog rows
   that nothing references, while always keeping one canonical survivor per
   handler so the catalog never silently loses a tool.
2. `php artisan kanvas:nervous-system:check-tool-drift [--allow-stale]` —
   **a genuinely elegant CI gate.** It diffs the classes discovered on disk
   against the catalog rows and fails the build if any tool is *missing*
   (class exists, no catalog row — silently invisible to agents), *orphaned*
   (catalog row, class deleted) or *stale* (name/description/frameworks
   drifted from the attribute). The docblock on this command is worth
   reading verbatim for *why* this matters: a tool with no catalog row
   doesn't error — it just makes an agent confidently answer "nobody has
   built this" about a capability that already exists.
3. `php artisan nervous-system:tool-setup` — an interactive wizard to
   register a tool manually when attribute-driven discovery isn't the right
   fit.

This trio is wired directly into CI (`.github/workflows/tests.yml`):
`sync-tools` runs immediately after ecosystem setup, then
`check-tool-drift` runs right after it — so every push proves the tool
catalog and the actual `#[AgentTool]` classes agree, on a database built
fresh from that commit.

### b) A custom Spatie Health check as a first-class domain object

`src/Kanvas/Health/Checks/QueueSizeCheck.php` extends Spatie Health's `Check`
base class to add **per-queue backlog thresholds** (fail *and* warning
levels) that `DatabaseCheck`/`RedisCheck` don't give you out of the box:

```php
QueueSizeCheck::new()
    ->name('queue-sizes')
    ->thresholds(['default' => 5000, 'agent-runtime' => 2000, ...])
    ->warnings(['default' => 1000, 'agent-runtime' => 500, ...]);
```

Registered once in `app/Providers/HealthProvider.php` alongside per-domain
`DatabaseCheck` instances (one per DB connection) and a `RedisCheck`. This
means the "is Kanvas healthy" question is answered by two complementary,
non-overlapping layers: `kanvas:status` (on-demand, human-readable,
exit-code-gated) and the Spatie Health checks (always-on, persisted,
notifiable, Oh-Dear-exportable).

### c) Domain-grouped `Schedule` classes as thin, self-documenting registries

Covered in §1 — worth calling out again as a structural pattern: instead of
one Kernel method accumulating every cron line, each domain gets its own
`final class {Domain}Schedule { public static function register(Schedule $s) }`
with a docblock timing map. It keeps `Kernel::schedule()` readable as a table
of contents, and keeps the *reasoning* for a schedule (why 08:15 and not
hourly, why `onOneServer()`, why a 26-minute buffer between two steps)
attached to the code that owns it rather than buried in a PR description.

### d) One namespace per connector, with consistent command shapes

44 third-party integrations, each isolated under
`App\Console\Commands\Connectors\{Provider}\`, following the same rough
shape (`Pull*`, `Sync*`, `Download*`, `Scheduled*SyncCommand`). This
consistency means finding "how do I pull data from X" is always
`find app/Console/Commands/Connectors/X` rather than hunting through a flat
command list, and a new connector can copy an existing sibling's shape rather
than inventing a new one.

### e) `KanvasJobsTrait::overwriteAppService()` — a hard rule enforced by convention, not the type system

235 of the 313 commands (and any job that resolves a specific `Apps` model)
use `Baka\Traits\KanvasJobsTrait` and call
`$this->overwriteAppService($app)` as the first line of work per app. This
exists because the app container binding for `Apps::class` and Bouncer's
scope are both **process-global state** — in a long-running queue worker (or
a command that loops over every app), the previous iteration's app/scope
otherwise leaks into the next one, producing scope-mismatched
`ModelNotFoundException`s that surface nowhere near the actual bug. It's not
enforced by the compiler; it's enforced by review convention and documented
with a real named incident
(`app/Console/Commands/NervousSystem/Agents/EnsureAgentReportRoleCommand.php`
docblock, and the project's `.claude/CLAUDE.md`) — a good example of a
footgun the team chose to document loudly at the call site instead of trying
to abstract away.

## 4. Environment Setup — Running Diagnostics & Domain Tasks

### Local / Docker development

Per `README.md`, the full bootstrap sequence is:

```bash
docker compose up --build -d
docker-compose ps                       # confirm containers are healthy
docker exec -it mysqlLaravel /bin/bash  # create the per-domain databases
docker exec -it phpLaravel bash         # then, inside the php container:
php artisan key:generate
php artisan kanvas:setup-ecosystem      # bootstrap the base ecosystem
composer migrate-inventory && php artisan inventory:setup   # per README (see note in §2 re: actual signature)
composer migrate-social     && php artisan social:setup
composer migrate-crm        && php artisan guild:setup
```

`composer.json` defines one `migrate-{domain}` script per database
connection (`migrate-inventory`, `migrate-social`, `migrate-crm`,
`migrate-workflow`, `migrate-commerce`, `migrate-action-engine`,
`migrate-events`, `migrate-intelligence`, ...) plus a `migrate-all-kanvas`
composite that runs every domain's migrations against its own connection in
one shot — a direct reflection of the multi-database, per-domain-connection
architecture (`ecosystem`/default, `inventory`, `social`, `crm`, `workflow`,
`commerce`, `action_engine`, `event`, `intelligence`, `accounting`, `hr`).

Once bootstrapped, the app can run under Laravel Octane:

```bash
php artisan octane:start --server=swoole --host=0.0.0.0 --port=8000
```

### Running the scheduler

Locally / in the 1.x and development docker-compose files, a dedicated
`laravel-scheduler` service runs:

```bash
php artisan config:cache && php artisan schedule:work
```

(`schedule:work` is the long-running, no-crontab-needed equivalent of
`schedule:run` for containerized environments.) The Helm deployment
(`helm/templates/cronjob-laravel-scheduler.yaml`) instead uses a Kubernetes
CronJob invoking `php artisan schedule:run` on a fixed interval — the
container/Helm split is intentional: `schedule:work` needs one long-lived
process, `schedule:run` needs an external trigger, and Helm's cron primitive
is the trigger there.

Queue workers follow a **one dedicated `queue:work` service per queue**
convention (`docker-compose.yml` defines ~15 separate services —
`agent-runtime-queue`, `ledger-queue`, `scribe-aging-queue`, `lead-follow-ups-queue`,
etc.) rather than one worker consuming many queues, so each queue's
volume/retry/timeout profile is isolated (documented explicitly in
`.claude/CLAUDE.md`).

### CI: how diagnostics are actually exercised on every push

`.github/workflows/tests.yml` is the clearest end-to-end example of the
setup sequence in practice — it spins up MySQL/Redis, creates every
per-domain database, then runs, **in order**:

```bash
composer install --prefer-dist --no-interaction --no-progress
php artisan kanvas:setup-ecosystem
php artisan kanvas:create-integration shopify --config='...' --handler='...'
php artisan kanvas:workflow-sync-actions
php artisan kanvas:nervous-system:sync-tools        # populate the tool catalog
php artisan kanvas:nervous-system:check-tool-drift  # ← diagnostic gate, fails the build on drift
php artisan kanvas:intelligence:sync-agent-types
vendor/bin/paratest --testsuite=<suite> --processes=4
```

`static-analysis.yml` runs a lighter variant of the same ecosystem setup
(`kanvas:setup-ecosystem`) before invoking `phpstan`, since some
static-analysis rules need real container bindings to resolve.

### Ad-hoc diagnostics against a running environment

```bash
# Inside the php container (or via docker exec, per tests/CLAUDE.md convention):
php artisan kanvas:status                 # DB/Redis/queue snapshot, --backlog=N to tune the flag threshold
php artisan kanvas:version                # confirm the deployed AppEnums::VERSION
php artisan kanvas:nervous-system:check-tool-drift --allow-stale   # tool-catalog vs. code drift check
```

Per `tests/CLAUDE.md`, tests themselves are always run **inside** the
`phpkanvas-ecosystem` container, never on the host:

```bash
docker exec -it phpkanvas-ecosystem bash -c "cd /var/www/html && php vendor/bin/paratest --testsuite=ActionEngine"
```

## Summary

The console layer here is less "a folder of scripts" and more a second,
explicit API surface for the same domains the GraphQL layer exposes:
auto-discovered by convention (`$this->load(...)`), namespaced 1:1 with
`src/Domains/`, and — for the newest domains (NervousSystem, Intelligence) —
paired with genuinely thoughtful diagnostics: a status command that's
exit-code-scriptable, a Spatie Health layer with a custom per-queue-threshold
check, and an attribute-driven catalog with its own CI-enforced drift
detector. The `Schedule`-class-per-domain pattern and the
`overwriteAppService()` convention are both worth propagating to any new
domain added to this console.
