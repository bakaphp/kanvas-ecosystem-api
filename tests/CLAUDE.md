# Tests — Kanvas Ecosystem API

Loads when work touches anything under `tests/`. For unrelated PHP work this stays unloaded.

## Running Tests — Inside the Docker container, NEVER locally

```bash
# Single test by name
docker exec -it phpkanvas-ecosystem bash -c "cd /var/www/html && php vendor/bin/phpunit --filter testCreateAction"

# Full test suite (parallel)
docker exec -it phpkanvas-ecosystem bash -c "cd /var/www/html && php vendor/bin/paratest --testsuite=ActionEngine"

# Single test file
docker exec -it phpkanvas-ecosystem bash -c "cd /var/www/html && php vendor/bin/phpunit tests/GraphQL/ActionEngine/ActionCrudTest.php"
```

**Always run the relevant test suite after completing work on a module or connector** to verify nothing is broken, unless explicitly told otherwise.

## Keeping the Verify Loop Fast (measured, don't re-litigate)

**Never run `php artisan lighthouse:validate-schema` as part of a routine verification run.** It costs
**79 seconds** (measured twice, warm) — more than the tests it's bolted onto — and **CI never runs it**:
`lighthouse:*` appears only in the deploy workflows, never in `.github/workflows/tests.yml`. A broken
schema already fails the tests. Run it only when the diff actually touches `.graphql`:

```bash
git diff --name-only | grep -q '\.graphql$' && docker exec phpkanvas-ecosystem bash -c "cd /var/www/html && php artisan lighthouse:validate-schema"
```

**Keep `bootstrap/cache/config.php` in place.** `TestCase::createApplication()` re-bootstraps the kernel
for *every test*, so with config uncached each test re-reads ~100 config files over the macOS bind mount.
Same 146 tests: **1m34s uncached vs 35.7s cached** — 2.6x. If a run suddenly feels twice as slow, run
`php artisan config:cache` before suspecting your change. Don't leave a `config:clear` behind.

**Scope the run like CI does — one suite.** CI is a 25-runner matrix, one `--testsuite` each at
`--processes=4`; no runner ever runs 830 tests. Running several suites at once locally serializes the
whole matrix onto one box and is what gets the process OOM-killed (exit 137). Prefer
`--testsuite=<Name>` over directory paths so the slice matches CI exactly.

**Type-check the paths you touched — `php -l` does not do this.** CI runs PHPStan at `level: 0` on
every push (`.github/workflows/static-analysis.yml`), and level 0 already covers cross-file call
signatures: unknown methods, wrong argument counts, and **unknown named arguments**. `php -l` only
parses one file at a time, so a call site that no longer matches a constructor it does not live in is
invisible to it — and invisible to PHPUnit too whenever the broken branch is one the suite never
executes (see the `??` test-seam trap below).

```bash
# ~6s scoped to a few paths; run this before declaring done, not the full config
docker exec phpkanvas-ecosystem bash -c "cd /var/www/html && vendor/bin/phpstan analyse \
  --configuration=phpstan.neon.dist --no-progress src/Domains/YourTree app/GraphQL/YourTree"
```

Scope it to the trees in your diff. **Never run the full `app` + `src` config locally.** It runs >10
minutes and then OOMs — and it does not just kill its own worker, it takes **`mysqlkanvas-ecosystem`
down with it** (`Exited (137)`), exactly like the full paratest run above.

The symptom is terrifying and misleading: the next test run reports *hundreds* of errors
(`AppInput::__construct(): Argument #1 ($name) must be of type string, null given` out of
`tests/TestCase.php`), because `app(Apps::class)` resolves against a database that is no longer
there. That is not your code. Check the container before you debug anything:

```bash
docker ps -a --format '{{.Names}}\t{{.Status}}' | grep -iE "mysql|redis"
docker start mysqlkanvas-ecosystem   # then wait for health: healthy
```

Leave the full analysis to CI and analyse paths locally.

**Beware the `??` test seam.** The idiom `$this->dep ?? new RealThing(...)` — used for transports,
SSH clients and HTTP clients across the connectors — means every test that injects a fake makes the
`new RealThing(...)` branch **unreachable in the suite**. A green suite says nothing about whether
that constructor call is still valid. Real incident: removing a dead constructor parameter from
`GuardedHttpMcpTransport` left `McpConnectionService::connector()` passing `transport:` to a
constructor that no longer had it; 191 MCP tests passed, PHPStan flagged it in 6s, and it surfaced as
`Unknown named parameter $transport` the first time an admin pressed Refresh. When you add such a
seam, add one test that takes the real branch — see
`McpServerUrlPerConnectionTest::testTheConnectorBuildsTheRealGuardedTransportWhenNoneIsInjected`.

