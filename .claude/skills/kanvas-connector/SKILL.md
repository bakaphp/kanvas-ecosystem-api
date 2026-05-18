---
name: kanvas-connector
description: Build a new external-service integration under `src/Domains/Connectors/{ConnectorName}/` — Handler + Client + DTO + Enums + Webhook job + Workflow activity + GraphQL setup mutation + `integrations` row. Load when adding/editing a connector (Shopify, Stripe, WaSender, Microsoft, OpenClaw, Hermes, etc.) or its associated `app/GraphQL/Connector/` / `graphql/schemas/Connector/` files. **NOTE**: `AgentRuntime` is a primary domain at `src/Domains/Intelligence/AgentRuntime/`, NOT a connector — see the "AgentRuntime is a primary domain" section before touching it.
---

# Kanvas Connector Pattern

Connectors integrate Kanvas with external services (Shopify, Stripe, WaSender, etc.). All connectors live under `src/Domains/Connectors/`.

## Project Structure

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
└── Activities/                         # Temporal workflow activities (flat, NOT Workflows/Activities/)

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

## 1. Handler (Required)

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

## 2. Configuration Enums

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

## 3. Client (for External APIs)

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

## 4. DTO

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

## 5. Webhook Job (if receiving webhooks)

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

## 6. Workflow Activities (for long-running async operations)

Extend `KanvasActivity` which provides `executeIntegration()` for status tracking and history logging.

Place new activities in `src/Domains/Connectors/{ConnectorName}/Activities/` (flat — NOT `Workflows/Activities/` or `Workflow/`).

```php
<?php

declare(strict_types=1);

namespace Kanvas\Connectors\{ConnectorName}\Activities;

use Kanvas\Workflow\KanvasActivity;

class Sync{Entity}Activity extends KanvasActivity
{
    public function execute($entity, Apps $app, array $params): array
    {
        return $this->executeIntegration(
            entity: $entity,
            app: $app,
            integration: IntegrationsEnum::{CONNECTOR},
            additionalParams: $params,
            integrationOperation: function () use ($entity) {
                return new Sync{Entity}Action($entity)->execute();
            },
            company: $entity->company,
        );
    }
}
```

**Important:** Always pass `additionalParams: $params` to `executeIntegration()`. Without it, the system cannot retry the activity with the correct parameters.

## 7. GraphQL Setup Mutation

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

## 8. Register the Integration

Add to `IntegrationsEnum` (`src/Domains/Workflow/Enums/IntegrationsEnum.php`):

```php
enum IntegrationsEnum: string
{
    // ... existing connectors
    case {CONNECTOR} = '{connector_name}';
}
```

Seed a row in the `integrations` table mapping the name to the handler class. Always provide the SQL insert when shipping a new connector:

```sql
INSERT INTO integrations (name, uuid, apps_id, config, handler, actions_id, receivers_id, is_deleted, created_at, updated_at)
VALUES ('{connector_name}', UUID(), 0, '{"api_key": {"type": "text", "required": true}, "api_secret": {"type": "text", "required": true}}', 'Kanvas\\Connectors\\{ConnectorName}\\Handlers\\{ConnectorName}Handler', NULL, NULL, 0, NOW(), NOW());
```

`config` JSON describes the setup fields the handler expects (`type: text`, `required: true/false`). `apps_id = 0` means global (available to all apps). Reference the `DriveCentric` row for format.

## Connector Checklist

- [ ] **Enums**: `ConfigurationEnum` + `CustomFieldEnum`
- [ ] **Handler**: Extends `BaseIntegration` with `setup()` method
- [ ] **Client**: Guzzle HTTP client (if external API)
- [ ] **DTO**: Configuration/credentials data object
- [ ] **Service**: Core service class (optional)
- [ ] **Actions**: Business logic (sync, import, etc.) in `Actions/`
- [ ] **Webhook Job**: Extends `ProcessWebhookJob` (if receiving webhooks)
- [ ] **Workflow Activities**: In `Activities/` (flat, not nested)
- [ ] **GraphQL**: Schema + mutation for setup
- [ ] **Register**: Add to `IntegrationsEnum`, seed `integrations` table, register activities in `KanvasWorkflowSynActionCommand`
- [ ] **Tests**: Configuration trait + integration tests in `tests/Connectors/`

## Multi-Tenancy Notes

- **App-level** configs (shared endpoints, base URLs): `$app->set()`
- **Company-level** configs (API keys, tokens): `$company->set()`
- **Region-scoped** credentials use composite keys: `CREDENTIAL-{appId}-{companyId}-{regionId}`
- Integration status tracked per company via `IntegrationsCompany` model (ACTIVE, INACTIVE, FAILED, OFFLINE)
- Every integration operation logged in `EntityIntegrationHistory` for auditing

## Never Cache SDK Instances in Static Properties (Octane footgun)

