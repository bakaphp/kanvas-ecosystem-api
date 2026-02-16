# Kanvas Ecosystem API - Development Guide

Guidelines for working with the Kanvas Ecosystem API codebase.

## Architecture Overview

- **Multi-database**: Each domain has its own database connection (`action_engine`, `crm`, `inventory`, `social`, `ecosystem`)
- **Domain-driven design**: Code is organized by domain under `src/Domains/{DomainName}/`
- **GraphQL API**: Uses Lighthouse PHP framework with schema files in `graphql/schemas/`
- **PHP 8.4**: Use modern syntax (e.g., `new Foo(...)->execute()` not `(new Foo(...))->execute()`)

## Domain CRUD Pattern

### Project Structure

```
src/Domains/{DomainName}/{Entity}/
├── Actions/
│   ├── Create{Entity}Action.php    # Business logic for creation
│   └── Update{Entity}Action.php    # Business logic for updates
├── DataTransferObject/
│   └── {Entity}.php                # Spatie LaravelData DTO (named after entity, NOT {Entity}Input)
├── Models/
│   └── {Entity}.php                # Eloquent model
└── Enums/                          # Optional enums

app/GraphQL/{DomainName}/Mutations/{Entity}/
└── {Entity}Mutation.php            # GraphQL mutation resolver

graphql/schemas/{DomainName}/
└── {entity}.graphql                # GraphQL type, input, mutation, query definitions

tests/GraphQL/{DomainName}/
└── {Entity}CrudTest.php            # GraphQL CRUD tests
```

### 1. Data Transfer Object (DTO)

Location: `src/Domains/{Domain}/{Entity}/DataTransferObject/{Entity}.php`

Name the DTO class after the entity (e.g., `Action`, `Pipeline`). When importing in files that also use the model, alias it: `use Kanvas\{Domain}\{Entity}\DataTransferObject\{Entity} as {Entity}Data;`

```php
<?php

declare(strict_types=1);

namespace Kanvas\{Domain}\{Entity}\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Spatie\LaravelData\Data;

class {Entity} extends Data
{
    public function __construct(
        public readonly AppInterface $app,
        public readonly CompanyInterface $company,
        public readonly UserInterface $user,
        public readonly string $name,
        public readonly ?string $description = null,
        // ... other fields
        // Use model objects for FKs: public readonly RelatedModel $related,
    ) {
    }
}
```

### 2. Create Action

Location: `src/Domains/{Domain}/{Entity}/Actions/Create{Entity}Action.php`

```php
<?php

declare(strict_types=1);

namespace Kanvas\{Domain}\{Entity}\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\{Domain}\{Entity}\DataTransferObject\{Entity} as {Entity}Data;
use Kanvas\{Domain}\{Entity}\Models\{Entity};

class Create{Entity}Action
{
    public function __construct(
        protected readonly {Entity}Data $data,
    ) {
    }

    public function execute(): {Entity}
    {
        return DB::connection('{db_connection}')->transaction(function () {
            $entity = new {Entity}();
            $entity->apps_id = $this->data->app->getId();
            $entity->companies_id = $this->data->company->getId(); // 0 for global entities
            $entity->users_id = $this->data->user->getId();
            $entity->name = $this->data->name;
            // ... set other fields
            // For FK relationships: $entity->related_id = $this->data->related->getId();
            $entity->saveOrFail();

            return $entity;
        });
    }
}
```

### 3. Update Action

Location: `src/Domains/{Domain}/{Entity}/Actions/Update{Entity}Action.php`

```php
<?php

declare(strict_types=1);

namespace Kanvas\{Domain}\{Entity}\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\{Domain}\{Entity}\DataTransferObject\{Entity} as {Entity}Data;
use Kanvas\{Domain}\{Entity}\Models\{Entity};

class Update{Entity}Action
{
    public function __construct(
        protected readonly {Entity} $entity,
        protected readonly {Entity}Data $data,
    ) {
    }

    public function execute(): {Entity}
    {
        return DB::connection('{db_connection}')->transaction(function () {
            $this->entity->name = $this->data->name;
            // ... update other fields
            $this->entity->saveOrFail();

            return $this->entity;
        });
    }
}
```