**Already measured, don't retry:** `opcache.enable_cli` + `file_cache` = 3%. Lighthouse **schema cache =
a wash** — 14% on a GraphQL-only file, and *slower* on mixed sets (every process loads a 26MB
`lighthouse-schema.php`); leave `LIGHTHOUSE_SCHEMA_CACHE_ENABLE=false`. `paratest --processes=4` = 20%
only, because each worker repeats the full domain setup in `createApplication()`.

## Available Suites

Unit, Ecosystem, GraphQL, Inventory, Social, Guild, Connectors, Workflow, Intelligence, Baka, Souk, Event, ActionEngine

## Hard Rules

- **NEVER use `RefreshDatabase`** — it wipes all shared DB tables across connections. Use `DatabaseTransactions`.
- **`DatabaseTransactions` only rolls back the *default* connection.** Laravel's `connectionsToTransact()` defaults to `[null]`, so anything written on `inventory` / `crm` / `commerce` / `social` / `action_engine` **commits and survives the test**. The symptom is a second test in the same file finding rows the first one "created" — an idempotent action then correctly skips them and returns nothing, and the assertion fails on empty data rather than on the bug you were testing. Declare every connection the code under test writes to:
  ```php
  class SeedsProductsTest extends TestCase
  {
      use DatabaseTransactions;

      protected $connectionsToTransact = [null, 'inventory'];
  }
  ```
  Check which connection the Action actually writes on (`DB::connection('inventory')->transaction(...)` inside `CreateProductAction`, for example) — not the domain you *think* you're testing. Real case: `tests/Insurance/SyncInsuranceProductsActionTest.php`.
- **The one exception: don't list `inventory` on a test that creates products through `CreateProductAction`.** That action wraps its work in `DB::connection('inventory')->transaction($cb, 3)` so it can retry the deadlock concurrent product inserts hit — `Products::where(slug, apps_id, companies_id)->lockForUpdate()` gap-locks the non-unique `(apps_id, companies_id, slug)` index, and two paratest workers inserting different slugs under the same tenant deadlock on the insert-intention lock. Laravel only retries a transaction it opened itself (`handleTransactionException` rethrows when `transactions > 1`), so listing `inventory` in `connectionsToTransact()` demotes that one to a savepoint, kills the retry, and the deadlock escapes as a 500 — which the caller then sees as `Undefined array key "data"`. Accept the leaked product rows, or run the suite single-process. Real case: the `Event` suite, pinned to `processes: 1` in `.github/workflows/tests.yml` for exactly this.
- **`CreateChannelAction` had the same trap and it is fixed at the source, not worked around.** It no longer `lockForUpdate()`s `channels` — a locking read of a row that does not exist yet only takes a gap lock, which excludes nothing (gap locks are mutually compatible) while deadlocking the insert that follows. Creation is serialized on a `Cache::lock` instead, so any connection may be listed in `connectionsToTransact`. The symptom it caused: `PlanObserver` swallows a failed channel create, so a plan came out with no Activities channel and `AgentWakeReplySuppressionTest` failed on `A plan with no Activities channel cannot be posted on at all` — only under `--processes=3`. Don't reintroduce a row lock to dedupe a row that has no unique index; `channels` still has none.
- **A test that mutates state shared by every paratest process must be tagged `#[Group('serial')]`.** CI runs `paratest ... --exclude-group=serial` and then a second single-process `phpunit --group=serial` pass, so a serial test never overlaps anything. Two kinds of state qualify, and `DatabaseTransactions` protects you from neither:
  - **A global `apps_id = 0` catalog row** (`agent_types`, `actions`, `nervous_system_tools`) — CI seeds these with the sync commands *before* the run, so every process reads the same committed row. Soft-deleting one takes an X lock on it for the rest of your transaction while parallel processes take the FK parent lock on that same row from their own inserts, through a different index. That is a genuine 1213 deadlock, and it surfaces as an unrelated `DeadlockException` in whichever transaction MySQL picks. Real case: `EnsureCompanyOrchestratorAgentActionTest::testFailsClearlyWhenTypeNotSynced` deleting the `Project Orchestrator` agent-type vs. `insert into agents` in two sibling classes.
  - **An app setting** — `HashTableTrait::set()` writes to **Redis first** and then upserts on the **`ecosystem`** connection. Redis is shared by every process and rolls back never; `ecosystem` is in almost no test's `connectionsToTransact`. So `$app->set('onboarding_orchestrator_setup', 1)` turns the flag on for the whole run, for everyone. Real case: `OnboardingOrchestratorProvisionTest`, whose own flag-off assertion caught it — but the wider damage was every parallel test that creates a user silently running onboarding provisioning.
