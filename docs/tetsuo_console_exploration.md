# Tetsuo Console Environment Exploration

This document is the result of exploring the Kanvas Ecosystem API's console
(CLI) environment and its queue/job infrastructure. It covers:

1. [Console architecture overview](#1-console-architecture-overview)
2. [Command reference](#2-command-reference)
3. [Queue implementation](#3-queue-implementation)
4. [Testing the queue end-to-end](#4-testing-the-queue-end-to-end)
5. [Appendix: file map](#5-appendix-file-map)

---

## 1. Console architecture overview

Kanvas is a **Laravel** application, so its CLI environment is the standard
Laravel **Artisan** console — there is no Phalcon/Symfony-standalone/custom
runner layered on top of it.

```
artisan                          # CLI entry point (require bootstrap/app.php, run the console kernel)
bootstrap/app.php                # Binds Illuminate\Contracts\Console\Kernel => App\Console\Kernel
app/Console/Kernel.php            # Registers commands + the cron schedule
app/Console/Commands/**           # ~230 first-party Artisan commands, grouped by domain
app/Console/Commands/*/Schedules  # Per-domain "thin dispatcher" classes for the scheduler
routes/console.php                 # Closure-based commands (Artisan::command(...))
```

### Bootstrapping

`artisan` (project root) is the standard Laravel front controller:

```php
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$status = $kernel->handle(new ArgvInput, new ConsoleOutput);
$kernel->terminate($input, $status);
```

`bootstrap/app.php` builds the `Illuminate\Foundation\Application` container
and binds `App\Console\Kernel` as the console kernel implementation (mirrors
the HTTP kernel binding used for web requests).

### Command registration

`App\Console\Kernel::commands()` (`app/Console/Kernel.php`) does two things:

```php
protected function commands()
{
    $this->load(__DIR__ . '/Commands');   // auto-discovers every Command class under app/Console/Commands/**
    require base_path('routes/console.php'); // closure-based commands, e.g. `inspire`
}
```

- **`$this->load(__DIR__ . '/Commands')`** recursively scans
  `app/Console/Commands/` and auto-registers every class that extends
  `Illuminate\Console\Command`. This is why there is no giant manual `$commands`
  array — dropping a new `SomethingCommand.php` file anywhere under that tree
  (in any nested domain folder) is enough for `php artisan list` to pick it up.
- **`routes/console.php`** is for one-off closure commands that don't warrant
  a full class (currently just Laravel's stock `inspire` command).

Commands are organized by domain, mirroring `src/Domains/`:

```
app/Console/Commands/
├── AccessControl/          Roles, abilities, permission templates
├── Connectors/              One folder per external integration
│   ├── Acumatica/  Shopify/  NetSuite/  Zoho/  VinSolution/  ...
├── Ecosystem/               Apps, companies, users, system modules
├── Event/                   Booking/time-slot generation
├── Guild/                   CRM: leads, people, duplicates
├── Intelligence/            Agents, follow-ups, knowledge indexing
├── Inventory/                Product discovery/search/import
├── Lead/                    Lead follow-up dispatch + schedule
├── Movipass/                 Roadside/impound domain-specific fixups
├── NervousSystem/            Agent runtime, ledger, plans, provisioning
├── Scribe/                    AR invoice ingest/aging
├── Search/                    Algolia/Typesense/Scout indexing
├── Setup/                     Idempotent per-domain "seed the defaults" commands
├── Social/                     Feeds, messages
├── Souk/                      Orders, carts, payments
├── Support/                    Cross-cutting ops tooling (this PR adds one here)
├── Workflows/                  Receivers, webhooks, integrations
├── Kernel.php
└── Kanvas*.php                 Top-level ops commands (status, version, setup, import)
```

### Scheduling

`App\Console\Kernel::schedule()` wires Laravel's cron scheduler
(`Illuminate\Console\Scheduling\Schedule`). To keep this method from becoming
a god-list, domain-specific schedules are extracted into their own
`Schedules/` classes as soon as a domain accumulates 3+ scheduled entries,
e.g.:

- `App\Console\Commands\NervousSystem\Schedules\NervousSystemSchedule::register($schedule)`
- `App\Console\Commands\Lead\Schedules\LeadFollowUpSchedule::register($schedule)`
- `App\Console\Commands\Scribe\Schedules\ScribeSchedule::register($schedule)`
- `App\Console\Commands\Analytics\Schedules\AnalyticsSchedule::register($schedule)`

The scheduler itself is driven in production by the `laravel-scheduler`
service in the compose files, which just runs:

```bash
php artisan schedule:work
```

`schedule:work` runs in the foreground and fires due commands every minute —
the containerized equivalent of a system cron entry calling
`php artisan schedule:run` every minute.

### PHP version / execution model

The app also runs under **Laravel Octane (Swoole)** for the HTTP server
(`php artisan octane:start --server=swoole`, see `config/octane.php` and the
`php` service in the compose files) — but Artisan console commands and queue
workers are plain, non-Octane PHP processes (`php artisan <command>` /
`php artisan queue:work`). This matters for connectors: SDK clients must never
be cached in `static` properties because Octane's worker-persistence model can
leak app/tenant context across requests, but that specific footgun does not
apply to one-shot console commands or per-job queue worker processes since
each command/job invocation is a fresh PHP boot (see
`.claude/skills/kanvas-connector/SKILL.md` for the full rule).

---

## 2. Command reference

### Listing and getting help

```bash
# List every registered command
php artisan list

# Filter by domain namespace
php artisan list kanvas

# Full help/signature for one command
php artisan help kanvas:status
```

### Running a command

```bash
php artisan {command-name} {arguments} {--options}

# Examples that exist in this codebase today:
php artisan kanvas:version
php artisan kanvas:status --backlog=5000
php artisan kanvas:setup
php artisan queue:work --queue=workflow --tries=3 --timeout=3750
php artisan schedule:work
```

Inside Docker (this is how the team actually runs commands — see
`tests/CLAUDE.md`):

```bash
docker exec -it php${APP_CONTAINER_NAME} bash -c "cd /var/www/html && php artisan kanvas:status"
```

### Notable operational commands

| Command | Purpose |
|---|---|
| `kanvas:status` (`App\Console\Commands\KanvasStatusCommand`) | Health snapshot: pings every per-domain DB connection, Redis, and reports **pending + failed counts for every known queue**. This is the fastest built-in way to see whether queues are backing up. |
| `kanvas:version` | Prints the running `AppEnums::VERSION`. |
| `kanvas:setup` / `kanvas:app-setup` | Idempotent bootstrap commands invoked by the various `Setup/*SetupCommand` classes (`InventorySetupCommand`, `GuildSetupCommand`, `SocialSetupCommand`, `SoukSetupCommand`, ...). |
| `kanvas:fake-migration {class}` | Manually inserts a row into the `migrations` table (used when a migration was applied out-of-band). |
| `kanvas:customFields-redis-regeneration {app_id} {className}` | Rebuilds the Redis cache of custom fields for an app/model. |
| `queue:work`, `queue:listen`, `queue:retry`, `queue:failed`, `queue:flush` | Stock Laravel queue console commands — see [§3](#3-queue-implementation). |
| `schedule:work`, `schedule:run`, `schedule:list` | Stock Laravel scheduler commands. |
| **`kanvas:queue-test`** (new, this PR) | Dispatches a probe job onto any queue connection/queue and confirms a worker actually processed it. See [§4](#4-testing-the-queue-end-to-end). |

`kanvas:status` in particular doubles as living documentation of every queue
name in the system — see `KanvasStatusCommand::QUEUES` in
`app/Console/Commands/KanvasStatusCommand.php`. A CI test
(`tests/Unit/Console/KanvasStatusQueueCoverageTest.php`) already enforces that
every queue referenced by a `queue:work --queue=...` line in the compose files
is also listed there, so that table is guaranteed to stay accurate.

---

## 3. Queue implementation

### Driver / connections

`config/queue.php` defines the standard Laravel connections:

| Connection | Driver | Notes |
|---|---|---|
| `redis` | `redis` | **Primary connection** (`config('queue.default')` falls back to `redis` when `QUEUE_CONNECTION` is unset). Uses the dedicated `queue` Redis connection (`config/database.php`), `retry_after=4000`, `block_for=5`, `after_commit=true`. |
| `sync` | `sync` | Runs jobs inline, no broker. Used for local dev/tests — `.env.example` and `phpunit.xml` both set `QUEUE_CONNECTION=sync`. |
| `database` | `database` | Backed by the `jobs` table. Not the default, but available. |
| `beanstalkd` | `beanstalkd` | Configured, not actively used by the compose workers. |
| `sqs` | `sqs` | Configured for AWS SQS, env-driven. |
| `rabbitmq` | `rabbitmq` (via `vladimir-yuldashev/laravel-queue-rabbitmq` + `php-amqplib`) | Configured with SSL options; `RABBITMQ_HOST` is set in `.env.example`. |

Failed jobs are stored via `'failed' => ['driver' => 'database-uuids', 'table' => 'failed_jobs']`
(migration: `database/migrations/2023_01_06_200643_create_failed_jobs_table.php`).
Job batching uses `database/migrations/2026_08_27_110000_create_job_batches_table.php`.

### Job classes

Jobs live under `Jobs/` (or `Webhooks/`) folders inside each domain, e.g.:

```
src/Baka/Jobs/LightHouseCacheCleanUpJob.php
src/Kanvas/Jobs/BatchLoggerJob.php
src/Domains/Workflow/Jobs/ProcessWebhookJob.php
src/Domains/Connectors/OpenClaw/Jobs/LaunchAgentJob.php
... (212 job classes across the codebase)
```

The standard shape:

```php
class SomeJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;      // when the job carries Eloquent models
    use KanvasJobsTrait;        // overwriteAppService()/overwriteAppServiceLocation() — see below

    public function __construct(...) {
        $this->onQueue('some-queue-name');   // pin the job to a specific named queue
    }

    public function handle(): void { ... }
}
```

- **`Baka\Traits\KanvasJobsTrait`** (`src/Baka/Traits/KanvasJobsTrait.php`) is
  the multi-tenancy bridge for queued jobs: `overwriteAppService(AppInterface $app)`
  rebinds the scoped `Apps` singleton and the Bouncer permission scope inside
  the worker process, because a queue worker is a long-lived process with no
  per-request container reset — without this, a worker could leak the tenant
  context of the previous job it processed. `overwriteAppServiceLocation()`
  does the same for `CompaniesBranches`.
- **Dispatching**: `SomeJob::dispatch($args)` (via the `Dispatchable` trait).
  Most jobs pin their own queue name in the constructor with
  `$this->onQueue('queue-name')` rather than leaving the caller to choose —
  keeps the routing centralized and greppable (`grep -rn "onQueue("`).
- **Notifications**: `Kanvas\Notifications\Notification` (which every
  notification class must extend, never the base Illuminate one) implements
  `ShouldQueue` itself, so any `->notify(...)` call is queued automatically.

### Named queues in production

Every named queue below has one or more dedicated `queue:work --queue=X`
containers in `docker-compose.yml` / `docker-compose.1.x.yml` /
`docker-compose.development.yml`:

```
default, kanvas-social, notifications, user-interactions, batch-logger,
agent-task-worker, imports (x4), scout (x3), scrapper-queue (x5),
sync-shopify-queue (x3), workflow (x4), agent-runtime, product-enrichment,
product-discovery, agent-chat, broadcasts, ledger, slack-ingest,
nervous-system-project, scheduled-actions, scribe-aging, scribe-pdf-ingest,
lead_follow_ups
```

Example (`docker-compose.1.x.yml`):

```yaml
x-common-queue-settings: &common-queue-settings
  restart: always
  image: php-app-image
  command:
    - "sh"
    - "-c"
    - "php artisan config:cache && php artisan queue:work --tries=3 --timeout=3750"

services:
  queue:                       # drains the "default" queue
    <<: *common-queue-settings
    container_name: queue

  queue-social:                 # drains "kanvas-social"
    <<: *common-queue-settings
    command:
      - "sh"
      - "-c"
      - "php artisan config:cache && php artisan queue:work --queue kanvas-social --tries=3 --timeout=3750"

  agent-task-worker-queue:       # 3 replicas — parallelism ceiling for a plan's task band
    <<: *common-queue-settings
    deploy:
      replicas: 3
    command: [...--queue=agent-task-worker --tries=1 --timeout=3750]
```

A queue with `deploy.replicas: N` (or N separately-named `*-worker-N`
services, e.g. `queue-imports2..4`, `queue-scrapper-worker-1..4`) means N
`queue:work` processes are consuming that queue in parallel — one process
per replica, so a batch dispatched onto a single-consumer queue runs strictly
end-to-end (see the comment above `agent-task-worker-queue`).

The scheduler itself runs in its own `laravel-scheduler` service via
`php artisan schedule:work`, separate from every queue worker.

### Dispatch → consumption flow

```
1. Somewhere in app code:      SomeJob::dispatch($payload);
                                 (job's own constructor pins ->onQueue('name'))
2. Laravel Queue manager:      pushes the serialized job onto the configured
                                connection (redis by default) under that queue name
3. A `queue:work --queue=name` container (docker-compose service) is blocked
   on BRPOP against that Redis list, pops the job, and calls handle()
4. On success: job is deleted from the queue.
   On failure: retried up to --tries, then moved to `failed_jobs`.
```

---

## 4. Testing the queue end-to-end

### A. Passive/observability checks (no dispatch needed)

```bash
# Snapshot of every DB connection, Redis, and every named queue's
# pending + failed count
php artisan kanvas:status

# Raw pending count for one queue/connection
php artisan tinker
>>> app(\Illuminate\Contracts\Queue\Factory::class)->connection('redis')->size('workflow')

# Inspect/retry failed jobs
php artisan queue:failed
php artisan queue:retry all
php artisan queue:flush        # discard all failed jobs
```

### B. Active end-to-end smoke test (new: `kanvas:queue-test`)

This PR adds `App\Console\Commands\Support\QueueSmokeTestCommand` (registered
automatically — no wiring needed, see [§1](#command-registration)) plus its
probe job, `Kanvas\Jobs\QueueSmokeTestJob`
(`src/Kanvas/Jobs/QueueSmokeTestJob.php`). It answers the very concrete
question **"if I dispatch a job onto queue X right now, is anything actually
consuming it?"** without needing a real business job, without touching the
database, and without waiting for a scheduled command to run.

How it works:

1. Generates a unique token and dispatches `QueueSmokeTestJob` onto the
   requested `--connection`/`--queue` (defaults to the app's default
   connection + the `default` queue).
2. The job's `handle()` just writes a small marker (token, timestamps, queue
   name, worker hostname) to the cache under a token-scoped key.
3. The command polls that cache key for up to `--timeout` seconds.
4. Prints a table with the round-trip latency and the hostname of the worker
   that processed it on success, or a clear "no worker consumed this" error
   with the exact `queue:work` invocation needed to fix it.

Usage:

```bash
# Basic check against the default connection + "default" queue
php artisan kanvas:queue-test

# Target a specific named queue (e.g. confirm the workflow workers are alive)
php artisan kanvas:queue-test --queue=workflow --timeout=15

# Target a specific connection explicitly (e.g. confirm Redis vs sync)
php artisan kanvas:queue-test --connection=redis --queue=agent-runtime

# Just dispatch and inspect the cache key yourself later
php artisan kanvas:queue-test --dispatch-only
```

Inside Docker, exactly as documented in `tests/CLAUDE.md`:

```bash
docker exec -it php${APP_CONTAINER_NAME} bash -c "cd /var/www/html && php artisan kanvas:queue-test --queue=workflow --timeout=15"
```

Example successful output:

```
Dispatching probe job [3f7c...] onto connection="redis" queue="workflow"...

✓ Job processed after 42 ms by worker on host "queue-workflow-1".
+--------------------------------------+------------+----------+--------------------------+--------------------------+
| Token                                | Connection | Queue    | Dispatched at            | Processed at             |
+--------------------------------------+------------+----------+--------------------------+--------------------------+
| 3f7c1e2a-....                        | redis      | workflow | 2026-08-29T12:00:00+00:00| 2026-08-29T12:00:00+00:00|
+--------------------------------------+------------+----------+--------------------------+--------------------------+
```

Example failure (no worker listening on that queue):

```
✗ No worker processed the job within 10s.
Make sure a worker is listening on this queue, e.g.:
  php artisan queue:work --connection=redis --queue=workflow
```

This is intentionally environment-agnostic: run it against `sync` locally to
confirm the job class itself works, or against `redis`/any other connection
in staging/production to confirm a real worker container is up and draining
the queue you care about — the exact scenario this exploration task asked to
validate.

### C. Automated test coverage

`tests/Unit/Console/QueueSmokeTestCommandTest.php` exercises the command and
job directly (`TestCaseUnit`, no database needed):

```bash
docker exec -it php${APP_CONTAINER_NAME} bash -c \
  "cd /var/www/html && php vendor/bin/phpunit tests/Unit/Console/QueueSmokeTestCommandTest.php"
```

It covers:
- `--dispatch-only` pushes the job onto the requested queue without waiting (`Queue::fake()`).
- The `--queue` option is honored (`Queue::assertPushedOn(...)`).
- A synchronous run (`QUEUE_CONNECTION=sync`, the test-suite default per
  `phpunit.xml`) succeeds end-to-end, proving the job/marker/poll loop works.
- A faked (never-processed) dispatch times out and reports failure.
- The job's `handle()` writes the expected marker shape directly, independent
  of the command.

---

## 5. Appendix: file map

```
artisan                                    Front controller
bootstrap/app.php                          Application bootstrap + kernel bindings
app/Console/Kernel.php                     Command registration + cron schedule
routes/console.php                         Closure-based commands
app/Console/Commands/**                    ~230 domain-organized Artisan commands
app/Console/Commands/KanvasStatusCommand.php  Queue/DB/Redis health snapshot
app/Console/Commands/Support/QueueSmokeTestCommand.php  NEW — queue smoke test (this PR)
src/Kanvas/Jobs/QueueSmokeTestJob.php      NEW — probe job used by the smoke test (this PR)
tests/Unit/Console/QueueSmokeTestCommandTest.php  NEW — coverage for the above (this PR)
tests/Unit/Console/KanvasStatusQueueCoverageTest.php  Existing ratchet: every compose queue must appear in kanvas:status
config/queue.php                          Queue connections (redis/sync/database/beanstalkd/sqs/rabbitmq) + failed-job store
config/database.php                       Redis connection details (incl. the dedicated "queue" connection)
database/migrations/2023_01_06_200643_create_failed_jobs_table.php
database/migrations/2026_08_27_110000_create_job_batches_table.php
docker-compose.yml / docker-compose.1.x.yml / docker-compose.development.yml
                                           One `queue:work --queue=X` service per named queue + a `laravel-scheduler` (schedule:work) service
src/Baka/Traits/KanvasJobsTrait.php        Per-job tenant-context rebinding for long-lived workers
Baka\Jobs\*, Kanvas\Jobs\*, Kanvas\{Domain}\Jobs\*  212 job classes across the codebase
```