### 4. GraphQL Mutation Resolver

Location: `app/GraphQL/{Domain}/Mutations/{Entity}/{Entity}Mutation.php`

```php
<?php

declare(strict_types=1);

namespace App\GraphQL\{Domain}\Mutations\{Entity};

use Kanvas\{Domain}\{Entity}\Actions\Create{Entity}Action;
use Kanvas\{Domain}\{Entity}\Actions\Update{Entity}Action;
use Kanvas\{Domain}\{Entity}\DataTransferObject\{Entity} as {Entity}Data;
use Kanvas\{Domain}\{Entity}\Models\{Entity};
use Kanvas\Apps\Models\Apps;

class {Entity}Mutation
{
    public function create(mixed $rootValue, array $request): {Entity}
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();
        $input = $request['input'];

        // Look up related models from IDs before constructing DTO
        // $related = RelatedModel::getByIdFromCompanyApp((int) $input['related_id'], $company, $app);

        return new Create{Entity}Action(
            new {Entity}Data(
                app: $app,
                company: $company,
                user: $user,
                name: $input['name'],
                // related: $related,
            ),
        )->execute();
    }

    public function update(mixed $rootValue, array $request): {Entity}
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();
        $input = $request['input'];

        // For global entities (no company scoping):
        $entity = {Entity}::getById((int) $request['id'], $app);
        // For company-scoped entities:
        // $entity = {Entity}::getByIdFromCompanyApp((int) $request['id'], $company, $app);

        return new Update{Entity}Action(
            $entity,
            new {Entity}Data(
                app: $app,
                company: $company,
                user: $user,
                name: $input['name'] ?? $entity->name,
            ),
        )->execute();
    }

    public function delete(mixed $rootValue, array $request): bool
    {
        $app = app(Apps::class);
        $entity = {Entity}::getById((int) $request['id'], $app);

        return $entity->softDelete();
    }
}
```

### 5. GraphQL Schema

Location: `graphql/schemas/{Domain}/{entity}.graphql`

```graphql
input {Entity}Input {
    name: String!
    description: String
    # ... other fields
}

input Update{Entity}Input {
    name: String
    description: String
    # ... other fields (all optional for partial updates)
}

# Admin-only CUD operations
extend type Mutation @guardByAdmin {
    create{Entity}(input: {Entity}Input!): {Entity}!
        @field(resolver: "App\\GraphQL\\{Domain}\\Mutations\\{Entity}\\{Entity}Mutation@create")
    update{Entity}(id: ID!, input: Update{Entity}Input!): {Entity}!
        @field(resolver: "App\\GraphQL\\{Domain}\\Mutations\\{Entity}\\{Entity}Mutation@update")
    delete{Entity}(id: ID!): Boolean!
        @field(resolver: "App\\GraphQL\\{Domain}\\Mutations\\{Entity}\\{Entity}Mutation@delete")
}

# Read access for all authenticated users
extend type Query @guard {
    {entityPlural}(
        search: String @search
        where: _ @whereConditions(columns: ["id", "name", "slug"])
        orderBy: _ @orderBy(columns: ["id", "created_at", "updated_at", "name"])
    ): [{Entity}!]!
        @paginate(
            model: "Kanvas\\{Domain}\\{Entity}\\Models\\{Entity}"
            scopes: ["fromApp", "notDeleted"]
            defaultCount: 25
        )
}
```

### 6. Tests

Location: `tests/GraphQL/{Domain}/{Entity}CrudTest.php`

