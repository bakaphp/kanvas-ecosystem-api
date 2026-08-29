# Console / CLI Architecture

This is a diagnostic guide produced by exploring how Artisan console commands are
registered, structured, and executed in this repository. It's a Laravel 11 app, so the
console layer follows Laravel conventions, but the domain-driven layout (`src/Domains/...`
consumed by `app/Console/Commands/...`) and a few repo-specific conventions are worth
documenting explicitly.

## 1. Entry point and registration

- **`artisan`** (repo root) is the standard Laravel CLI bootstrap script: it boots
  `bootstrap/app.php`, resolves `Illuminate\Contracts\Console\Kernel` from the container,
  and hands off to `$kernel->handle(...)`.
- **`bootstrap/app.php`** binds `App\Console\Kernel::class` as the concrete implementation
  of `Illuminate\Contracts\Console\Kernel`. This is the only console kernel in the app —
  there's no per-domain kernel.
- **`app/Console/Kernel.php`** is the actual registration point. Its `commands()` method
  (overridden with `#[Override]`) does two things:

  ```php
  protected function commands()
  {
      $this->load(__DIR__ . '/Commands');

      require base_path('routes/console.php');
  }
  ```

  1. `$this->load(__DIR__ . '/Commands')` — Laravel's `Kernel::load()` recursively scans
     `app/Console/Commands/` for PHP classes that extend `Symfony\Component\Console\Command`
     (which `Illuminate\Console\Command` extends) and auto-registers every one it finds as
     an Artisan command, using each class's own `protected $signature`. **There is no manual
     command list to maintain** — dropping a new `*Command.php` class anywhere under that
     tree (in any nested subfolder) is sufficient to register it.
  2. `require base_path('routes/console.php')` — a second, closure-based registration path
     for trivial one-off commands (`Artisan::command('name', function () {...})`). In this
     repo that file only defines the stock `inspire` command; every real command in the
     project is a class, not a closure.

- There is **no other command-registration path** in the codebase: no domain service
  provider calls `$this->commands([...])`, and no `src/Domains/*` package registers its own
  commands directly — every command lives under `app/Console/Commands` and is picked up by
  the single `$this->load(...)` call above. Domain packages under `src/` provide the
  business logic (actions, services, DTOs) that the command classes in `app/` call into.

## 2. Command discovery — what's actually registered

Everything under `app/Console/Commands/` is scanned, but not every `.php` file there is a
command:

| File type | Count | Registered as Artisan command? |
|---|---|---|
| Classes extending `Illuminate\Console\Command` (or a subclass of it, e.g. `KanvasImportCommand extends Laravel\Scout\Console\ImportCommand`) | 308 | ✅ Yes |
| `*Schedule` helper classes (e.g. `NervousSystemSchedule`, `LeadFollowUpSchedule`) | 4 | ❌ No — plain PHP classes with a static `register(Schedule $schedule)` method, see §4 |
| `Concerns/*` traits (e.g. `InteractsWithVinSolutionCompanies`) | 1 | ❌ No — shared logic mixed into commands via `use` |

Total: 313 PHP files under `app/Console/Commands/`, of which 308 are live commands.

Commands are organized by domain folder, mirroring the `src/Domains/*` structure:

| Folder | Commands | Notes |
|---|---|---|
| `Connectors/` | 141 | By far the largest group — one subfolder per third-party integration (44 connector subfolders: Shopify, NetSuite, Salesforce, Zoho, VinSolution, DriveCentric, Recombee, ESim, Movipass, PromptMine, ...). Naming is per-connector, not standardized (`kanvas:shopify-...`, `kanvas:netsuite-...`, `azul:test-mtls`, etc). |
| `NervousSystem/` | 27 | Agent runtime health, ledger maintenance, capability expiry, plan/task sweeps, tool catalog sync — the "operational nervous system" mentioned in the README. |
| `Intelligence/` | 26 | Agents, follow-up, knowledge indexing, leads/AI-mode, messaging, usage rollups. |
| `Ecosystem/` | 19 | Companies, users, global system modules, notifications/email templates. |
| `Inventory/` | 16 | Product/variant backfills, recommendation benchmarking, cross-env export/import. |
| `Setup/` | 11 | Per-domain bootstrap commands (`kanvas-{domain}:setup`), see §5. |
| `Guild/` (CRM) | 10 | People/organization dedupe, imports, reporting. |
| `Workflows/` | 9 | Workflow actions, integrations, receivers, webhook retries. |
| `Social/` | 8 | Messaging backfills, feeds, counters. |
| `Search/` | 7 | Algolia/Typesense/Scout indexing. |
| `Support/` | 6 | Cross-cutting ops tooling (daily report, dashboard fields, Lighthouse Redis cache). |
| `Souk/` (commerce) | 6 | Orders, carts, payments. |
| `Scribe/` (accounting) | 5 | Invoice aging, snapshot backfills, vendor approvers. |
| `AccessControl/` | 5 | Roles/abilities (Bouncer-backed permissions). |
| `Movipass/` | 4 | Company region/warehouse backfills. |
| `Lead/` | 3 | Follow-up dispatch/summary. |
| `Event/` | 2 | Booking slot generation/reminders. |
| `Analytics/` | 2 | Usage reports. |
| root (`app/Console/Commands/*.php`) | 6 | Top-level Kanvas-wide commands: `kanvas:setup-ecosystem`, `kanvas:app-create`, `kanvas:status`, `kanvas:version`, `kanvas:import` (Scout), `kanvas:ecosystem-updates`. |