- **A test that exercises a table-wide sweep must sweep a time window nothing else occupies.** `ArchiveOldEventsAction` deletes from `nervous_system_events` with no app, company or event-type filter — only `occurred_at < cutoff`. `LedgerArchiveTest` used to seed rows minutes old and pass `retentionDaysOverride: 0`, i.e. cutoff = `now`, so under paratest it deleted ledger rows a sibling process had just written and was about to assert on. That lands as an unreproducible "sometimes it fails" in the victim, never in the destructive test: `PlanAgentWakeUpTest::testListenerEmitsWakeDispatchedLedgerEvent`, `BuildLeadFollowUpDailySummaryActionTest` (seeds yesterday) and `EventAnalyticsServiceTest` (seeds a few months back) were all in range. The fix is to move the whole exercise into the far past — seed at `now()->subYears(5)`, cut at 3 years — which tests the same age-based branch while touching a window no other test writes to. `#[Group('serial')]` also works; the window is cheaper and keeps the file in the parallel pass. Tests that only *seed* ledger rows are already well-behaved: they clean up filtered by a marker `source_domain` or an isolated far-future date range, never by a bare age.
- **Never index straight into `->json()['data'][$mutation]`.** A failed mutation answers with an `errors`-only body and an unhandled exception answers with no GraphQL envelope at all, so that turns any real failure into an opaque `Undefined array key "data"` with no trace of the cause. Use `InventoryCases::graphQLData($response, $mutation)`, which asserts and prints the HTTP status plus the body.
- Base `TestCase` loads `.env` (not `.env.testing`), no `RefreshDatabase` by default.
- Base `TestCase` provides `$this->graphQL()` via Lighthouse's `MakesGraphQLRequests` trait.
- User is auto-authenticated in `createApplication()` with admin role.

## Common GraphQL Patterns

```php
// GraphQL mutation test
$this->graphQL('
    mutation($input: ActionInput!) {
        createAction(input: $input) { id name }
    }
', ['input' => ['name' => 'Test']])
->assertSuccessful()
->assertJson(['data' => ['createAction' => ['name' => 'Test']]]);

// GraphQL query with search
$this->graphQL('
    query($search: String) {
        companyActions(search: $search) { data { id name } }
    }
', ['search' => 'keyword'])
->assertSuccessful();
```

## AppKey-Guarded Test Pattern

Endpoints using `@guardByAppKey` require the AppKey header in tests:

```php
private function getAppKeyHeader(): array
{
    $app = app(Apps::class);

    return [AppEnums::KANVAS_APP_KEY_HEADER->getValue() => $app->keys()->first()->client_secret_id];
}

// Pass as 4th argument
$this->graphQL($query, $variables, [], $this->getAppKeyHeader());
```

References: `tests/GraphQL/Souk/DiscountTest.php`, `tests/GraphQL/Workflow/RulesTest.php`.

## Setting Up Bouncer Permissions in Tests

When mutations use `@can` directives, the test must set up Bouncer scope, assign the role to the user, and grant abilities:

```php
use Kanvas\AccessControlList\Enums\RolesEnums;
use Silber\Bouncer\BouncerFacade as Bouncer;

class {Entity}CrudTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        // 1. Set Bouncer scope (use global: true for companyId 0)
        $scope = RolesEnums::getScope($this->apps, global: true);
        Bouncer::scope()->to($scope);

        // 2. Assign role to the test user — required, or @can returns "unauthorized"
        Bouncer::assign('Admins')->to($this->user);

        // 3. Grant abilities to the role for each model — abilities must match schema
        Bouncer::allow('Admins')->to(['create', 'edit', 'delete'], {Entity}::class);
    }
}
```

`RolesEnums::getScope($app, global: true)` returns scope `app_{id}_company_0` (global scope).

## Common Test Fix Patterns

- **FK constraint errors in factories**: Check if factory hardcodes IDs (e.g., `agent_type_id => 1`) — use `RelatedModel::factory()` instead.
- **Time-dependent tests**: Use `Carbon::setTestNow()` to freeze time.
- **Silent failures via Sentry**: Actions that catch exceptions with `captureException()` — add a temporary `echo` in catch block to debug.
- **AI/laravel-ai calls**: Use `StructuredAnonymousAgent::fake([...])` (or `AnonymousAgent::fake([...])` for text) with enough responses for all sessions — array items become structured responses, strings become text responses.
- **Duplicate key violations**: Check if action classes already create related records internally (e.g., `GenerateReferralCodeAction::execute()` creates the discount — use `$referralCode->discount` rather than creating another).
- **Mock objects**: Set `$mock->exists = true` when the code checks `$this->model->exists`.