```php
<?php

declare(strict_types=1);

namespace Tests\GraphQL\{Domain};

use Tests\TestCase;

class {Entity}CrudTest extends TestCase
{
    public function testCreate{Entity}(): void
    {
        $input = ['name' => 'Test ' . fake()->word()];

        $this->graphQL('
            mutation($input: {Entity}Input!) {
                create{Entity}(input: $input) {
                    id
                    name
                }
            }
        ', ['input' => $input])
        ->assertSuccessful()
        ->assertJson(['data' => ['create{Entity}' => ['name' => $input['name']]]]);
    }

    public function testUpdate{Entity}(): void
    {
        $input = ['name' => 'Test ' . fake()->word()];
        $createResponse = $this->graphQL('
            mutation($input: {Entity}Input!) {
                create{Entity}(input: $input) { id name }
            }
        ', ['input' => $input])->assertSuccessful();
        $id = $createResponse->json('data.create{Entity}.id');

        $updateInput = ['name' => 'Updated ' . fake()->word()];
        $this->graphQL('
            mutation($id: ID!, $input: Update{Entity}Input!) {
                update{Entity}(id: $id, input: $input) { id name }
            }
        ', ['id' => $id, 'input' => $updateInput])
        ->assertSuccessful();
    }

    public function testDelete{Entity}(): void
    {
        $input = ['name' => 'Test ' . fake()->word()];
        $createResponse = $this->graphQL('
            mutation($input: {Entity}Input!) {
                create{Entity}(input: $input) { id }
            }
        ', ['input' => $input])->assertSuccessful();
        $id = $createResponse->json('data.create{Entity}.id');

        $this->graphQL('
            mutation($id: ID!) { delete{Entity}(id: $id) }
        ', ['id' => $id])
        ->assertSuccessful()
        ->assertJson(['data' => ['delete{Entity}' => true]]);
    }

    public function testList{Entities}(): void
    {
        $this->graphQL('query { {entityPlural} { data { id name } } }')
            ->assertSuccessful()
            ->assertJsonStructure(['data' => ['{entityPlural}' => ['data' => [['id', 'name']]]]]);
    }
}
```

## Connector Pattern

Connectors integrate Kanvas with external services (Shopify, Stripe, WaSender, etc.). All connectors live under `src/Domains/Connectors/`.

### Project Structure

```
src/Domains/Connectors/{ConnectorName}/
├── Handlers/
│   └── {ConnectorName}Handler.php     # Extends BaseIntegration, implements setup()
├── Client.php                          # Guzzle HTTP client for external API
├── DataTransferObject/
│   └── {ConnectorName}.php             # DTO for configuration/credentials
├── Enums/
│   ├── ConfigurationEnum.php           # App/company config keys
│   └── CustomFieldEnum.php             # Entity custom field names
├── Actions/                            # Business logic (sync, import, etc.)
├── Services/                           # Reusable domain services
├── Webhooks/ or Jobs/                  # ProcessWebhookJob implementations
└── Workflows/                          # Temporal workflows (optional)
    └── Activities/                     # Workflow activities

app/GraphQL/Connector/{ConnectorName}/
└── Mutations/
    └── {ConnectorName}Mutation.php     # GraphQL setup mutation

graphql/schemas/Connector/
└── {connector}.graphql                 # GraphQL input/mutation definitions

tests/Connectors/
├── {ConnectorName}/                    # Integration tests
└── Traits/
    └── Has{ConnectorName}Configuration.php  # Test setup trait
```

### 1. Handler (Required)

Extends `BaseIntegration` — validates credentials and stores configuration.

Location: `src/Domains/Connectors/{ConnectorName}/Handlers/{ConnectorName}Handler.php`

```php
<?php

declare(strict_types=1);

namespace Kanvas\Connectors\{ConnectorName}\Handlers;

use Kanvas\Connectors\Contracts\BaseIntegration;
use Kanvas\Exceptions\ValidationException;
use Override;

class {ConnectorName}Handler extends BaseIntegration
{
    #[Override]
    public function setup(): bool
    {
        $apiKey = $this->data['api_key'] ?? null;

        if (empty($apiKey)) {
            throw new ValidationException('API key is required');
        }

        // Store credentials in app or company custom fields
        $this->app->set(ConfigurationEnum::API_KEY->value, $apiKey);

        // Validate by making a test API call
        return Client::validateCredentials($apiKey);
    }
}
```

