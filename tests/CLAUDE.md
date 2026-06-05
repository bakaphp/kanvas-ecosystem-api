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

## Available Suites

Unit, Ecosystem, GraphQL, Inventory, Social, Guild, Connectors, Workflow, Intelligence, Baka, Souk, Event, ActionEngine

## Hard Rules

- **NEVER use `RefreshDatabase`** — it wipes all shared DB tables across connections. Use `DatabaseTransactions`.
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
