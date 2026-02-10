# Kanvas Ecosystem API

## Testing
- **Tests must run inside Docker container**, not locally
- **Never use `RefreshDatabase` trait** - it wipes all shared DB tables across connections. Use `DatabaseTransactions` instead
- **ParaTest** is used for parallel test execution: `vendor/bin/paratest --testsuite=<name>`
- Test suites: Unit, Ecosystem, GraphQL, Inventory, Social, Guild, Connectors, Workflow, Intelligence, Baka, Souk, Event, ActionEngine
- Base `TestCase` loads `.env` (not `.env.testing`), no RefreshDatabase by default
- Tests run inside Docker: `docker exec phpkanvas-ecosystem bash -c "cd /var/www/html && php vendor/bin/phpunit --filter {TestName}"`
- Base `TestCase` provides `$this->graphQL()` via Lighthouse's `MakesGraphQLRequests` trait
- User is auto-authenticated in `createApplication()` with admin role

## Architecture Notes
- Multi-database: kanvas ecosystem, action_engine, intelligence, commerce
- PHP 8.4: Use `new Foo(...)->execute()` not `(new Foo(...))->execute()`
- All models use `is_deleted` boolean flag (not Laravel's `SoftDeletes` trait). Use `$model->softDelete()` and the `notDeleted` scope

## CRUD Guide

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

### Step-by-Step CRUD Creation

#### 1. Data Transfer Object (DTO)

Location: `src/Domains/{Domain}/{Entity}/DataTransferObject/{Entity}.php`

Name the DTO class after the entity (e.g., `Action`, `Pipeline`). When importing in files that also use the model, alias it: `use ...\DataTransferObject\{Entity} as {Entity}Data;`

```php
<?php

declare(strict_types=1);

namespace Kanvas\{Domain}\{Entity}\DataTransferObject;

use Spatie\LaravelData\Data;

class {Entity} extends Data
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $description = null,
        // ... other fields
    ) {
    }
}
```

#### 2. Create Action

Location: `src/Domains/{Domain}/{Entity}/Actions/Create{Entity}Action.php`

```php
<?php

declare(strict_types=1);

namespace Kanvas\{Domain}\{Entity}\Actions;

use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Facades\DB;

class Create{Entity}Action
{
    public function __construct(
        protected readonly {Entity}Input $data,
        protected readonly UserInterface $user,
        protected readonly AppInterface $app,
    ) {
    }

    public function execute(): {Entity}
    {
        // Use the model's DB connection for transactions
        return DB::connection('{db_connection}')->transaction(function () {
            $entity = new {Entity}();
            $entity->apps_id = $this->app->getId();
            $entity->companies_id = 0; // 0 for global entities
            $entity->users_id = $this->user->getId();
            $entity->name = $this->data->name;
            // ... set other fields
            $entity->saveOrFail();

            return $entity;
        });
    }
}
```

#### 3. Update Action

Location: `src/Domains/{Domain}/{Entity}/Actions/Update{Entity}Action.php`

```php
<?php

declare(strict_types=1);

namespace Kanvas\{Domain}\{Entity}\Actions;

use Illuminate\Support\Facades\DB;

class Update{Entity}Action
{
    public function __construct(
        protected readonly {Entity} $entity,
        protected readonly {Entity}Input $data,
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

#### 4. GraphQL Mutation Resolver

Location: `app/GraphQL/{Domain}/Mutations/{Entity}/{Entity}Mutation.php`

```php
<?php

declare(strict_types=1);

namespace App\GraphQL\{Domain}\Mutations\{Entity};

use Kanvas\Apps\Models\Apps;

class {Entity}Mutation
{
    public function create(mixed $rootValue, array $request): {Entity}
    {
        $user = auth()->user();
        $app = app(Apps::class);

        return new Create{Entity}Action(
            {Entity}Input::from($request['input']),
            $user,
            $app,
        )->execute();
    }

    public function update(mixed $rootValue, array $request): {Entity}
    {
        $app = app(Apps::class);
        // For global entities (no company scoping):
        $entity = {Entity}::getById((int) $request['id'], $app);
        // For company-scoped entities:
        // $entity = {Entity}::getByIdFromCompanyApp((int) $request['id'], $user->getCurrentCompany(), $app);

        return new Update{Entity}Action(
            $entity,
            {Entity}Input::from($request['input']),
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

#### 5. GraphQL Schema

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

#### 6. Tests

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
        $input = ['name' => 'Test ' . fake()->word(), ...];

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
        // Create first, then update using returned ID
        $createResponse = $this->graphQL('...create mutation...', ['input' => $input])->assertSuccessful();
        $id = $createResponse->json('data.create{Entity}.id');

        $this->graphQL('
            mutation($id: ID!, $input: Update{Entity}Input!) {
                update{Entity}(id: $id, input: $input) { id name }
            }
        ', ['id' => $id, 'input' => $updateInput])
        ->assertSuccessful();
    }

    public function testDelete{Entity}(): void
    {
        // Create first, then delete
        $createResponse = $this->graphQL('...create mutation...', ['input' => $input])->assertSuccessful();
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

### Key Conventions

#### Database Connections
Each domain has its own database connection defined in the domain's BaseModel:
- `action_engine` - ActionEngine domain
- `crm` - Guild domain (leads, pipelines, etc.)
- `inventory` - Inventory domain
- `social` - Social domain

Use the correct connection in `DB::connection('{connection}')->transaction()`.

#### Model Base Classes & Traits
- **BaseModel** per domain (e.g., `Kanvas\ActionEngine\Models\BaseModel`) - sets DB connection, includes `KanvasModelTrait`
- **KanvasModelTrait** - provides `fromCompany`, `fromApp`, `notDeleted` scopes, `getById()`, `getByIdFromCompanyApp()`, `softDelete()`
- **UuidTrait** - auto-generates UUID on creation
- **SlugTrait** - auto-generates slug from `name` field
- **AppsIdTrait** - auto-sets `apps_id` from current app context on creation

#### Authorization Directives
- `@guard` - any authenticated user
- `@guardByAdmin` - admin/owner only (uses `isAdmin()` check)
- `@guardByAppKey` - app key (super admin / system) only

#### Scoping Patterns
- **Global entities** (companies_id = 0): scope queries with `fromApp` + `notDeleted`
- **Company-scoped entities**: scope queries with `fromCompany` + `fromApp` + `notDeleted`
- Lookups: `Model::getById($id, $app)` for global, `Model::getByIdFromCompanyApp($id, $company, $app)` for company-scoped

#### GraphQL Query Naming
Check existing query names in `graphql/schemas/` before naming yours to avoid Lighthouse "Duplicate definition" merge errors.

---

## Connector Guide

### Project Structure

```
src/Domains/Connectors/{ConnectorName}/
├── Actions/                          # Business logic (Push/Pull/Sync)
│   ├── Push{Entity}Action.php
│   ├── Pull{Entity}Action.php
│   └── Sync{Entity}Action.php
├── Workflows/
│   └── Activities/                   # Temporal workflow activities
│       └── Sync{Entity}Activity.php
├── DataTransferObject/
│   └── {Connector}.php               # Connector config DTO
├── Enums/
│   ├── ConfigurationEnum.php         # App/region config keys
│   └── CustomFieldEnum.php           # Entity custom field identifiers
├── Handlers/
│   └── {Connector}Handler.php        # Integration setup (extends BaseIntegration)
├── Services/
│   └── {Entity}Service.php           # API interaction / data mapping
├── Webhooks/
│   └── Process{Connector}WebhookJob.php  # Extends ProcessWebhookJob
├── Jobs/                             # Background jobs (optional)
├── Traits/                           # Custom field helpers (optional)
└── Client.php                        # API client (singleton pattern)

app/Console/Commands/Connectors/{ConnectorName}/
└── {Command}.php                     # CLI sync/setup commands
```

### Step-by-Step Connector Creation

#### 1. Handler (Integration Setup)

Location: `src/Domains/Connectors/{Connector}/Handlers/{Connector}Handler.php`

Extends `Kanvas\Connectors\Contracts\BaseIntegration`. Called when the integration is set up via GraphQL `integrationCompany` mutation.

```php
<?php

declare(strict_types=1);

namespace Kanvas\Connectors\{Connector}\Handlers;

use Kanvas\Connectors\Contracts\BaseIntegration;

class {Connector}Handler extends BaseIntegration
{
    public function setup(): bool
    {
        // 1. Store credentials in company/app settings
        $this->company->set(
            ConfigurationEnum::API_KEY->value,
            $this->data['api_key']
        );

        // 2. Validate connection (test API call)
        $client = new Client($this->app, $this->company);
        return $client->testConnection();
    }
}
```

#### 2. Client (API Client)

Location: `src/Domains/Connectors/{Connector}/Client.php`

Use singleton pattern with connection pooling per app/company/region:

```php
<?php

declare(strict_types=1);

namespace Kanvas\Connectors\{Connector};

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;

final class Client
{
    private static array $instances = [];

    public static function getInstance(
        AppInterface $app,
        CompanyInterface $company,
    ): self {
        $key = $app->getId() . '-' . $company->getId();
        if (! isset(self::$instances[$key])) {
            self::$instances[$key] = new self($app, $company);
        }
        return self::$instances[$key];
    }

    private function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company,
    ) {
        $this->apiKey = $app->get(ConfigurationEnum::API_KEY->value);
        // Setup HTTP client
    }
}
```

#### 3. Enums

**ConfigurationEnum** — App/company-level config keys:

```php
enum ConfigurationEnum: string
{
    case API_KEY = 'connector_api_key';
    case API_SECRET = 'connector_api_secret';
    case ACTIVITY_QUEUE = 'sync-connector-queue';
}
```

**CustomFieldEnum** — Entity custom field identifiers for mapping external IDs:

```php
enum CustomFieldEnum: string
{
    case EXTERNAL_PRODUCT_ID = 'CONNECTOR_PRODUCT_ID';
    case EXTERNAL_CUSTOMER_ID = 'CONNECTOR_CUSTOMER_ID';
}
```

#### 4. Actions

Location: `src/Domains/Connectors/{Connector}/Actions/`

Naming convention:
- `Push{Entity}Action` — Send data TO external system
- `Pull{Entity}Action` — Get data FROM external system
- `Sync{Entity}Action` — Bidirectional sync

```php
<?php

declare(strict_types=1);

namespace Kanvas\Connectors\{Connector}\Actions;

class SyncProductAction
{
    public function __construct(
        protected Products $product,
    ) {
    }

    public function execute(): array
    {
        $client = Client::getInstance($this->product->app, $this->product->company);
        // Push/pull logic
        return $result;
    }
}
```

#### 5. Workflow Activity

Location: `src/Domains/Connectors/{Connector}/Workflows/Activities/{Name}Activity.php`

Extends `Kanvas\Workflow\KanvasActivity`. Use `executeIntegration()` wrapper for automatic error handling and history tracking.

```php
<?php

declare(strict_types=1);

namespace Kanvas\Connectors\{Connector}\Workflows\Activities;

use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

class SyncProductActivity extends KanvasActivity
{
    public $tries = 3;

    public function execute(Products $product, Apps $app, array $params): array
    {
        $this->overwriteAppService($app);

        return $this->executeIntegration(
            entity: $product,
            app: $app,
            integration: IntegrationsEnum::YOUR_CONNECTOR,
            integrationOperation: function () use ($product) {
                return new SyncProductAction($product)->execute();
            },
            company: $product->company,
        );
    }
}
```

#### 6. Webhook Processor

Location: `src/Domains/Connectors/{Connector}/Webhooks/Process{Connector}WebhookJob.php`

Extends `Kanvas\Workflow\Jobs\ProcessWebhookJob`. Provides `$this->webhookRequest` (payload) and `$this->receiver` (config).

```php
<?php

declare(strict_types=1);

namespace Kanvas\Connectors\{Connector}\Webhooks;

use Kanvas\Workflow\Jobs\ProcessWebhookJob;

class Process{Connector}WebhookJob extends ProcessWebhookJob
{
    public function execute(): array
    {
        $payload = $this->webhookRequest->payload;
        $integrationCompanyId = $this->receiver->configuration['integration_company_id'];

        // Process webhook payload
        return ['message' => 'Processed successfully'];
    }
}
```

#### 7. Console Command

Location: `app/Console/Commands/Connectors/{Connector}/{Command}.php`

```php
<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\{Connector};

use Illuminate\Console\Command;

class SyncProductsCommand extends Command
{
    protected $signature = 'kanvas:{connector}:sync-products {app_id} {company_id}';

    public function handle(): void
    {
        $app = Apps::getById((int) $this->argument('app_id'));
        $company = Companies::getById((int) $this->argument('company_id'));

        // Sync logic
    }
}
```

#### 8. Register in IntegrationsEnum

Location: `src/Domains/Workflow/Enums/IntegrationsEnum.php`

```php
enum IntegrationsEnum: string
{
    // ... existing connectors
    case YOUR_CONNECTOR = 'your_connector';
}
```

### Key Patterns

- **Configuration storage**: Use `$app->set()` / `$company->set()` with enum keys
- **External ID mapping**: Store external IDs as entity custom fields via `$product->set(CustomFieldEnum::EXTERNAL_ID->value, $externalId)`
- **Multi-region support**: Include region ID in config keys: `'api_key-' . $region->getId()`
- **Activity error handling**: Always use `executeIntegration()` wrapper — it handles error logging, history tracking, and workflow status
- **`overwriteAppService($app)`**: Call this in activities to set the correct app context