The `BaseIntegration` base class (`src/Domains/Connectors/Contracts/BaseIntegration.php`) provides:
- `$this->app` — current App
- `$this->company` — current Company
- `$this->region` — KanvasRegions instance
- `$this->data` — array of setup data from the request

### 2. Configuration Enums

```php
// ConfigurationEnum.php — keys for app/company settings
enum ConfigurationEnum: string
{
    case BASE_URL = '{connector}_base_url';
    case API_KEY = '{connector}_api_key';
    case API_SECRET = '{connector}_api_secret';
}

// CustomFieldEnum.php — keys for entity-level custom fields
enum CustomFieldEnum: string
{
    case EXTERNAL_PRODUCT_ID = '{CONNECTOR}_PRODUCT_ID';
    case EXTERNAL_CUSTOMER_ID = '{CONNECTOR}_CUSTOMER_ID';
}
```

**Storage pattern:**
- App-level: `$app->set(ConfigurationEnum::KEY->value, $value)`
- Company-level: `$company->set(ConfigurationEnum::KEY->value, $value)`
- Entity-level: `$model->set(CustomFieldEnum::KEY->value, $value)`

### 3. Client (for External APIs)

```php
<?php

declare(strict_types=1);

namespace Kanvas\Connectors\{ConnectorName};

use Baka\Contracts\AppInterface;
use GuzzleHttp\Client as GuzzleClient;
use Kanvas\Exceptions\ValidationException;

class Client
{
    protected GuzzleClient $client;

    public function __construct(protected AppInterface $app)
    {
        $baseUrl = $this->app->get(ConfigurationEnum::BASE_URL->value);
        $apiKey = $this->app->get(ConfigurationEnum::API_KEY->value);

        if (empty($baseUrl) || empty($apiKey)) {
            throw new ValidationException('{ConnectorName} configuration is missing');
        }

        $this->client = new GuzzleClient([
            'base_uri' => $baseUrl,
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $apiKey,
            ],
        ]);
    }

    public function get(string $endpoint): array
    {
        $response = $this->client->get($endpoint);
        return json_decode($response->getBody()->getContents(), true);
    }

    public function post(string $endpoint, array $data): array
    {
        $response = $this->client->post($endpoint, ['json' => $data]);
        return json_decode($response->getBody()->getContents(), true);
    }
}
```

### 4. DTO

```php
<?php

declare(strict_types=1);

namespace Kanvas\Connectors\{ConnectorName}\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Inventory\Regions\Models\Regions;

class {ConnectorName}
{
    public function __construct(
        public CompanyInterface $company,
        public AppInterface $app,
        public Regions $region,
        public string $apiKey,
        public string $apiSecret,
    ) {
    }

    public static function viaRequest(array $data, AppInterface $app, CompanyInterface $company): self
    {
        return new self(
            company: $company,
            app: $app,
            region: Regions::getById($data['region_id']),
            apiKey: $data['api_key'],
            apiSecret: $data['api_secret'],
        );
    }
}
```

### 5. Webhook Job (if receiving webhooks)

Extend `ProcessWebhookJob` (`src/Domains/Workflow/Jobs/ProcessWebhookJob.php`). The base class handles auth setup, app context, and status tracking.

```php
<?php

declare(strict_types=1);

namespace Kanvas\Connectors\{ConnectorName}\Jobs;

use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Override;

class Process{ConnectorName}WebhookJob extends ProcessWebhookJob
{
    #[Override]
    public function execute(): array
    {
        $payload = $this->webhookRequest->payload;
        $regionId = $this->receiver->configuration['region_id'];

        // Process the webhook payload
        $result = new Sync{Entity}Action(
            $this->receiver->app,
            $this->receiver->company,
            Regions::getById($regionId),
            $payload,
        )->execute();

        return ['message' => 'Processed successfully', 'id' => $result->getId()];
    }
}
```