### Signature/naming conventions

Command signatures aren't machine-enforced, but a strong `kanvas:` (or domain-scoped
`kanvas-{domain}:`) prefix convention dominates:

```
223  kanvas:*                (general-purpose, cross-domain)
 17  kanvas-inventory:*
  9  kanvas-guild:*
  7  nervous-system:* / kanvas:nervous-system:*
  6  kanvas-social:*
  4  kanvas-souk:* / kanvas-movipass:* / scribe:* / intelligence:*
  …  a handful of connector-specific prefixes (azul:, netsuite:, paso-rapido:, …)
```

Signatures range from bare (`kanvas:version`) to argument/option-heavy, multi-line
signatures for connector backfills, e.g.:

```php
protected $signature = 'kanvas:guild-apollo-people-sync {app_id} {company_id} {total=150} {perPage=50}
    {--order=desc : Sort people by id (asc|desc)}
    {--cooldown=3 : Days to wait before retrying a person Apollo had no data for}
    {--force : Ignore the revalidation window and no-data cooldown; re-enrich everyone}
    ...';
```

Almost every command is multi-tenant aware: the vast majority take `{app_id}` and/or
`{company_id}` as the first arguments, since Kanvas is a multi-app, multi-company
ecosystem and nearly every operation needs to be scoped to one.

## 3. Command structure and shared conventions