Do **not** cache external-SDK clients (Twilio, Shopify, VoiceBridge, etc.) in `private static array $instances = []` keyed by `app_X` / `company_X`. Under Swoole/Octane the worker process is long-lived, so any static state survives across requests on that worker. When a tenant rotates credentials in DB + Redis, workers that already cached the old client keep serving requests with stale keys — manifests as intermittent 4xx/auth errors that hit *some* requests but not others (the ones that landed on a worker that hadn't cached yet).

```php
// WRONG — stale credentials per worker after key rotation
final class Client
{
    private static array $instances = [];

    public static function getInstance(AppInterface $app): SomeSDK
    {
        $key = sprintf('app_%s', $app->getId());
        return self::$instances[$key] ??= new SomeSDK($app->get('api_key'));
    }
}

// CORRECT — thin factory, always reads fresh creds
final class Client
{
    public static function getInstance(AppInterface $app): SomeSDK
    {
        return new SomeSDK($app->get('api_key'));
    }
}
```

Building an SDK client is almost always cheap (string assignments, no network handshake) — there's no real perf win to caching it, and the correctness cost is intermittent stale-creds bugs that are painful to reproduce. Same rule applies to any singleton-style `protected static ?SDK $instance = null` pattern. If you genuinely need to cache something heavy, key it on a credential fingerprint (`hash($sid.$token)`), not the app/company id, so rotation invalidates the cache automatically — but prefer just rebuilding.

The same Octane risk applies to **any mutable static state on connector classes** (e.g. `protected static string $environment` mutated via `setEnvironment()`). Those mutations persist across requests on the same worker and cause cross-tenant state bleed. Keep environment/region per-instance, or pass at call time.

## AgentRuntime is a primary domain, not a connector (foot-guns)

OpenClaw, Hermes (and future Nano) live under `src/Domains/Connectors/`, but **`AgentRuntime` itself is a primary domain** at `src/Domains/Intelligence/AgentRuntime/`. The connector folders only hold per-runtime implementations of the shared `AgentRuntimeProvider` contract — they don't own GraphQL, dispatch, or the public surface. If you see `app/GraphQL/Connector/AgentRuntime/`, `graphql/schemas/Connector/agentruntime.graphql`, `hermesLaunchAgent`, `openclawTerminateAgent`, or any per-runtime mutation, that's the wrong shape — delete it. The whole graph is `agentRuntime*` and routes by `agent_deployments.provider`.

**Provider source of truth:** `agent.agentType.provider` pre-launch (no deployment yet) and `agent_deployments.provider` post-launch. There is **no `agents.agent_provider`** column — don't read or add one. Resolvers always go through [`AgentRuntimeProviderFactory`](../../../src/Domains/Intelligence/AgentRuntime/Providers/AgentRuntimeProviderFactory.php) (`forAgent` / `forDeployment` / `forProvider`); never inject a DI container or instantiate a concrete provider. We tried a service-provider + registry once and deleted it — providers are stateless and the set is closed, so a static `match` is the right answer; don't rebuild the registry.

**Per-runtime variation belongs on [`ProviderConfig`](../../../src/Domains/Intelligence/AgentRuntime/Contracts/ProviderConfig.php), not in `Base*Action` bodies.** If a new variation point shows up (directory name, CLI alias, config filename, image name, custom-field key) add a field to `ProviderConfig` and populate it in every connector's `SshClient::makeProviderConfig()`. The cost of hardcoding the OpenClaw spelling in a base action is silent breakage on Hermes; the same applies the other direction.

**Per-agent credentials that aren't runtime-specific (Slack tokens, Telegram tokens, future channel API keys) live on the primary domain under [`AgentChannelTokenEnum`](../../../src/Domains/Intelligence/AgentRuntime/Enums/AgentChannelTokenEnum.php) — one shared key per credential, NOT `OPENCLAW_SLACK_BOT_TOKEN` + `HERMES_SLACK_BOT_TOKEN`.** A Slack token's value doesn't change based on which runtime reads it; the runtime's `DockerComposeBuilder` picks up the shared key and injects the canonical container env var (`SLACK_BOT_TOKEN`). If you find yourself adding `<PROVIDER>_<CREDENTIAL>` to a connector's `CustomFieldEnum`, ask first whether the credential is genuinely runtime-specific. Things that ARE runtime-specific and DO belong on the per-connector enum: gateway tokens (each runtime issues its own), deployment ids (each runtime gets its own deployment row), workspace paths.

**Cross-runtime migration is "target adopts source", not "source pushes to target".** `AgentRuntimeProvider::dispatchAdoptForeignDeployment` is implemented on the **destination** runtime (today: Hermes consumes OpenClaw deployments) — not on the source. The single mutation is `agentRuntimeMigrateAgentToProvider` with a `target_provider` field. Don't add a `hermesMigrateFromOpenclaw` (we deleted that one).

## Workflow Activity Registration

Register new workflow activities in `app/Console/Commands/Workflows/KanvasWorkflowSynActionCommand.php` (runs on deploy):
1. Add `use` import at top
2. Add `ClassName::class` to the `$actions` array

Without registration, the activity won't appear in the workflow rules UI.