The `$this->receiver` (ReceiverWebhook model) provides:
- `$this->receiver->app` — the App
- `$this->receiver->company` — the Company
- `$this->receiver->user` — the User
- `$this->receiver->configuration` — array of webhook config (region_id, etc.)

### 6. Workflow Activities (for long-running async operations)

Extend `KanvasActivity` which provides `executeIntegration()` for status tracking and history logging.

```php
<?php

declare(strict_types=1);

namespace Kanvas\Connectors\{ConnectorName}\Workflows\Activities;

use Kanvas\Workflow\KanvasActivity;

class Sync{Entity}Activity extends KanvasActivity
{
    public function execute($entity, Apps $app, array $params): array
    {
        return $this->executeIntegration(
            entity: $entity,
            app: $app,
            integration: IntegrationsEnum::{CONNECTOR},
            integrationOperation: function () use ($entity) {
                return new Sync{Entity}Action($entity)->execute();
            },
            company: $entity->company,
        );
    }
}
```

### 7. GraphQL Setup Mutation

```graphql
# graphql/schemas/Connector/{connector}.graphql
input {ConnectorName}SetupInput {
    api_key: String!
    api_secret: String!
    region_id: ID!
}

extend type Mutation @guard {
    {connectorName}Setup(input: {ConnectorName}SetupInput!): Boolean
        @field(
            resolver: "App\\GraphQL\\Connector\\{ConnectorName}\\Mutations\\{ConnectorName}Mutation@setup"
        )
}
```

```php
// app/GraphQL/Connector/{ConnectorName}/Mutations/{ConnectorName}Mutation.php
class {ConnectorName}Mutation
{
    public function setup(mixed $root, array $request): bool
    {
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $app = app(Apps::class);

        $dto = {ConnectorName}Dto::viaRequest($request['input'], $app, $company);
        {ConnectorName}Service::setup($dto);

        return true;
    }
}
```

### 8. Register the Integration

Add to `IntegrationsEnum` (`src/Domains/Workflow/Enums/IntegrationsEnum.php`):

```php
enum IntegrationsEnum: string
{
    // ... existing connectors
    case {CONNECTOR} = '{connector_name}';
}
```

Seed a record in the `integrations` table mapping the name to the handler class.

### Connector Checklist

- [ ] **Enums**: `ConfigurationEnum` + `CustomFieldEnum`
- [ ] **Handler**: Extends `BaseIntegration` with `setup()` method
- [ ] **Client**: Guzzle HTTP client (if external API)
- [ ] **DTO**: Configuration/credentials data object
- [ ] **Service**: Core service class (optional)
- [ ] **Actions**: Business logic (sync, import, etc.)
- [ ] **Webhook Job**: Extends `ProcessWebhookJob` (if receiving webhooks)
- [ ] **Workflow/Activities**: Temporal workflows (if async operations)
- [ ] **GraphQL**: Schema + mutation for setup
- [ ] **Register**: Add to `IntegrationsEnum`, seed `integrations` table
- [ ] **Tests**: Configuration trait + integration tests in `tests/Connectors/`

### Multi-Tenancy Notes

- **App-level** configs (shared endpoints, base URLs): `$app->set()`
- **Company-level** configs (API keys, tokens): `$company->set()`
- **Region-scoped** credentials use composite keys: `CREDENTIAL-{appId}-{companyId}-{regionId}`
- Integration status tracked per company via `IntegrationsCompany` model (ACTIVE, INACTIVE, FAILED, OFFLINE)
- Every integration operation logged in `EntityIntegrationHistory` for auditing

## Adding @search to GraphQL Queries

All list queries should support the `@search` directive for text search. This requires two things:

### 1. Add the Trait to the Model