- **Base class**: every command extends `Illuminate\Console\Command` directly (one
  exception: `KanvasImportCommand` extends Laravel Scout's `ImportCommand`). There is
  **no repo-wide abstract base command class** — shared behavior is composed via traits,
  not inheritance.
- **`KanvasJobsTrait`** (`src/Baka/Traits/KanvasJobsTrait.php`) is the most common trait
  pulled into commands. It provides `overwriteAppService(AppInterface $app)`, which
  rebinds the scoped `Apps` singleton (and the Bouncer permission scope via
  `overWriteAppPermissionService`) to the app the command is currently operating on. This
  is the standard way a CLI command (which has no HTTP request to resolve "current app"
  from) tells the rest of the container which tenant it's acting as.
- **Domain-specific concern traits** exist for connectors with shared resolution logic,
  e.g. `Connectors/VinSolution/Concerns/InteractsWithVinSolutionCompanies` (resolves an
  explicit comma-separated company list, or falls back to every company configured with a
  VinSolution dealer id).
- **Thin commands, fat domain layer**: command `handle()` methods are typically small —
  parse args/options, resolve the `Apps`/`Companies` models, call into `Kanvas\{Domain}\Actions\*`
  classes under `src/Domains/`, and report progress/results via `$this->info()/error()/table()`.
  Business logic lives in the domain `Actions`, not in the command class.
- **Return codes**: commands consistently return `self::FAILURE` / `self::SUCCESS` (or
  `0`/`1`) so orchestration (see §5) and CI can check exit codes.
- **Command chaining via `Artisan::call()`**: several top-level commands orchestrate other
  commands rather than duplicating logic — most notably `kanvas:setup-ecosystem`
  (`KanvasSetupCommand`), which runs a scripted sequence of `migrate`, `db:seed`, and
  `kanvas:*` commands, stopping and reporting the failing step if any exit code is
  non-zero.

## 4. Scheduling (`schedule()`)

`App\Console\Kernel::schedule()` registers recurring jobs against Laravel's
`Illuminate\Console\Scheduling\Schedule`. The class has an explicit house rule stated in
its own docblock:

> Domain-grouped schedules live in `App\Console\Schedules\*` — extract the next domain
> into its own class as soon as its entries hit 3+ here, so this method stays a thin
> dispatcher rather than a god-list.

In practice this means:

- Small/one-off schedule entries (health checks, a handful of Ecosystem/Social/Souk/
  Connector cron jobs) are inlined directly in `Kernel::schedule()`.
- Once a domain accumulates 3+ scheduled commands, it gets its own `{Domain}Schedule`
  class with a static `register(Schedule $schedule): void` method, called from
  `Kernel::schedule()`. Four such classes currently exist:
  - `NervousSystemSchedule` (`app/Console/Commands/NervousSystem/Schedules/`) — the
    largest, covering agent runtime health, ledger archival, plan/capability sweeps, the
    daily-learning digest pipeline, and dashboard/pulse metric rollups. Its docblock
    contains a full timing map with the reasoning for each stagger offset (e.g. hourly
    jobs deliberately land on `:05`/`:10`/`:15` instead of `:00` to avoid a thundering herd).
  - `LeadFollowUpSchedule` (`app/Console/Commands/Lead/Schedules/`)
  - `ScribeSchedule` (`app/Console/Commands/Scribe/Schedules/`)
  - `AnalyticsSchedule` (`app/Console/Commands/Analytics/Schedules/`)
- Most schedule entries use `->withoutOverlapping()`, and cluster-sensitive ones add
  `->onOneServer()` to guard against duplicate execution across replicas; a few
  SSH/ingest-heavy jobs also add `->runInBackground()` so the scheduler process itself
  doesn't block on them.

## 5. Setup / orchestration commands

`app/Console/Commands/Setup/` holds one bootstrap command per domain
(`kanvas-{domain}:setup {app_id} {user_id} {company_id} ...`, e.g. `kanvas-inventory:setup`,
`kanvas-guild:setup`, `kanvas-social:setup`, `kanvas-souk:setup`, `kanvas-hr:setup`,
`kanvas-scribe:setup`, `kanvas-action-engine:setup`, `kanvas-event:setup`). These seed the
domain-specific defaults a new company needs (e.g. `kanvas-hr:setup` seeds default leave
types; `kanvas-scribe:setup` seeds a country-aware Chart of Accounts and opens the year's
fiscal periods).

The top-level `kanvas:setup-ecosystem` command (`App\Console\Commands\KanvasSetupCommand`)
is the entry point that stitches the whole database + baseline data together in one call:
it guards itself with `Schema::hasTable('migration')` (skips if already set up), then runs
a scripted list of per-database `migrate` calls, `db:seed`, and a handful of `kanvas:*`
bootstrap commands via `Artisan::call()`, aborting on the first non-zero exit code. This is
exactly what CI runs (see §6) to build a fresh database before the test suite.

## 6. Where these commands actually run

- **CI** (`.github/workflows/tests.yml`): after `composer install` and creating the
  per-domain MySQL databases, the job runs, in order:
  1. `php artisan kanvas:setup-ecosystem`
  2. `php artisan kanvas:create-integration shopify --config=... --handler=...`
  3. `php artisan kanvas:workflow-sync-actions`
  4. `php artisan kanvas:nervous-system:sync-tools`
  5. `php artisan kanvas:nervous-system:check-tool-drift`
  6. `php artisan kanvas:intelligence:sync-agent-types`
  7. `vendor/bin/paratest --testsuite=...` (the actual test run)

  Steps 4–5 are a notable pattern: `SyncAgentToolsCommand` scans the codebase for classes
  annotated with the `#[AgentTool(name: ..., category: ...)]` attribute (via
  `AgentToolDiscoveryService`) and upserts them into the `nervous_system_tools` catalog
  table; `CheckAgentToolDriftCommand` then re-runs discovery and **fails the build** if the
  catalog and the on-disk `#[AgentTool]` classes disagree (missing rows, orphaned rows, or
  stale metadata). The command's own docblock explains why this is a CI gate and not just
  a warning: a tool that exists in code but has no catalog row makes the agent's
  `capability_lookup` incorrectly answer "nobody has built this."
- **Docker Compose** (`docker-compose.1.x.yml` / `docker-compose.development.yml`):
  - The scheduler runs as `php artisan schedule:work` in the local/dev compose files (a
    long-running foreground process that fires due tasks every minute).
  - Queues follow a **one `queue:work` process per queue** convention — there's a separate
    compose service per queue (`--queue=workflow`, `--queue=agent-runtime`,
    `--queue=product-enrichment`, `--queue=agent-chat`, `--queue imports` ×4 replicas,
    `--queue scout` ×3 replicas, `--queue=batch-logger`, `--queue=agent-task-worker`,
    etc.), rather than one worker listening on many queues. This gives each queue an
    independent replica count / parallelism ceiling.
- **Helm** (`helm/templates/cronjob-laravel-scheduler.yaml`): production instead runs
  `php artisan schedule:run` as a Kubernetes CronJob (fired externally on a 1-minute
  cadence), rather than a long-lived `schedule:work` process — the container-orchestrated
  equivalent of the same scheduler.
- **Deploy workflows** (`.github/workflows/ec2-deploy.yaml` and similar) call a small,
  fixed set of commands after each deploy: `artisan lighthouse:cache`,
  `lighthouse:clear-cache all`, `config:cache`, `octane:reload`, and (on the primary
  target only) `kanvas:workflow-sync-actions`.

## 7. Diagnostics: `kanvas:status`

`App\Console\Commands\KanvasStatusCommand` (`kanvas:status {--backlog=10000}`) is a
one-shot health snapshot: it checks database connections, Redis, and pending/failed job
counts across an explicit hardcoded list of ~20 known queues (default, kanvas-social,
notifications, workflow, agent-runtime, agent-chat, agent-task-worker,
nervous-system-project, scheduled-actions, scribe-aging, ...), flagging any queue whose
backlog exceeds `--backlog`. This overlaps in spirit with the Spatie Health package
(`RunHealthChecksCommand`, scheduled `everyMinute()` in the Kernel) which the app extends
with its own custom check, `Kanvas\Health\Checks\QueueSizeCheck` — a configurable
per-queue pending/failed threshold check with separate warning vs. failure levels, built
on top of the same `Queue::connection($conn)->size($queue)` primitive.

## 8. Testing conventions

Command tests live under `tests/{Domain}/...CommandTest.php` and use Laravel's console
testing helpers rather than calling `handle()` directly:

```php
$this->artisan('kanvas-inventory:backfill-variant-rating-from-category', ['app_id' => $id])
    ->expectsOutputToContain('Done.')
    ->expectsOutputToContain('Processed=3')
    ->assertExitCode(0);
```

Tests typically set up their own tenant fixtures (an `Apps` + `Users` + `Companies` trio,
often via the same domain `Setup` action the `kanvas-{domain}:setup` command itself calls)
inside `DatabaseTransactions`-backed test classes, then assert on both the command's
console output and the resulting database state.

## Summary of key findings

1. **Single registration point, zero maintenance overhead**: `App\Console\Kernel::commands()`
   auto-loads every `Command` subclass under `app/Console/Commands/` recursively — adding a
   command is just adding a class in the right domain folder, no manual list to update.
2. **~308 registered commands**, overwhelmingly organized by business domain
   (`Connectors/` alone is 141, one subfolder per third-party integration), following the
   same domain taxonomy as `src/Domains/*`.
3. **No shared abstract base command** — composition via traits (`KanvasJobsTrait` for
   tenant-scoping, connector-specific `Concerns/*` traits) rather than inheritance.
4. **Kernel::schedule() is a thin dispatcher by house rule**: once a domain's schedule
   grows past 2 entries it's extracted to its own `{Domain}Schedule::register()` class
   (`NervousSystemSchedule`, `LeadFollowUpSchedule`, `ScribeSchedule`, `AnalyticsSchedule`).
5. **Setup orchestration**: `kanvas:setup-ecosystem` scripts migrations + seeding + a chain
   of `kanvas-{domain}:setup` bootstrap commands via `Artisan::call()`, and is the exact
   command CI runs to build a fresh test database.
6. **Attribute-driven tool discovery + CI drift gate**: `#[AgentTool]`-annotated classes
   are discovered, synced into a DB catalog (`kanvas:nervous-system:sync-tools`), and then
   verified for drift as a hard CI gate (`kanvas:nervous-system:check-tool-drift`) so the
   agent capability catalog can never silently fall out of sync with the code.
7. **Deployment topology mirrors CLI structure**: one `queue:work --queue=X` compose
   service per queue, `schedule:work` locally/in dev vs. a `schedule:run` Kubernetes
   CronJob in Helm/production, and a fixed post-deploy command sequence in each deploy
   workflow.
