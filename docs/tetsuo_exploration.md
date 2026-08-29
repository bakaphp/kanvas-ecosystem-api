# Tetsuo Exploration: The Kanvas Console / CLI Nervous System

> *A comprehensive diagnostic and architectural guide to the sandbox backend environment's
> console layer — how it is built, how commands are registered and executed, what already
> exists, and the design patterns worth stealing.*

**Codebase:** `kanvas-ecosystem-api` (Laravel 11 + GraphQL, multi-domain, multi-tenant)
**Scope of this report:** `app/Console/`, `routes/console.php`, `bootstrap/app.php`, `artisan`,
plus the supporting infrastructure in `src/Baka`, `src/Kanvas/Health`, and `app/Providers` that
the console layer leans on.
**Command count at time of writing:** **313** Artisan command classes across **19** top-level
domain folders, plus 1 closure-based command in `routes/console.php`.

---

## Table of Contents

1. [Executive Summary](#1-executive-summary)
2. [The CLI Runtime: How `artisan` Boots](#2-the-cli-runtime-how-artisan-boots)
3. [Command Registration & Discovery](#3-command-registration--discovery)
4. [Anatomy of a Kanvas Command](#4-anatomy-of-a-kanvas-command)
5. [The Multi-Tenancy Trap and Its Fix — `KanvasJobsTrait`](#5-the-multi-tenancy-trap-and-its-fix--kanvasjobstrait)
6. [The Scheduler: From God-List to Domain Registries](#6-the-scheduler-from-god-list-to-domain-registries)
7. [Command Inventory by Domain](#7-command-inventory-by-domain)
8. [Diagnostic & Health Tooling](#8-diagnostic--health-tooling)
9. [Setup / Bootstrap Tooling](#9-setup--bootstrap-tooling)
10. [Migration & Backfill Tooling](#10-migration--backfill-tooling)
11. [Utility & Maintenance Tooling](#11-utility--maintenance-tooling)
12. [Notably Elegant Patterns](#12-notably-elegant-patterns)
13. [Testing the Console Layer](#13-testing-the-console-layer)
14. [Conventions & Gotchas Checklist](#14-conventions--gotchas-checklist)
15. [Appendix: Full Domain Folder Map](#15-appendix-full-domain-folder-map)

---

## 1. Executive Summary

Kanvas does not use Phalcon CLI Tasks. Its console layer is **stock Laravel Artisan**, but the
way the *codebase* has grown that layer is anything but stock. What was clearly once a normal
`app/Console/Commands/*.php` flat folder has evolved — under real production pressure — into a
**domain-partitioned command architecture** that mirrors the business domains of the whole
platform (`Ecosystem`, `Inventory`, `Guild` (CRM), `Souk` (Commerce), `Social`, `Workflows`,
`Intelligence`, `NervousSystem`, `Scribe` (Accounting), `Connectors`, …).

Three things make this console layer worth documenting in depth:

1. **A hard-learned multi-tenancy discipline.** Every command that resolves a specific `Apps`
   tenant is required (by written convention, and partially by test) to rebind the app-scoped
   container binding and the Bouncer permission scope before doing any work. Skipping this has
   caused at least one real production incident (silently dropping ~90% of tenants from a
   digest — see [§5](#5-the-multi-tenancy-trap-and-its-fix--kanvasjobstrait)).
2. **Schedule-as-code with an explicit anti-god-object guardrail.** `Kernel::schedule()` carries
   a code comment instructing future authors to extract any domain once it hits 3+ scheduled
   entries into its own `*Schedule` registry class — and the codebase actually follows through
   on it (`NervousSystemSchedule`, `LeadFollowUpSchedule`, `ScribeSchedule`,
   `AnalyticsSchedule`).
3. **CLI commands as first-class product/ops tooling**, not just scripts: a colorized
   infrastructure dashboard (`kanvas:status`), a CI-gateable search-quality evaluator
   (`kanvas-inventory:evaluate-product-discovery`), and an interactive AI chat REPL
   (`agent:inventory-chat`) all live in the same `app/Console/Commands/` tree as routine
   backfills.

---

## 2. The CLI Runtime: How `artisan` Boots

The entrypoint is the standard Laravel `artisan` script at the repo root:

```php
// artisan
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$status = $kernel->handle(new ArgvInput, new ConsoleOutput);
$kernel->terminate($input, $status);
exit($status);
```

`bootstrap/app.php` binds the console kernel interface to Kanvas's own kernel:

```php
$app->singleton(
    Illuminate\Contracts\Console\Kernel::class,
    App\Console\Kernel::class
);
```

`App\Console\Kernel` (`app/Console/Kernel.php`) extends
`Illuminate\Foundation\Console\Kernel` and overrides exactly two protected hooks:

| Hook | Purpose |
|---|---|
| `schedule(Schedule $schedule)` | Registers every cron-driven command/job (`php artisan schedule:run`, typically fired every minute by a system cron or the `schedule:work` process). |
| `commands()` | Registers ad-hoc commands: auto-discovers everything under `app/Console/Commands/**` and additionally requires `routes/console.php` for closure-based commands. |

```php
protected function commands()
{
    $this->load(__DIR__ . '/Commands');
    require base_path('routes/console.php');
}
```

`$this->load(...)` is Laravel's directory auto-loader — it recursively scans the folder,
`require`s every PHP file, and any subclass of `Illuminate\Console\Command` becomes available to
Artisan automatically (registration is by class discovery, not an explicit list). This is why the
domain folders under `Commands/` can nest arbitrarily deep (e.g.
`Commands/Connectors/PromptMine/…`, `Commands/NervousSystem/Ledger/…`) without any manifest file
needing to be touched.

`routes/console.php` is the closure-based escape hatch for trivial commands — today it only
holds Laravel's stock `inspire` demo command:

```php
Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
```

No Kanvas-specific commands live here; every real command is a full class under
`app/Console/Commands/`.

---

## 3. Command Registration & Discovery

Because registration is **directory-based auto-discovery**, "adding a command" is purely a
matter of:

1. Dropping a new class under `app/Console/Commands/<Domain>/[<SubDomain>/]YourCommand.php`.
2. Giving it a `protected $signature` and a `handle()` method.

No `Kernel.php` edit is required unless the command also needs to be **scheduled** (cron-driven),
in which case it's added to `schedule()` or to a domain `*Schedule` registry class
(see [§6](#6-the-scheduler-from-god-list-to-domain-registries)).

Naming is domain-namespaced but not perfectly uniform (organic growth, not a strict linter rule).
Observed signature prefixes:

- `kanvas:*` — cross-cutting / ecosystem-level (`kanvas:setup-ecosystem`, `kanvas:status`, `kanvas:version`)
- `kanvas-<domain>:*` — domain-scoped (`kanvas-inventory:*`, `kanvas-guild:*`, `kanvas-souk:*`, `kanvas-social:*`, `kanvas-movipass:*`)
- `kanvas:<domain>:*` (colon-nested) — newer domains lean on this instead (`kanvas:nervous-system:*`, `kanvas:intelligence:*`)
- `nervous-system:*` / `intelligence:*` / `movipass:*` / `netsuite:*` — a handful of commands drop the `kanvas` prefix entirely
- `agent:*` — Intelligence/agent-facing interactive tools (`agent:create`, `agent:inventory-chat`)

The Inventory domain's own `app/Console/Commands/Inventory/CLAUDE.md` documents this
inconsistency implicitly by just giving the exact invocable string for every command rather than
assuming a pattern — a pragmatic acknowledgment that "grep the folder" beats "guess the prefix."

---

## 4. Anatomy of a Kanvas Command

A representative, minimal command (`app/Console/Commands/Ecosystem/KanvasAppCreateKeyCommand.php`):

```php
class KanvasAppCreateKeyCommand extends Command
{
    protected $signature = 'kanvas:app-key {name} {app_id} {email}';
    protected $description = 'Create a new Kanvas App Key';

    public function handle()
    {
        $app  = Apps::getById($this->argument('app_id'));
        $user = Users::getByEmail($this->argument('email'));

        UsersRepository::belongsToThisApp($user, $app);

        $appKey = (new CreateAppKeyAction(new AppKeyInput(...)))->execute();

        $this->info('App Key created successfully: ' . $appKey->client_id);
        $this->info('Secret: ' . $appKey->client_secret_id);
    }
}
```

Notice the command itself contains almost **no business logic** — it parses CLI input, resolves
domain models, and delegates to an **Action** class (`CreateAppKeyAction`). This Action-oriented
style is consistent across the whole codebase: commands are thin adapters over the same Action /
Service classes that the GraphQL mutations call, so CLI and API stay behaviorally identical.

Commands that touch a specific tenant additionally:

```php
use Baka\Traits\KanvasJobsTrait;

class SomeCommand extends Command
{
    use KanvasJobsTrait;

    public function handle()
    {
        $app = Apps::getById($this->argument('app_id'));
        $this->overwriteAppService($app);   // <-- mandatory, see §5
        // ... do work scoped to $app
    }
}
```

---

## 5. The Multi-Tenancy Trap and Its Fix — `KanvasJobsTrait`

Kanvas is multi-tenant: the same process can run work for many different `Apps` (tenants), and a
lot of framework/library code (Bouncer roles, container-bound singletons) resolves against
*whichever app happens to be currently bound in the container*. A CLI process boots with some
default app bound; if a command iterates over 100 different tenants without re-binding, every
tenant after the first silently runs under the wrong (or no) permission scope.

The fix lives in `src/Baka/Traits/KanvasJobsTrait.php`:

```php
trait KanvasJobsTrait
{
    public function overwriteAppService(AppInterface $app): void
    {
        App::scoped(Apps::class, fn () => $app);
        $this->overWriteAppPermissionService($app);
    }

    public function overwriteAppServiceLocation(CompaniesBranches $branch): void
    {
        App::scoped(CompaniesBranches::class, fn () => $branch);
    }

    public function overWriteAppPermissionService(AppInterface $app): void
    {
        Bouncer::scope()->to(RolesEnums::getScope($app));
    }
}
```

It does two things per call:

- Re-binds the `Apps` singleton in the container so anything that type-hints `Apps` (repositories,
  policies, custom-field resolvers) resolves the *current* tenant, not whatever was bound first.
- Re-scopes **Bouncer** (the roles/permissions package) to that tenant's scope, so
  `Role::firstOrFail()`, `Bouncer::assign()`, etc. don't silently query the wrong tenant's roles.

### The written rule (`.claude/CLAUDE.md`)

> *"EVERY command, anywhere under `app/Console/Commands/` (every domain, no exceptions), that
> resolves or operates on a specific `Apps` MUST `use Baka\Traits\KanvasJobsTrait` and call
> `$this->overwriteAppService($app)`** — once after resolving the app (single-app commands), or
> per-iteration inside the loop (multi-app commands), before doing any work."*

Exemptions are explicit and narrow: global `apps_id=0` catalog syncs, row-chunk backfills with no
concrete `Apps` model, ledger archive/maintenance, and pure queue dispatchers (the dispatched job
re-binds its own scope in `handle()`).

### The real incident this guards against

`SendDailyLearningDigestCommand` fanned a daily digest out over ~90 `(app, company)` tuples
without rebinding scope on each iteration. Every non-primary tenant silently received **zero**
emails for weeks — roles, assignments, and recipients were all individually correct, so nothing
*looked* broken; the command exited `0` every day. The regression is now covered by
`tests/Intelligence/NervousSystem/SendDailyLearningDigestCommandTest.php`, and the canonical
"do it right" reference is `EnsureAgentReportRoleCommand`.

**This is the single most important convention in the entire console layer** — it appears in the
root `CLAUDE.md`, the `Inventory/CLAUDE.md`, and is enforced ad-hoc throughout every domain's
commands (`ProjectHeartbeatCommand`, `EvaluateProductDiscoveryCommand`,
`CustomFieldsRedisRegeneration`, `KanvasLighthouseRedisCacheCommand`, and dozens more all call
`overwriteAppService`).

---

## 6. The Scheduler: From God-List to Domain Registries

`App\Console\Kernel::schedule()` is the single method Laravel calls to build the cron schedule.
In most Laravel apps this method balloons into an unreadable wall of `$schedule->command(...)`
calls. Kanvas heads that off with an explicit, *load-bearing* code comment sitting directly above
the method:

```php
// Domain-grouped schedules live in App\Console\Schedules\* — extract the
// next domain into its own class as soon as its entries hit 3+ here, so
// this method stays a thin dispatcher rather than a god-list.
protected function schedule(Schedule $schedule)
{
    // Platform health (Spatie).
    $schedule->command(RunHealthChecksCommand::class)->everyMinute();
    $schedule->command(DispatchQueueCheckJobsCommand::class)->everyMinute();

    // Ecosystem / Social / Souk / Connectors — small enough to inline today.
    $schedule->command(DeleteUsersRequestedCommand::class)->dailyAt('00:00');
    $schedule->command(DetectSignupAnomalyCommand::class)->hourly()->withoutOverlapping()->onOneServer();
    ...

    // Event — roll the booking window forward daily ...
    $schedule->command(GenerateUpcomingTimeSlotsCommand::class, ['--prune'])->dailyAt('01:30')->withoutOverlapping();

    // Nervous System — agent lifecycle, ledger maintenance, pulse + dashboard
    // rollups, plan + capability sweeps, the daily-learning loop.
    NervousSystemSchedule::register($schedule);

    // Lead follow-up v2 — hourly fan-out + daily summary.
    LeadFollowUpSchedule::register($schedule);

    // Scribe — daily AR-aging fan-out.
    ScribeSchedule::register($schedule);

    // Analytics — weekly Engage usage leaderboard.
    AnalyticsSchedule::register($schedule);
}
```

Once a domain earns 3+ schedule entries, it's extracted into a `final class *Schedule` with a
single `public static function register(Schedule $schedule): void` method, living next to the
commands it schedules (`app/Console/Commands/<Domain>/Schedules/<Domain>Schedule.php`). Four such
registries exist today:

| Registry | File | Registers |
|---|---|---|
| `NervousSystemSchedule` | `NervousSystem/Schedules/NervousSystemSchedule.php` | 14 entries — agent lifecycle, ledger archival, pulse/dashboard rollups, the daily-learning pipeline, plan heartbeats |
| `LeadFollowUpSchedule` | `Lead/Schedules/LeadFollowUpSchedule.php` | Hourly fan-out + daily summary for CRM lead follow-ups |
| `ScribeSchedule` | `Scribe/Schedules/ScribeSchedule.php` | Daily AR-aging evaluation |
| `AnalyticsSchedule` | `Analytics/Schedules/AnalyticsSchedule.php` | Weekly Engage usage report |

### What makes `NervousSystemSchedule` particularly good documentation-as-code

Its class docblock is a **timing map** — a plain-English cron table with rationale baked in,
maintained by hand alongside the actual `$schedule->command(...)` calls it describes:

```
 * ── Sub-hourly (interval-based, TZ-irrelevant) ────────────────────
 *   every 5m   DetectStalledPlanTasks    (idempotent ledger sweep)
 *   every 10m  CheckAgentRuntimeHealth   (per-deployment SSH ping)
 *
 * ── Hourly, staggered to avoid :00 thundering herd ────────────────
 *   :00     ExpireCapabilities           (cheap UPDATE sweep)
 *   :05     RefreshAgentLiveCounters     (full-fleet DB scan)
 *   :10     CollectAgentSessionTranscripts (SSH ingest, runs in bg)
 *
 * ── Daily (America/New_York) ──────────────────────────────────────
 *   00:30 NY   RollupDailyDashboardMetrics
 *   00:35 NY   RollupDailyPulseMetrics      (+5min after dashboard)
 *   02:00 NY   ArchiveOldLedgerEvents
 *   ...
```

Every job also documents *why* its specific minute offset was chosen (avoiding a `:00`
"thundering herd", staying inside a downstream queue's drain budget, respecting a dependency's
completion time). Timezone handling is explicit too: interval-based jobs (`everyFiveMinutes()`,
`hourlyAt()`) are deliberately left UTC-anchored since they're TZ-irrelevant, while
business-semantics jobs (`dailyAt('06:04')->timezone('America/New_York')`) are pinned to NY time
so "yesterday" means the same thing to the job as it does to a US-based tenant.

Nearly every scheduled entry across all four registries also chains defensive scheduling
modifiers: `withoutOverlapping()`, `onOneServer()` (single-node lock across replicas), and
`runInBackground()` (don't block the scheduler process on a slow job) — applied deliberately per
job based on whether it fans out across many tenants, is idempotent, or is I/O heavy (SSH
ingestion, mail fan-out).

---

## 7. Command Inventory by Domain

Command classes under `app/Console/Commands/`, grouped by top-level folder:

| Domain folder | # commands | Focus |
|---|---:|---|
| `Connectors/` | 141 | Every third-party integration: Acumatica, Apollo, Azul, CMLink, Credit700, DealerAppCenter, DealerSocket, DriveCentric, ESim, Elead, Google, Hermes, InAppPurchase, Intras, Mailgun, Mercury, Microsoft, Movipass, NetSuite, Notifications, OpenClaw, PasoRapido, ProductEnrichment, PromptMine, Recombee, SalesAssist, Salesforce, ScrapingDog, ScrapperApi, Shopify, SuperCarros, TeeTime, Tookan, UniversalAssistance, VAuto, VentaMobile, VinSolution, VoiceBridge, WooCommerce, WordPress, Yusen, Zoho |
| `NervousSystem/` | 27 | Agent lifecycle, ledger, learning pipeline, plans, provisioning, tools, scheduling — the AI-agent orchestration core |
| `Intelligence/` | 26 | Agents, follow-up, knowledge indexing, leads AI-mode, messaging, social generation, usage/cost rollups |
| `Ecosystem/` | 19 | Companies, notifications, users, system modules, app keys/email templates |
| `Inventory/` | 16 | Product discovery pipeline, cross-env export/import, attribute types, reporting |
| `Setup/` | 11 | One-shot per-domain tenant bootstrap commands |
| `Guild/` (CRM) | 10 | People/lead dedup, import, addresses, reporting |
| `Workflows/` | 9 | Receivers, integrations, statuses, webhook retry/replay |
| `Social/` | 8 | Feeds, message backfills, reactions, counters |
| `Search/` | 7 | Algolia/Typesense/Scout indexing across entities |
| `Support/` | 6 | Cross-cutting maintenance: fake migrations, Redis cache regen, demo data |
| `Souk/` (Commerce) | 6 | Orders, carts, payments, order statuses |
| `Scribe/` (Accounting) | 5 | Invoice aging, snapshot backfill, vendor approvers, PDF ingest |
| `AccessControl/` | 5 | Roles, abilities, ownership grants |
| `Movipass/` | 4 | Region/warehouse backfills, global region seeding |
| `Lead/` | 3 | Follow-up dispatch/summary + schedule registry |
| `Event/` | 2 | Booking time-slot generation, reminders |
| `Analytics/` | 2 | Engage usage report + schedule registry |
| top-level loose files | 6 | `KanvasSetupCommand`, `KanvasAppSetupCommand`, `KanvasEcosystemUpdates`, `KanvasStatusCommand`, `KanvasVersionCommand`, `KanvasImportCommand` |

`Connectors/` alone accounts for ~45% of all commands — a direct reflection of the platform's
positioning as an integration/orchestration layer across dozens of external systems (DMS
platforms for auto dealers, payment processors, CRM/lead sources, AI agent runtimes, e-commerce
platforms, etc).

---

## 8. Diagnostic & Health Tooling

### 8.1 `kanvas:status` — the one-command infra dashboard

`app/Console/Commands/KanvasStatusCommand.php` is the fastest way to answer *"is everything up?"*
without touching Grafana. It:

1. Pings **every per-domain database connection** (`mysql`, `ecosystem`, `inventory`, `social`,
   `crm`, `content_engine`, `workflow`, `action_engine`, `commerce`, `event`, `intelligence`,
   `accounting`) with `SELECT 1` and reports latency (or the truncated error) in a table.
2. Pings Redis and reports latency.
3. Reports **pending + failed job counts for every queue the platform uses** (24 named queues,
   from `default` to `product-discovery` to `scribe-pdf-ingest`), flagging any queue whose
   pending count crosses a `--backlog=10000` threshold in yellow, and any queue with failed jobs
   in red — using a single aggregate `GROUP BY queue` query against `failed_jobs`, deliberately
   avoiding `SELECT *` (each failed-job row carries a full serialized payload + stack trace, which
   would exhaust memory at scale).
4. Prints a final colorized verdict: `✓ ALL GOOD`, `! Infrastructure healthy, but queues are
   backed up`, or `✗ ISSUES DETECTED`, and returns a matching exit code — so it's usable both by
   a human and by a monitoring script.

```
Databases
+----------------+--------+-----------------+
| Connection     | Status | Latency / error |
+----------------+--------+-----------------+
| mysql          | up     | 3 ms            |
| inventory      | up     | 2 ms            |
...

Queues
+-------------------------+---------+--------+
| Queue                   | Pending | Failed |
+-------------------------+---------+--------+
| default                 | 12      | 0      |
| agent-runtime           | 481     | 2      |
...

  ✓ ALL GOOD — every connection is up and queues are draining.
```

**This command is guarded by a coverage-ratchet unit test** — see [§13](#13-testing-the-console-layer).

### 8.2 Spatie Health checks

`app/Providers/HealthProvider.php` wires Spatie's `spatie/laravel-health` package, registered
into the scheduler as `RunHealthChecksCommand` (every minute) and
`DispatchQueueCheckJobsCommand` (every minute):

```php
Health::checks([
    DatabaseCheck::new()->name('ecosystem'),
    DatabaseCheck::new()->name('inventory')->connectionName('inventory'),
    // ... one per domain database
    RedisCheck::new()->name('redis'),
    QueueSizeCheck::new()->name('queue-sizes')
        ->thresholds(['default' => 5000, 'scout' => 100000, 'agent-runtime' => 2000, ...])
        ->warnings(['default' => 1000, 'scout' => 100000, 'agent-runtime' => 500, ...]),
]);
```

`QueueSizeCheck` (`src/Kanvas/Health/Checks/QueueSizeCheck.php`) is a **custom Spatie health
check** — per-queue warning/failure thresholds, feeding results into Spatie's own
`EloquentHealthResultStore` (5-day retention) and (optionally) mail/Slack notifications on
failure. This is a persisted, historical sibling to the on-demand `kanvas:status` snapshot: one
is "show me right now," the other is "alert someone the moment it degrades and remember it
happened."

### 8.3 Product-discovery diagnostics

`kanvas-inventory:evaluate-product-discovery` (detailed in
[§12.3](#123-a-ci-gateable-search-quality-evaluator)) is effectively a domain-specific health
check for AI/search quality rather than infrastructure — same philosophy, different axis.

---

## 9. Setup / Bootstrap Tooling

### 9.1 `kanvas:setup-ecosystem` — the meta-bootstrap command

`app/Console/Commands/KanvasSetupCommand.php` is a single command that provisions an **entire
fresh Kanvas ecosystem** from nothing, by orchestrating a scripted sequence of other Artisan
commands via `Artisan::call()`:

```php
if (FacadesSchema::hasTable('migration')) {
    $this->info('Some migrations have already been run ... Skipping setup.');
    return;
}

$commands = [
    'migrate',
    'migrate --path database/migrations/Inventory/ --database inventory',
    'migrate --path database/migrations/Social/ --database social',
    'migrate --path database/migrations/Guild/ --database crm',
    'migrate --path database/migrations/Workflow/ --database workflow',
    'migrate --path database/migrations/Souk/ --database commerce',
    'migrate --path vendor/durable-workflow/workflow/src/migrations/ --database workflow',
    'migrate --path database/migrations/ActionEngine/ --database action_engine',
    'migrate --path database/migrations/Subscription/ --database mysql',
    'migrate --path database/migrations/Event/ --database event',
    'migrate --path database/migrations/Intelligence/ --database intelligence',
    'migrate --path database/migrations/Scribe/ --database accounting',
    'migrate --path database/migrations/HumanResources/ --database hr',
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
    if ($exitCode !== 0) { $this->error('Command failed: ' . $command); break; }
}
```

It is idempotent-guarded at the top (bails early if migrations already ran), migrates **12
distinct per-domain databases** in dependency order, seeds base data, then chains a further 7
Artisan sub-commands to build roles, storage disks, workflow statuses, abilities, and attribute
types — a single command that takes a bare database from zero to a working platform. This exact
sequence is also codified as a Composer script (`composer.json` → `migrate-all-kanvas`), so the
two representations stay in sync by convention.

### 9.2 Per-domain tenant setup commands (`Commands/Setup/`)

Once the *ecosystem* exists, each business domain has its own idempotent per-tenant
initializer, all following the identical shape — `{app_id} {user_id} {company_id}` (Event's
adds `--type=standard|full`) — resolve the three models, `overwriteAppService($app)`, delegate to
a domain `Setup` class, print a confirmation:

| Command | Domain |
|---|---|
| `kanvas-event:setup {app_id} {user_id} {company_id} [--type=]` | Event/booking |
| `kanvas-souk:setup {app_id} {user_id} {company_id}` | Commerce |
| `kanvas-inventory:setup` | Inventory |
| `kanvas-social:setup` | Social |
| `kanvas-guild:setup` | CRM |
| `kanvas-hr:setup` | Human Resources |
| `kanvas-action-engine:setup` | Action Engine (workflow rules) |
| `kanvas:filesystem-setup` | Storage disks |
| `kanvas:commerce-default-update` / `kanvas:inventory-default-update` | Default-data refresh for existing tenants |

### 9.3 Connector-specific setup commands

Several external integrations ship their own one-off setup commands, e.g.
`kanvas:dealersocket:setup`, `kanvas:voice-bridge:setup`, `kanvas:iap-webhook:setup`,
`kanvas:sa-setup-receivers` (SalesAssist), `NervousSystemToolSetupCommand` — each provisions
whatever webhook registrations, API credentials wiring, or default records that connector needs
before it can run.

---

## 10. Migration & Backfill Tooling

Distinct from Laravel's schema migrations (`database/migrations/`), this is a large family of
**data migration / backfill** commands — reshaping or populating already-live data, usually
because a new column, index, or subsystem was introduced after data already existed. Naming
converges on `backfill-*`, `migrate-*`, or `*-migration`.

Representative examples:

| Command | What it repairs/migrates |
|---|---|
| `kanvas:migrate-attribute-type` | Re-points `Attributes` rows from app-specific `AttributesTypes` onto the matching global (`apps_id=0`) type, **inside a DB transaction with explicit rollback on any exception or empty result at each stage** — a genuinely careful "test in prod safely" migration pattern (see snippet below). |
| `kanvas-inventory:region-migration` | Moves inventory regions into the Ecosystem domain. |
| `kanvas-inventory:backfill-variant-rating-from-category` | Recomputes `Variant.rating` from category weights — must run before judging search quality, since rating feeds ranking. |
| `kanvas:backfill-ledger-event-categories` | NervousSystem ledger event re-categorization. |
| `kanvas:backfill-lead-ai-mode-keys` | Intelligence lead AI-mode key backfill. |
| `kanvas:backfill-pulse-metrics` / `kanvas:backfill-dashboard-metrics` | Historical rollup backfills for the NervousSystem Pulse/Dashboard subsystems. |
| `kanvas-guild:backfill-people-driver-license` | CRM data enrichment backfill. |
| `kanvas-movipass:backfill-company-default-region` / `backfill-unpaid-orders` | Domain-specific Movipass data repairs. |
| `nervous-system:restore-ledger-events-from-archive` | The **inverse** of the archival job — restores events an over-aggressive archive swept away. |
| `Connectors/ProductEnrichment/BackfillProductEnrichmentCommand` | LLM-driven backfill: writes the `search_blurb` + facets each product should be discoverable by. |

`MigrateAttributeTypesCommand`'s transactional-with-rollback shape is a good template for any
future "rewire foreign keys en masse" migration:

```php
DB::beginTransaction();
try {
    $baseAttributes = AttributesTypes::where('apps_id', 0)->where('companies_id', 0)->get();
    if ($baseAttributes->isEmpty()) { $this->info('...'); DB::rollBack(); return; }
    // ... resolve matching per-app attribute types, remap slug -> new id, update rows ...
    // (never actually commits in this snippet — deliberately test-mode-only)
} catch (\Exception $e) {
    DB::rollBack();
    $this->error('An error occurred: ' . $e->getMessage());
}
```

The **ledger archive/restore pair** (`nervous-system:archive-old-ledger-events` /
`nervous-system:restore-ledger-events-from-archive`) is worth calling out as a complete,
reversible data-lifecycle system rather than a one-way backfill — see
[§12.4](#124-reversible-data-lifecycle-archive--restore).

---

## 11. Utility & Maintenance Tooling

| Command | Purpose |
|---|---|
| `kanvas:fake-migration {class}` | Inserts a row into Laravel's `migrations` table without running the migration — used to mark a migration "already applied" when the schema change was made by hand or via another path. |
| `kanvas:lighthouse-redis-cache {class} {app_id} {company_id?}` | Regenerates the Lighthouse (GraphQL) Redis cache for every row of an arbitrary model class, chunked, with per-entity timing logged. |
| `kanvas:customFields-redis-regeneration {app_id} {className}` | Same idea for the custom-fields cache — paginates an arbitrary model, calls `reCacheCustomFields()` if present. |
| `kanvas:seed-demo-data` | A **1,158-line** command that seeds ~6 months of fully cross-linked demo volume — CRM leads/deals → Commerce orders → Accounting invoices/bills/quotes/expenses with real Actions (not raw inserts) — for "mission-control demo" environments. |
| `kanvas:app-key {name} {app_id} {email}` / `kanvas:app-key-revoke` | Issue/revoke API client credentials for a tenant. |
| `kanvas:replay-receiver-workflow {receiver_id} {start_date?}` | Replays every **failed** webhook receiver call since an optional start date, with a live progress bar (`$this->output->createProgressBar(...)`). |
| `kanvas:import {model} [--app=] [--chunk=]` | Extends **Laravel Scout's own `ImportCommand`**, adding an `--app` option that resolves the tenant and registers a `MountedAppProvider` before delegating to `parent::handle()` — tenant-aware search reindexing without forking Scout's logic. |
| `kanvas:version` | Prints the running `AppEnums::VERSION` — the "am I on the build I think I'm on" sanity check. |

---

## 12. Notably Elegant Patterns

### 12.1 Extending a framework command instead of wrapping it

`KanvasImportCommand extends Laravel\Scout\Console\ImportCommand` rather than shelling out to it
or duplicating its logic:

```php
class KanvasImportCommand extends ImportCommand
{
    protected $signature = 'kanvas:import
        {model} {--app=} {--c|chunk=}';

    public function handle(Dispatcher $events)
    {
        $app = AppsRepository::findFirstByKey($this->option('app'));
        (new MountedAppProvider($app))->register();
        parent::handle($events);
    }
}
```

Multi-tenancy is bolted onto stock Scout behavior with **zero duplicated import logic** — the
override only does the one thing Scout doesn't know how to do (resolve which tenant's search
index to write into), then hands off to the parent implementation unmodified.

### 12.2 An interactive AI chat REPL as an Artisan command

`agent:inventory-chat` (`Intelligence/Agents/AgentInventoryChatCommand.php`) turns `artisan` into
a live chat client against the platform's own Inventory AI agent:

```php
$agent = AgentInventoryAssistance::make()->forUser($user);
$this->info('Inventory Assistant ready. Type "exit" to quit.');

while (true) {
    $input = $this->ask('You');
    if (in_array(strtolower(trim($input)), ['exit', 'quit', 'q'])) { break; }
    $response = $agent->prompt($input);
    $this->line('<fg=cyan>Assistant:</> ' . $response->text);
}
```

It resolves `--app-id` / `--user-id` / `--company-id` options, calls `overwriteAppService()` and
`Auth::loginUsingId()` so the agent runs with the exact same scoping and auth context it would
have in production, then just... talks to you in the terminal. It's a genuinely fast way to
manually sanity-check agent behavior without spinning up the full GraphQL/frontend stack.

### 12.3 A CI-gateable search-quality evaluator

`kanvas-inventory:evaluate-product-discovery` scores the AI-driven product search/recommendation
pipeline against a human-judged "golden set" of `{query, relevant_product_ids}` cases, computing
**recall@k** and **MRR (Mean Reciprocal Rank)** — standard information-retrieval evaluation
metrics, implemented from scratch in a console command:

```php
$recalls[] = $hits === [] ? 0.0 : count(array_intersect($returned, $relevant)) / count($relevant);
$reciprocalRanks[] = $firstHitRank === null ? 0.0 : 1 / $firstHitRank;
...
if ($minRecall !== null && $meanRecall < (float) $minRecall) {
    $this->error(sprintf('Mean recall %.3f is below the %.3f threshold.', $meanRecall, (float) $minRecall));
    return self::FAILURE;   // <-- non-zero exit, so this can gate a CI pipeline
}
```

It even guards against a *self-fooling* failure mode: if any golden-set case is still flagged
`unjudged` (its expected IDs were auto-filled from whatever discovery already returned when the
fixture was drafted), scoring it would trivially "pass" by comparing discovery against itself —
so the command refuses to score unless every case is judged, or `--allow-unjudged` is passed
explicitly. It also force-flushes the result cache before running
(`Cache::flush()`), because "the whole point of this command is to catch a regression the cache
would otherwise hide."

This turns "did my prompt-tuning change make search better or worse?" from a matter of anecdote
into a number a script can gate on — genuinely elegant use of a CLI command as a quality-assurance
harness. Its sibling `kanvas-inventory:scaffold-golden-set` even auto-drafts the fixture from real
shopper queries, explicitly reminding the operator that "every id it writes is a guess — prune it
before scoring anything."

### 12.4 Reversible data lifecycle: archive ⇄ restore

`nervous-system:archive-old-ledger-events` and its mirror-image
`nervous-system:restore-ledger-events-from-archive` treat "delete old rows" as a **two-way door**
instead of a one-way one: events older than a retention window (configurable, `--retention-days`,
`--disk`) are serialized to long-term storage (S3 by default) and only *then* pruned from MySQL,
each archive run tracked with an id, byte size, and event count so a restore command can reverse
it later. Compare this to the much more common (and much riskier) pattern of a bare
`DELETE ... WHERE created_at < ?` migration command with no way back.

### 12.5 The scheduler's self-imposed anti-god-object rule

Already covered in depth in [§6](#6-the-scheduler-from-god-list-to-domain-registries), but worth
restating as a pattern in its own right: **a comment enforcing an extraction threshold, actually
followed**. It's rare to see a "refactor when X" rule left in a docblock that the codebase has
visibly obeyed four separate times rather than let rot into aspiration-only documentation.

### 12.6 Documentation co-located with the code it governs (`CLAUDE.md` network)

Rather than one central architecture doc, the repo scatters small, scoped `CLAUDE.md` files next
to the code they describe — `app/Console/Commands/Inventory/CLAUDE.md` documents the exact
ordering dependency between the four product-discovery commands (enrich → index → search → score)
and calls out the single most common false-positive ("indexing is broken" is usually just
`SCOUT_QUEUE=true` with no worker running). The root `.claude/CLAUDE.md` explicitly indexes all of
these scoped docs so an agent (human or AI) only loads the ones relevant to the code being
touched. This is effectively a lightweight, load-on-demand knowledge base living in git.

### 12.7 Commands as thin adapters over Actions

Every command surveyed for this report — from `KanvasAppCreateKeyCommand` to
`ProjectHeartbeatCommand` to the domain `Setup` commands — follows the same shape: parse
arguments → resolve models → delegate to an `Action`/`Service` class → report the result. Business
logic essentially never lives directly in `handle()`. This means the exact same domain logic a CLI
operator triggers is the logic a GraphQL mutation or a queued job would trigger — the console
layer is a UI on top of the domain layer, not a parallel implementation of it.

---

## 13. Testing the Console Layer

`tests/Unit/Console/KanvasStatusQueueCoverageTest.php` is a small but sharp example of tests
enforcing cross-file consistency rather than just per-class behavior:

```php
final class KanvasStatusQueueCoverageTest extends TestCaseUnit
{
    private const COMPOSE_FILES = [
        'docker-compose.yml',
        'docker-compose.development.yml',
        'docker-compose.1.x.yml',
    ];

    public function testEveryComposeQueueIsReportedByStatusCommand(): void
    {
        $reported = new ReflectionClass(KanvasStatusCommand::class)->getConstant('QUEUES');
        $missing = array_values(array_diff($this->composeQueues(), $reported));
        $this->assertSame([], $missing, 'Queues have workers but are not reported by kanvas:status: ' . implode(', ', $missing));
    }

    private function composeQueues(): array
    {
        // parses every `--queue=a,b,c` flag out of the 3 docker-compose files
    }
}
```

It **reads the actual `docker-compose*.yml` files**, extracts every `--queue=` flag any
`queue:work` worker service declares, and asserts that `KanvasStatusCommand::QUEUES` reports all
of them via reflection on the class constant. The doc comment above the test spells out exactly
why: *"A queue with a worker but no row in the status table is invisible — it can back up or
accumulate failures for weeks with `kanvas:status` still printing 'ALL GOOD'."* Adding a new
worker to a compose file and forgetting to add its queue name to the status command now **fails
CI** instead of silently degrading an operational dashboard. This is a coverage ratchet for a
console command's own completeness, not for its business logic — a pattern worth reusing for any
other "dashboard of everything" command.

---

## 14. Conventions & Gotchas Checklist

For anyone adding a new command to this codebase:

- [ ] Does it resolve a specific `Apps`? → `use KanvasJobsTrait;` + `$this->overwriteAppService($app)`
      immediately after resolving it (once for single-app commands, per-iteration for multi-app
      fan-outs).
- [ ] Is it long-running / LLM-backed / per-row expensive? → give it a `--limit` and a `--sync`
      (or equivalent dry-run) option so it can be sampled before being trusted at scale.
- [ ] Can one bad row kill the whole run? → catch per-item, `report($e)`, count failures, print
      the failure count at the end. Half the value of a backfill command is surviving data that's
      already wrong.
- [ ] Should it run on a schedule? → add it to `Kernel::schedule()`, and if its domain already has
      3+ entries there, extract/extend a `*Schedule` registry class instead of inlining further.
- [ ] Does it fan out across tenants/servers? → decide deliberately on `withoutOverlapping()`,
      `onOneServer()`, and `runInBackground()` — don't just copy whatever the neighboring line has.
- [ ] Is it destructive (deletes/archives data)? → prefer an archive-then-prune shape with a
      restore counterpart over a bare irreversible delete (see §12.4).
- [ ] Would a future dashboard/status command need to know this exists (e.g. a new queue worker)?
      → check whether an existing coverage test (like `KanvasStatusQueueCoverageTest`) needs
      updating, or whether one should be added.

---

## 15. Appendix: Full Domain Folder Map

```
app/Console/
├── Kernel.php                       # schedule() + commands() — the only two hooks Laravel calls
├── Commands/
│   ├── KanvasSetupCommand.php       # full-ecosystem bootstrap (migrate × 12 DBs, seed, roles, ...)
│   ├── KanvasAppSetupCommand.php    # interactive new-tenant creation wizard
│   ├── KanvasEcosystemUpdates.php   # version-gated ecosystem upgrade routine
│   ├── KanvasImportCommand.php      # tenant-aware Scout import (extends Scout's own command)
│   ├── KanvasStatusCommand.php      # colorized DB/Redis/queue health dashboard
│   ├── KanvasVersionCommand.php     # prints AppEnums::VERSION
│   ├── AccessControl/               # roles, abilities, ownership grants
│   ├── Analytics/                   # usage reporting + Schedules/AnalyticsSchedule.php
│   ├── Connectors/                  # 40+ third-party integrations (largest domain, 141 commands)
│   ├── Ecosystem/                   # companies, users, notifications, system modules, app keys
│   ├── Event/                       # booking time slots, reminders
│   ├── Guild/                       # CRM: leads, people, organizations, dedup
│   ├── Intelligence/                # AI agents, follow-up, knowledge, leads AI-mode, usage
│   ├── Inventory/                   # product discovery pipeline, cross-env movement, reporting
│   ├── Lead/                        # lead follow-up v2 + Schedules/LeadFollowUpSchedule.php
│   ├── Movipass/                    # Movipass-specific backfills/seeds
│   ├── NervousSystem/               # agent orchestration core: Agents, Learning, Ledger, Metrics,
│   │                                #   Plans, Provisioning, Scheduling, Tools, Schedules/
│   ├── Scribe/                      # accounting: invoice aging, snapshots, PDF ingest, Schedules/
│   ├── Search/                      # Algolia/Typesense/Scout indexing
│   ├── Setup/                       # per-domain tenant bootstrap (Event/Souk/Social/Guild/HR/...)
│   ├── Social/                      # feeds, message backfills, reactions
│   ├── Souk/                        # commerce: orders, carts, payments
│   └── Support/                     # cross-cutting maintenance (fake migrations, cache regen, demo data)
├── ... (313 command classes total)
routes/console.php                   # closure-based commands (only `inspire` today)
bootstrap/app.php                    # binds App\Console\Kernel as the Console Kernel singleton
artisan                              # the CLI entrypoint script
src/Baka/Traits/KanvasJobsTrait.php  # overwriteAppService() — the multi-tenancy scope fix
src/Kanvas/Health/Checks/QueueSizeCheck.php  # custom Spatie Health check for queue backlogs
app/Providers/HealthProvider.php     # registers all Spatie Health checks
```

---

*Compiled by an autonomous exploration pass over the `1.x` branch of `kanvas-ecosystem-api`.
Every command, file path, and code excerpt cited above was read directly from the repository at
exploration time.*