For simple database-only search (most models):
```php
use Baka\Traits\DatabaseSearchableTrait;
use Kanvas\Apps\Models\Apps;

class MyModel extends BaseModel
{
    use DatabaseSearchableTrait;

    public function searchableAs(): string
    {
        $app = $this->app ?? app(Apps::class);
        $customIndex = $app->get('app_custom_{model}_index') ?? null;

        return config('scout.prefix') . ($customIndex ?? '{model}_index');
    }

    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            // ... other searchable fields
        ];
    }

    public function shouldBeSearchable(): bool
    {
        return ! $this->isDeleted();
    }
}
```

For models that need Algolia/Typesense indexing (Products, Leads, Messages, etc.):
```php
use Baka\Traits\DynamicSearchableTrait;

class MyModel extends BaseModel
{
    use DynamicSearchableTrait {
        search as public traitSearch;
    }

    public function searchableAs(): string
    {
        $model = ! $this->searchableDeleteRecord() ? $this : $this->withTrashed()->find($this->id);
        $app = $model->app ?? app(Apps::class);
        $customIndex = $app->get('app_custom_{model}_index') ?? null;
        return config('scout.prefix') . ($customIndex ?? '{model}_index');
    }

    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            // ... other searchable fields
        ];
    }

    public function shouldBeSearchable(): bool
    {
        return ! $this->isDeleted();
    }
}
```

### 2. Add `search` Parameter to the GraphQL Query

```graphql
extend type Query @guard {
    myEntities(
        search: String @search
        where: _ @whereConditions(columns: ["id", "name"])
        orderBy: _ @orderBy(columns: ["id", "created_at", "name"])
    ): [MyEntity!]!
        @paginate(
            model: "Kanvas\\Domain\\Models\\MyModel"
            scopes: ["fromApp", "notDeleted"]
            defaultCount: 25
        )
}
```

### Which Trait to Use

| Trait | Use When | Examples |
|-------|----------|---------|
| `DatabaseSearchableTrait` | Simple models, no external search engine needed | Categories, Channels, Warehouses, Status, Pipeline, Action |
| `DynamicSearchableTrait` | Need Algolia/Typesense indexing, full-text search | Products, Leads, Messages, Agents |

### 3. Add Search Scoping to Prevent Data Leaks

**Every model that uses `@search` MUST override the `search()` method** to scope results by `apps_id` and `companies_id`. Without this, search queries can leak data across apps and companies.

Both `DatabaseSearchableTrait` and `DynamicSearchableTrait` alias `search as traitSearch`, so the pattern is the same.

#### Multi-Tenant Search Patterns

**Standard pattern** (most models — Templates, simple entities):
```php
use Baka\Users\Contracts\UserInterface;
use Kanvas\Apps\Models\Apps;

public static function search($query = '', $callback = null)
{
    $query = self::traitSearch($query, $callback)->where('apps_id', app(Apps::class)->getId());
    $user = auth()->user();
    if ($user instanceof UserInterface && ! $user->isAppOwner()) {
        $query->where('companies_id', $user->getCurrentCompany()->getId());
    }

    return $query;
}
```

**Branch-aware pattern** (Lead model — uses `CompaniesBranches` binding when available):
```php
use Kanvas\Companies\Models\CompaniesBranches;

public static function search($query = '', $callback = null)
{
    $query = self::traitSearch($query, $callback)->where('apps_id', app(Apps::class)->getId());
    $user = auth()->user();

    // When CompaniesBranches is bound (request scoped to a branch), use that company
    if ($user instanceof UserInterface && app()->bound(CompaniesBranches::class)) {
        $query->where('companies_id', app(CompaniesBranches::class)->company->getId());
    } elseif ($user instanceof UserInterface && ! $user->isAppOwner()) {
        $query->where('companies_id', $user->getCurrentCompany()->getId());
    }

    return $query;
}
```

