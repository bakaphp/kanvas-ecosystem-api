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