**Product pattern** (supports opt-in company-bound search via app config + Algolia callback):
```php
public static function search($query = '', $callback = null)
{
    $app = app(Apps::class);
    $searchQuery = self::traitSearch($query, $callback)->where('apps_id', $app->getId());
    $user = auth()->user();

    if (
        $user instanceof UserInterface &&
        (
            ! $user->isAppOwner() ||
            (app()->bound(CompaniesBranches::class) && $app->get('enable_company_bound_search', false))
        )
    ) {
        $searchQuery->where('company.id', $user->getCurrentCompany()->getId());
    }

    return $searchQuery;
}
```

**Users pattern** (uses `whereIn` for array-based Algolia/Typesense filters):
```php
public static function search($query = '', $callback = null)
{
    $query = self::traitSearch($query, $callback)->whereIn('apps', [app(Apps::class)->getId()]);
    $user = auth()->user();
    if ($user instanceof UserInterface && ! $user->isAppOwner()) {
        $query->whereIn('companies', [$user->currentCompanyId()]);
    }

    return $query;
}
```

#### Key rules for `search()`:

- **Always filter by `apps_id`** — no exceptions
- **Always filter by `companies_id`** for non-app-owners — prevents cross-company data leaks
- **Use `isAppOwner()`** (not `isAdmin()`) for the company-scoping check — `isAppOwner()` returns `true` only for `@guardByAppKey` requests with Owner role
- `isAdmin()` returns `true` for any Admin/Owner role regardless of auth method, which would skip company filtering on `@guard` endpoints
- **Check `CompaniesBranches` binding** when the entity is branch-scoped — this ensures the correct company context when a request targets a specific branch
- **Filter field names vary by search engine**: Algolia uses nested paths like `company.id`, Typesense/database use flat `companies_id`. Match what's in `toSearchableArray()`
- **`@search` bypasses `@paginate(builder:)` scoping** — When Lighthouse's `@search` directive is active, it calls `Model::search()` and results come entirely from the search engine. The custom builder specified in `@paginate(builder: ...)` is **NOT applied**. The `search()` method is the **only** place to enforce multi-tenancy during search
- **When using `search()` in a custom builder** (not via `@search`), call `traitSearch()` directly with explicit filters instead of the model's `search()` method, since `search()` auto-scopes to the logged-in user's company which may not be the target company

**Typesense schema requirement:** Models using `DynamicSearchableTrait` that may use the Typesense engine **MUST implement `typesenseCollectionSchema()`**. Without it, the Typesense engine throws `Parameter 'fields' is required` when creating the collection. The method should define fields matching `toSearchableArray()`.

**Placement:** Place the `search()` method at the **end of the class**, not at the top. Properties (`$table`, `$guarded`, `casts()`) and relationships should come first.

## Key Conventions

### No Inline Fully-Qualified Class Names
Always use `use` imports at the top of the file instead of inline fully-qualified class names (FQCNs). This applies to both code **and** docblock `@property`/`@param`/`@return` annotations.

```php
// WRONG — inline FQCN
$this->next_retry_at = \Illuminate\Support\Carbon::parse($retryAt);

// WRONG — FQCN in docblock
/** @property \Illuminate\Support\Carbon|null $approved_at */

// CORRECT — use import + short name everywhere
use Illuminate\Support\Carbon;

/** @property Carbon|null $approved_at */

$this->next_retry_at = Carbon::parse($retryAt);
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
- **Mutation resolvers look up models** from IDs and construct DTOs manually with named args — do NOT use `::from($request['input'])` when the DTO has object properties
- **Actions receive only the DTO** (and optionally the existing model for updates) — they pull IDs via `$this->data->taskList->getId()`

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
- **DatabaseSearchableTrait** - adds `@search` support using database engine (for simple models)
- **DynamicSearchableTrait** - adds `@search` support with Algolia/Typesense (for indexed models like Products, Leads)

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

### Scoping Patterns
- **Global entities** (companies_id = 0): scope queries with `fromApp` + `notDeleted`
- **Company-scoped entities**: scope queries with `fromCompany` + `fromApp` + `notDeleted`
- Lookups: `Model::getById($id, $app)` for global, `Model::getByIdFromCompanyApp($id, $company, $app)` for company-scoped

### JSON/Array Fields
If a model has JSON columns, add casts:
```php
protected function casts(): array
{
    return [
        'form_fields' => 'array',
        'form_config' => 'array',
    ];
}
```

### GraphQL Query Naming
Check existing query names in `graphql/schemas/` before naming yours to avoid Lighthouse "Duplicate definition" merge errors.

### Code Style
- **No section separator comments** — do not add `// --- SectionName ---`, `# --- SectionName ---`, or similar decorative dividers in code, tests, or schema files. Test methods and code sections are self-documenting by their names. If a file grows too large, split it into separate files instead.

## Testing

### Running Tests

Tests **must run inside the Docker container**, never locally:

```bash
# Run a specific test by name
docker exec -it phpkanvas-ecosystem bash -c "cd /var/www/html && php vendor/bin/phpunit --filter testCreateAction"

# Run a full test suite
docker exec -it phpkanvas-ecosystem bash -c "cd /var/www/html && php vendor/bin/paratest --testsuite=ActionEngine"

# Run a specific test file
docker exec -it phpkanvas-ecosystem bash -c "cd /var/www/html && php vendor/bin/phpunit tests/GraphQL/ActionEngine/ActionCrudTest.php"
```

### Available Test Suites
Unit, Ecosystem, GraphQL, Inventory, Social, Guild, Connectors, Workflow, Intelligence, Baka, Souk, Event, ActionEngine

### Key Rules

- **Always run tests after completing work on a module or connector** — run the relevant test suite to verify nothing is broken before moving on, unless explicitly told otherwise
- **Never use `RefreshDatabase` trait** — it wipes all shared DB tables across connections. Use `DatabaseTransactions` instead
- Base `TestCase` loads `.env` (not `.env.testing`), no `RefreshDatabase` by default
- Base `TestCase` provides `$this->graphQL()` via Lighthouse's `MakesGraphQLRequests` trait
- User is auto-authenticated in `createApplication()` with admin role

### Common Test Patterns

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

### Setting Up Bouncer Permissions in Tests

When mutations use `@can` directives, the test must set up Bouncer scope, assign the role to the user, and grant abilities to the role:

```php
use Kanvas\AccessControlList\Enums\RolesEnums;
use Silber\Bouncer\BouncerFacade as Bouncer;

class {Entity}CrudTest extends TestCase // or extends OrderBase, etc.
{
    public function setUp(): void
    {
        parent::setUp();

        // 1. Set Bouncer scope (use global: true for companyId 0)
        $scope = RolesEnums::getScope($this->apps, global: true);
        Bouncer::scope()->to($scope);

        // 2. Assign role to the test user
        Bouncer::assign('Admins')->to($this->user);

        // 3. Grant abilities to the role for each model
        Bouncer::allow('Admins')->to(['create', 'edit', 'delete'], {Entity}::class);
    }
}
```

**Key points:**
- `RolesEnums::getScope($app, global: true)` returns scope `app_{id}_company_0` (global scope)
- `Bouncer::assign('Admins')->to($this->user)` is **required** — without it, `@can` checks will return "unauthorized"
- `Bouncer::allow('Admins')->to([...], Model::class)` grants abilities to the role, abilities must match schema: `create`, `edit`, `delete`

### Common Test Fix Patterns

- **FK constraint errors in factories**: Check if factory hardcodes IDs (e.g., `agent_type_id => 1`) — use `RelatedModel::factory()` instead
- **Time-dependent tests**: Use `Carbon::setTestNow()` to freeze time
- **Silent failures via Sentry**: Actions that catch exceptions with `captureException()` — add temporary `echo` in catch block to debug
- **AI/Prism calls**: Use `Prism::fake()` with enough responses for all sessions
- **Duplicate key violations**: Check if action classes already create related records internally
- **Mock objects**: Set `$mock->exists = true` when the code checks `$this->model->exists`
