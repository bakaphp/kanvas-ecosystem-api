# Kanvas Ecosystem to Odoo ERP Integration Specification

> **Status:** Draft implementation spec — ready for hand-off to an engineering agent.
> **Owning domain:** `src/Domains/Connectors/Odoo` (follows the existing Kanvas Connector pattern — see `.claude/skills/kanvas-connector/SKILL.md`).
> **Integration key:** `IntegrationsEnum::ODOO` (`'odoo'`).
> **Protocol:** Odoo External API over **XML-RPC** (`/xmlrpc/2/common`, `/xmlrpc/2/object`).
> **Trigger mechanism:** Kanvas Nervous System domain events → Laravel Event Listeners → Laravel Queue Jobs.

---

## 1. Title and Executive Summary

**Kanvas Ecosystem to Odoo ERP Integration Specification**

This document specifies a **Laravel-based (PHP), event-driven architecture** that keeps Odoo ERP (Accounting, Inventory, Sales, Purchase) synchronized with operational entities created inside the Kanvas Ecosystem (Products, Customers/Organizations, Orders, Vendors).

The integration does **not** call Odoo synchronously inside the request/response cycle of Kanvas GraphQL mutations. Instead:

1. A Kanvas domain action (e.g. `CreateProductAction`, `Order::markAsPaid()`) persists the entity and fires a **domain event** (a plain Laravel event, optionally also recorded on the **Nervous System Ledger** via `EmitsNervousSystemEvents` / `EmitsLedgerEventsForEntity` for observability and replay).
2. A dedicated **Listener** (e.g. `ProductCreatedListener`, `OrderPaidListener`) living in `src/Domains/Connectors/Odoo/Listeners` receives that event and dispatches a **Queue Job** (e.g. `SyncProductToOdooJob`, `SyncOrderToOdooJob`) onto a dedicated `odoo` queue.
3. The Job — built on a shared `SyncToOdooJob` base class — talks to Odoo through an `OdooClient` (implementing `OdooClientInterface`) over XML-RPC, using the standard Kanvas Connector conventions: `IntegrationsCompany` for credential lookup, `EntityIntegrationHistory` for audit trail, and the existing `KanvasActivity`/`executeIntegration()` pattern for retries when the sync is orchestrated through a Workflow Rule instead of a raw queue job.

This gives Kanvas **asynchronous, retry-capable, eventually-consistent** synchronization with Odoo, matching the pattern already used by the `QuickBooks`, `NetSuite`, `Acumatica`, and `Shopify` connectors in this codebase, while satisfying the specific technical requirements of the Odoo XML-RPC API (dual-step auth, positional-argument RPC calls, no bulk/batch endpoint, no native webhooks).

**Non-goals:** real-time synchronous dual writes, direct PostgreSQL access to Odoo's database, and pre-checkout stock reservation in Odoo. These are covered explicitly in [Section 4](#4-what-is-not-possible-known-limitations--guardrails).

---

## 2. How We Will Code It (The Concrete Coding Blueprint)

### 2.1 Directory & Namespacing Structure

Follow the established Kanvas Connector layout (`src/Domains/Connectors/{ConnectorName}/...`, PSR-4 root `Kanvas\` → `src/Kanvas` **and** `src/Domains` per `composer.json`, so the connector namespace is `Kanvas\Connectors\Odoo\...`).

```
src/Domains/Connectors/Odoo/
├── Contracts/
│   └── OdooClientInterface.php          # RPC contract (connect/create/update/searchRead/...)
├── Services/
│   ├── OdooClient.php                    # XML-RPC implementation of OdooClientInterface
│   ├── OdooConfigurationService.php      # Resolves per-company/app Odoo credentials
│   ├── OdooCustomerSyncService.php       # res.partner mapping + upsert logic
│   ├── OdooProductSyncService.php        # product.template / product.product mapping
│   ├── OdooSalesOrderSyncService.php     # sale.order + order lines mapping
│   └── OdooVendorSplitService.php        # cart → per-vendor purchase.order logic (Section 5)
├── DataTransferObject/
│   ├── Odoo.php                          # credentials/config DTO (viaRequest)
│   ├── OdooPartnerPayload.php
│   ├── OdooProductPayload.php
│   └── OdooSalesOrderPayload.php
├── Enums/
│   ├── ConfigurationEnum.php             # odoo_base_url, odoo_db, odoo_username, odoo_api_key
│   ├── CustomFieldEnum.php               # ODOO_PARTNER_ID, ODOO_PRODUCT_TEMPLATE_ID, ODOO_SALE_ORDER_ID, ODOO_PURCHASE_ORDER_ID
│   └── OdooModelEnum.php                 # res.partner, product.template, sale.order, purchase.order, ...
├── Exceptions/
│   ├── OdooAuthenticationException.php
│   ├── OdooRpcException.php
│   └── OdooMappingException.php
├── Handlers/
│   └── OdooHandler.php                   # extends BaseIntegration, validates + stores credentials
├── Events/                                # Kanvas-side domain events that *feed* this connector
│   ├── ProductCreated.php
│   ├── ProductUpdated.php
│   ├── CustomerCreated.php
│   └── OrderPaid.php
├── Listeners/
│   ├── ProductCreatedListener.php
│   ├── ProductUpdatedListener.php
│   ├── CustomerCreatedListener.php
│   └── OrderPaidListener.php
├── Jobs/
│   ├── SyncToOdooJob.php                 # abstract base: retries/backoff/logging/history
│   ├── SyncProductToOdooJob.php
│   ├── SyncCustomerToOdooJob.php
│   ├── SyncOrderToOdooJob.php
│   └── SyncVendorPurchaseOrderToOdooJob.php
├── Activities/                            # Workflow-engine variant (for Workflow Rule driven syncs)
│   ├── SyncProductToOdooActivity.php
│   └── SyncOrderToOdooActivity.php
└── Providers/
    └── OdooServiceProvider.php            # binds OdooClientInterface, registers event→listener map
```

```
app/GraphQL/Connector/Odoo/
└── Mutations/
    └── OdooMutation.php                   # odooSetup(input: OdooSetupInput!): Boolean

graphql/schemas/Connector/
└── odoo.graphql

tests/Connectors/Odoo/
├── OdooClientTest.php
├── OdooCustomerSyncServiceTest.php
├── OdooProductSyncServiceTest.php
├── OdooSalesOrderSyncServiceTest.php
└── OdooVendorSplitServiceTest.php
```

**Namespacing rule:** every class under `src/Domains/Connectors/Odoo/` is namespaced `Kanvas\Connectors\Odoo\...` (mirrors `Kanvas\Connectors\Shopify\...`, `Kanvas\Connectors\QuickBooks\...`). Do **not** invent a new top-level `Odoo\` namespace — it must live inside the existing `Connectors` domain so it is picked up by `IntegrationsEnum`, `IntegrationsCompany`, and the workflow action discovery command.

### 2.2 Registering the Integration

1. Add to `src/Domains/Workflow/Enums/IntegrationsEnum.php`:

```php
case ODOO = 'odoo';
```

2. Seed the `integrations` table (per the connector skill convention):

```sql
INSERT INTO integrations (name, uuid, apps_id, config, handler, actions_id, receivers_id, is_deleted, created_at, updated_at)
VALUES (
    'odoo',
    UUID(),
    0,
    '{"odoo_base_url": {"type": "text", "required": true}, "odoo_db": {"type": "text", "required": true}, "odoo_username": {"type": "text", "required": true}, "odoo_api_key": {"type": "password", "required": true}}',
    'Kanvas\\Connectors\\Odoo\\Handlers\\OdooHandler',
    NULL,
    NULL,
    0,
    NOW(),
    NOW()
);
```

3. Register `OdooServiceProvider` in `bootstrap/providers.php` (or `config/app.php` providers array, matching whichever mechanism this Laravel version uses).

4. Register any `Activities` classes with `#[WorkflowAction]` — auto-discovered by `kanvas:workflow-sync-actions`; no manual step needed beyond the attribute.

### 2.3 Key PHP Interfaces & Classes

#### 2.3.1 `OdooClientInterface`

```php
<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Odoo\Contracts;

interface OdooClientInterface
{
    /**
     * Performs the `common.authenticate` handshake against /xmlrpc/2/common
     * and caches the resulting Odoo `uid` for the lifetime of this instance.
     *
     * @throws \Kanvas\Connectors\Odoo\Exceptions\OdooAuthenticationException
     */
    public function connect(): int;

    /**
     * Odoo `create` — returns the new record id.
     *
     * @param string $model e.g. 'res.partner', 'product.template', 'sale.order'
     * @param array<string, mixed> $data field => value map
     */
    public function create(string $model, array $data): int;

    /**
     * Odoo `write` — returns true on success.
     *
     * @param array<string, mixed> $data field => value map
     */
    public function update(string $model, int $id, array $data): bool;

    /**
     * Odoo `search_read` — combined search + read in a single RPC call.
     *
     * @param array<int, mixed> $domain Odoo domain filter, e.g. [['email', '=', 'a@b.com']]
     * @param array<int, string> $fields fields to return; empty = all fields
     *
     * @return array<int, array<string, mixed>>
     */
    public function searchRead(string $model, array $domain, array $fields = []): array;

    /**
     * Odoo `search` — returns matching ids only.
     *
     * @param array<int, mixed> $domain
     *
     * @return array<int, int>
     */
    public function search(string $model, array $domain): array;

    /**
     * Odoo `unlink` — soft/hard delete depending on model constraints.
     */
    public function delete(string $model, int $id): bool;

    /**
     * Generic escape hatch for any Odoo model method
     * (e.g. `action_confirm` on sale.order, `button_confirm` on purchase.order).
     *
     * @param array<int, mixed> $args positional args
     * @param array<string, mixed> $kwargs keyword args (context, etc.)
     */
    public function call(string $model, string $method, array $args = [], array $kwargs = []): mixed;
}
```

#### 2.3.2 `OdooClient` (XML-RPC implementation)

Recommended package: `ripaclet/odoo-client` (thin XML-RPC wrapper purpose-built for Odoo's two-endpoint auth model). If that package is unavailable/unmaintained at implementation time, fall back to `php-xmlrpc/php-xmlrpc` (`PhpXmlRpc\Client`, `PhpXmlRpc\Request`, `PhpXmlRpc\Value`) and implement the same interface — the public contract (`OdooClientInterface`) must not change either way.

```php
<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Odoo\Services;

use Kanvas\Connectors\Odoo\Contracts\OdooClientInterface;
use Kanvas\Connectors\Odoo\Exceptions\OdooAuthenticationException;
use Kanvas\Connectors\Odoo\Exceptions\OdooRpcException;
use Ripaclet\OdooClient\Client as RipacletClient; // or PhpXmlRpc\Client, see class comment above
use Throwable;

final class OdooClient implements OdooClientInterface
{
    private ?int $uid = null;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $database,
        private readonly string $username,
        private readonly string $apiKeyOrPassword,
    ) {
    }

    public function connect(): int
    {
        if ($this->uid !== null) {
            return $this->uid;
        }

        try {
            $common = new RipacletClient($this->baseUrl . '/xmlrpc/2/common');
            $uid = $common->call('authenticate', [
                $this->database,
                $this->username,
                $this->apiKeyOrPassword,
                [], // extra auth context, usually empty
            ]);
        } catch (Throwable $e) {
            throw new OdooAuthenticationException(
                'Failed to authenticate against Odoo common endpoint: ' . $e->getMessage(),
                previous: $e,
            );
        }

        if (! is_int($uid) || $uid <= 0) {
            throw new OdooAuthenticationException('Odoo rejected the credentials (uid was falsy).');
        }

        return $this->uid = $uid;
    }

    public function create(string $model, array $data): int
    {
        return (int) $this->call($model, 'create', [$data]);
    }

    public function update(string $model, int $id, array $data): bool
    {
        return (bool) $this->call($model, 'write', [[$id], $data]);
    }

    public function searchRead(string $model, array $domain, array $fields = []): array
    {
        return (array) $this->call($model, 'search_read', [$domain, $fields]);
    }

    public function search(string $model, array $domain): array
    {
        return (array) $this->call($model, 'search', [$domain]);
    }

    public function delete(string $model, int $id): bool
    {
        return (bool) $this->call($model, 'unlink', [[$id]]);
    }

    public function call(string $model, string $method, array $args = [], array $kwargs = []): mixed
    {
        $uid = $this->connect();

        try {
            $object = new RipacletClient($this->baseUrl . '/xmlrpc/2/object');

            return $object->call('execute_kw', [
                $this->database,
                $uid,
                $this->apiKeyOrPassword,
                $model,
                $method,
                $args,
                $kwargs,
            ]);
        } catch (Throwable $e) {
            throw new OdooRpcException(
                sprintf('Odoo RPC call %s.%s failed: %s', $model, $method, $e->getMessage()),
                previous: $e,
            );
        }
    }
}
```

> **Octane / long-lived worker safety:** per the repository's connector conventions, **never** cache `OdooClient` instances in a `private static array $instances = []` keyed by app/company id. Always build a fresh client per job execution from `OdooConfigurationService::getCredentials($app, $company, $region)`, which reads live credentials on every call. This avoids the exact stale-credential class of bug documented in `.claude/skills/kanvas-connector/SKILL.md`.

#### 2.3.3 Base Job: `SyncToOdooJob`

```php
<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Odoo\Jobs;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Kanvas\Connectors\Odoo\Exceptions\OdooRpcException;
use Kanvas\Connectors\Odoo\Services\OdooConfigurationService;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Integrations\Actions\AddEntityIntegrationHistoryAction;
use Kanvas\Workflow\Integrations\DataTransferObject\EntityIntegrationHistory as EntityIntegrationHistoryDto;
use Throwable;

/**
 * Shared retry/backoff/logging/audit-trail behaviour for every Odoo sync job.
 * Concrete jobs implement `handle()`'s protected `sync()` step only.
 */
abstract class SyncToOdooJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public string $queue = 'odoo';

    /** Total attempts before the job is moved to `failed_jobs`. */
    public int $tries = 5;

    /** Exponential-ish backoff (seconds) between retries. */
    public array $backoff = [10, 30, 60, 300, 900];

    /** Hard ceiling so a stuck RPC call can't block the worker forever. */
    public int $timeout = 120;

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company,
    ) {
    }

    final public function handle(): void
    {
        $client = OdooConfigurationService::makeClient($this->app, $this->company);

        try {
            $result = $this->sync($client);

            Log::channel('odoo')->info('odoo.sync.success', [
                'job' => static::class,
                'company_id' => $this->company->getId(),
                'result' => $result,
            ]);

            $this->recordHistory($result, null);
        } catch (Throwable $e) {
            Log::channel('odoo')->error('odoo.sync.failure', [
                'job' => static::class,
                'company_id' => $this->company->getId(),
                'attempt' => $this->attempts(),
                'error' => $e->getMessage(),
            ]);

            $this->recordHistory(null, $e);

            // Let Laravel's retry/backoff machinery decide whether to retry
            // or move to failed_jobs based on $tries/$backoff above.
            throw new OdooRpcException($e->getMessage(), previous: $e);
        }
    }

    /**
     * Concrete jobs implement the actual Odoo RPC calls here and return a
     * small serializable result array for the audit trail / logs.
     *
     * @return array<string, mixed>
     */
    abstract protected function sync(\Kanvas\Connectors\Odoo\Contracts\OdooClientInterface $client): array;

    /**
     * Persist to EntityIntegrationHistory so failures are visible in the
     * existing Kanvas integration-history UI, same as every other connector.
     */
    protected function recordHistory(?array $result, ?Throwable $exception): void
    {
        // Implementations resolve $integrationCompany + $entity + $status via
        // ActivityIntegrationTrait-equivalent helpers and call
        // AddEntityIntegrationHistoryAction (see QuickBooks/NetSuite connectors
        // for the exact call shape already used in this codebase).
    }

    public function failed(Throwable $exception): void
    {
        Log::channel('odoo')->critical('odoo.sync.exhausted_retries', [
            'job' => static::class,
            'company_id' => $this->company->getId(),
            'error' => $exception->getMessage(),
        ]);
    }
}
```

**Concrete job example:**

```php
<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Odoo\Jobs;

use Kanvas\Connectors\Odoo\Contracts\OdooClientInterface;
use Kanvas\Connectors\Odoo\Services\OdooProductSyncService;
use Kanvas\Inventory\Products\Models\Products;

class SyncProductToOdooJob extends SyncToOdooJob
{
    public function __construct(
        \Baka\Contracts\AppInterface $app,
        \Baka\Contracts\CompanyInterface $company,
        protected Products $product,
    ) {
        parent::__construct($app, $company);
    }

    protected function sync(OdooClientInterface $client): array
    {
        $odooProductId = (new OdooProductSyncService($client, $this->company))
            ->upsertFromKanvasProduct($this->product);

        return ['odoo_product_template_id' => $odooProductId];
    }
}
```

#### 2.3.4 Event Listeners: Nervous System Event → Job Dispatch

Kanvas events are plain Laravel events fired from domain Actions/Model methods (`Order::markAsPaid()`, `CreateProductAction::execute()`, etc.). Each event has exactly one dedicated Listener whose only job is to translate "something happened in Kanvas" into "dispatch the right Odoo sync job, on the right queue, for the right tenant."

```php
<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Odoo\Listeners;

use Kanvas\Connectors\Odoo\Jobs\SyncProductToOdooJob;
use Kanvas\Inventory\Products\Events\ProductCreatedEvent; // or the new Odoo-scoped ProductCreated event
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;

class ProductCreatedListener
{
    public function handle(ProductCreatedEvent $event): void
    {
        $product = $event->getProduct();
        $company = $product->company;
        $app = $product->app;

        // Skip entirely if this tenant hasn't configured Odoo — avoids
        // queueing dead-letter jobs for every tenant on every product save.
        if (! IntegrationsCompany::isActive(IntegrationsEnum::ODOO, $company)) {
            return;
        }

        SyncProductToOdooJob::dispatch($app, $company, $product)
            ->onQueue('odoo');
    }
}
```

```php
<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Odoo\Listeners;

use Kanvas\Connectors\Odoo\Jobs\SyncOrderToOdooJob;
use Kanvas\Souk\Orders\Events\OrderPaidEvent; // new event fired from Order::markAsPaid()
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;

class OrderPaidListener
{
    public function handle(OrderPaidEvent $event): void
    {
        $order = $event->getOrder();

        if (! IntegrationsCompany::isActive(IntegrationsEnum::ODOO, $order->company)) {
            return;
        }

        // Order sync ALSO triggers the vendor split (Section 5) — kept as a
        // chained job rather than inline logic so a vendor PO failure never
        // blocks/rolls back the main sale.order sync.
        SyncOrderToOdooJob::dispatch($order->app, $order->company, $order)
            ->onQueue('odoo');
    }
}
```

Register the map in `EventServiceProvider` (or `OdooServiceProvider::boot()` via `Event::listen(...)`):

```php
protected $listen = [
    \Kanvas\Inventory\Products\Events\ProductCreatedEvent::class => [
        \Kanvas\Connectors\Odoo\Listeners\ProductCreatedListener::class,
    ],
    \Kanvas\Inventory\Products\Events\ProductUpdatedEvent::class => [
        \Kanvas\Connectors\Odoo\Listeners\ProductUpdatedListener::class,
    ],
    \Kanvas\Guild\Customers\Events\CustomerCreatedEvent::class => [
        \Kanvas\Connectors\Odoo\Listeners\CustomerCreatedListener::class,
    ],
    \Kanvas\Souk\Orders\Events\OrderPaidEvent::class => [
        \Kanvas\Connectors\Odoo\Listeners\OrderPaidListener::class,
    ],
];
```

> **Note on existing events:** `ProductUpdateEvent` and `OrderUpdateEvent` already exist in this codebase (`src/Domains/Inventory/Products/Events`, `src/Domains/Souk/Orders/Events`) but implement `ShouldBroadcast` for websocket pushes, not domain semantics like "paid" or "created". Do **not** repurpose them. Add new, narrowly-scoped domain events (`ProductCreatedEvent`, `OrderPaidEvent`, etc.) fired explicitly from the relevant Action/model method (e.g. inside `Order::markAsPaid()` right after `firePaidSideEffects()`), so the Odoo listener isn't accidentally triggered by every broadcast tick.

### 2.4 Idempotency & Field Mapping Storage

Every Kanvas entity that has a corresponding Odoo record stores the Odoo id via the existing custom-fields mechanism (`CustomFieldEnum`), the same pattern used by Shopify/QuickBooks/NetSuite:

| Kanvas entity | Custom field key | Stores |
|---|---|---|
| `Organizations` / `Companies` (customer) | `ODOO_PARTNER_ID` | `res.partner.id` |
| `Products` | `ODOO_PRODUCT_TEMPLATE_ID` | `product.template.id` |
| `Variants` | `ODOO_PRODUCT_VARIANT_ID` | `product.product.id` |
| `Order` | `ODOO_SALE_ORDER_ID` | `sale.order.id` |
| `OrderItem` | `ODOO_SALE_ORDER_LINE_ID` | `sale.order.line.id` |
| `Vendor` split PO | `ODOO_PURCHASE_ORDER_ID` | `purchase.order.id` (see Section 5) |

Every sync service **must** check for the presence of the mapped id before calling `create()` — if present, call `update()` instead. This makes every job **safely retryable** (idempotent), which is required because `$tries = 5` in `SyncToOdooJob` means a transient network failure can re-run the same job.

---

## 3. API Integration Technical Specifics

### 3.1 Authentication Handshake (dual-step XML-RPC)

Odoo's External API exposes two separate XML-RPC endpoints. There is no session/cookie state and no OAuth token — every single call re-sends the full identity tuple.

**Step 1 — `common` endpoint (identity check, no model access):**

```
POST {ODOO_BASE_URL}/xmlrpc/2/common
Method: authenticate
Args:   [db, login, password_or_api_key, {}]
Returns: int uid   (or `false` on invalid credentials)
```

PHP call shape (regardless of client library):

```php
$uid = $commonClient->call('authenticate', [
    $db,                 // e.g. "kanvas_prod"
    $login,              // e.g. "integration@kanvas.dev"
    $apiKeyOrPassword,   // Odoo API Key (preferred) or user password
    [],                  // extra context, normally empty array
]);
```

- `uid` is a plain integer identifying the authenticated Odoo user. It is **not** a bearer token and does **not** expire — it is safe to re-derive on every request (cheap network round-trip) or cache in-process for the duration of a single job execution only (never across requests/workers, see Octane note in §2.3.2).
- As of Odoo 14+, **API Keys** (generated under the user's Odoo profile → "Account Security" → "API Keys") should be used instead of the raw account password in the `password_or_api_key` slot. This is what should be stored as `odoo_api_key` in Kanvas.

**Step 2 — `object` endpoint (model actions, every CRUD op):**

```
POST {ODOO_BASE_URL}/xmlrpc/2/object
Method: execute_kw
Args:   [db, uid, password_or_api_key, model, method, args, kwargs]
```

PHP call shape:

```php
$result = $objectClient->call('execute_kw', [
    $db,
    $uid,
    $apiKeyOrPassword,
    'res.partner',      // model
    'create',           // method: create | write | search_read | search | unlink | any custom method
    [$data],            // positional args — always an array-of-arrays per Odoo's RPC convention
    [],                 // kwargs — e.g. ['context' => ['lang' => 'en_US']]
]);
```

Key rules to encode in `OdooClient::call()`:

- `args` is **always** an array whose first element is itself an array (e.g. `create` takes `[[$fields]]`, `write` takes `[[$ids], $fields]`, `search_read` takes `[$domain, $fields]`).
- Odoo returns `false` (XML-RPC boolean), not `null`, for "nothing found" on singular lookups — `OdooClient` methods must normalize `false` results to empty arrays/`0`/`null` at the boundary so calling code doesn't have to special-case XML-RPC booleans everywhere.
- Any Odoo-side `ValidationError`/`UserError`/`AccessError` (e.g. a required custom field that isn't set) comes back as an XML-RPC `<fault>` element, which the client library converts into a PHP exception carrying the Odoo Python traceback string as the message — this is the only signal Kanvas gets that a required-field validation failed (see §4.2).

### 3.2 Concrete Payload Mappings

#### 3.2.1 Creating a Customer (`res.partner`)

Kanvas source: `Organizations` (Guild) or `Companies`, mapped by `OdooCustomerSyncService`.

```php
// PHP array sent as `create` args[0]
[
    'name'          => $organization->name,                       // required
    'email'         => $organization->primary_email,
    'street'        => $organization->address_line_1,
    'street2'       => $organization->address_line_2,
    'city'          => $organization->city,
    'zip'           => $organization->zip_code,
    'phone'         => $organization->phone,
    'customer_rank' => 1,          // marks the partner as a customer in Odoo's CRM/Sales views
    'company_type'  => 'company',  // 'company' | 'person'
    'ref'           => (string) $organization->getId(), // Kanvas id round-trip for reconciliation
]
```

Equivalent over-the-wire XML-RPC payload (illustrative JSON representation of the RPC call):

```json
{
    "method": "execute_kw",
    "params": [
        "kanvas_prod",
        7,
        "**********",
        "res.partner",
        "create",
        [
            {
                "name": "Acme Distribution LLC",
                "email": "ap@acmedist.com",
                "street": "500 Industrial Pkwy",
                "zip": "07001",
                "phone": "+1-555-010-2222",
                "customer_rank": 1,
                "company_type": "company",
                "ref": "10432"
            }
        ]
    ]
}
```

Response: integer `res.partner` id, stored on the Kanvas `Organizations` record under `ODOO_PARTNER_ID`.

#### 3.2.2 Creating a Product Template (`product.template`)

Kanvas source: `Products` (Inventory), mapped by `OdooProductSyncService`.

```php
[
    'name'         => $product->name,                       // required
    'list_price'   => (float) $variant->default_price,      // sales price
    'standard_price' => (float) $variant->cost,             // cost, used for COGS in §5
    'type'         => 'consu',                               // 'consu' (goods) | 'service' | 'combo'
    'is_storable'  => true,                                   // Odoo 17+: replaces old 'product' type; enables stock tracking
    'categ_id'     => $odooCategoryId,                        // product.category id, resolved/mapped ahead of time
    'default_code' => $variant->sku,                          // internal reference / SKU
    'barcode'      => $variant->upc,
]
```

Illustrative JSON RPC payload:

```json
{
    "method": "execute_kw",
    "params": [
        "kanvas_prod",
        7,
        "**********",
        "product.template",
        "create",
        [
            {
                "name": "Kanvas Branded T-Shirt - Medium",
                "list_price": 24.99,
                "standard_price": 9.50,
                "type": "consu",
                "is_storable": true,
                "categ_id": 12,
                "default_code": "KTS-MED-001",
                "barcode": "0123456789012"
            }
        ]
    ]
}
```

Response: integer `product.template` id, stored under `ODOO_PRODUCT_TEMPLATE_ID`. If the product has variants (size/color), Odoo auto-generates the corresponding `product.product` records; fetch them via `searchRead('product.product', [['product_tmpl_id', '=', $templateId]], ['id', 'default_code'])` and store each `product.product.id` under `ODOO_PRODUCT_VARIANT_ID` on the matching Kanvas `Variants` row (matched by `default_code`/SKU).

#### 3.2.3 Creating a Sales Order (`sale.order`)

Kanvas source: `Order` + `OrderItem[]` (Souk), mapped by `OdooSalesOrderSyncService`.

```php
[
    'partner_id'  => $odooPartnerId,               // required, res.partner.id resolved from Order::company/organization
    'client_order_ref' => (string) $order->uuid,   // round-trip reference back to Kanvas
    'date_order'  => $order->created_at->toDateTimeString(),
    'order_line'  => [
        // Odoo one2many "commands": (0, 0, {values}) = create a new line
        [0, 0, [
            'product_id'      => $odooProductVariantId, // product.product.id (NOT product.template.id)
            'product_uom_qty' => (float) $item->quantity,
            'price_unit'      => (float) $item->unit_price_net_amount,
        ]],
        [0, 0, [
            'product_id'      => $odooProductVariantId2,
            'product_uom_qty' => 2.0,
            'price_unit'      => 14.50,
        ]],
    ],
]
```

Illustrative JSON RPC payload:

```json
{
    "method": "execute_kw",
    "params": [
        "kanvas_prod",
        7,
        "**********",
        "sale.order",
        "create",
        [
            {
                "partner_id": 245,
                "client_order_ref": "b6a7a6b0-2f3f-4b7d-9c9e-6a2f0e8e1a11",
                "date_order": "2026-08-03 14:22:10",
                "order_line": [
                    [0, 0, {"product_id": 981, "product_uom_qty": 1.0, "price_unit": 24.99}],
                    [0, 0, {"product_id": 982, "product_uom_qty": 2.0, "price_unit": 14.50}]
                ]
            }
        ]
    ]
}
```

Response: integer `sale.order` id, stored under `ODOO_SALE_ORDER_ID`. To move the order out of the Odoo `draft` quotation state into a confirmed sale (which is what actually reserves/commits stock — see §4.4), a **second** RPC call is required after creation:

```php
$client->call('sale.order', 'action_confirm', [[$saleOrderId]]);
```

The `(0, 0, {...})` tuple syntax is Odoo's standard "one2many command" protocol used for every nested/related record write across the entire API (also used for `purchase.order.order_line` in §5). The three most relevant commands are:

| Command | Meaning |
|---|---|
| `(0, 0, {values})` | Create a new related record with `{values}` |
| `(1, id, {values})` | Update existing related record `id` with `{values}` |
| `(2, id, 0)` | Delete related record `id` |

---

## 4. What Is Not Possible (Known Limitations & Guardrails)

### 4.1 Strict Two-Way Transactional Consistency

Kanvas and Odoo are two independently-committed databases (MySQL/Kanvas, PostgreSQL/Odoo) connected only by a network API call. There is **no distributed transaction coordinator** between them — a two-phase commit across an HTTP/XML-RPC boundary is not something either system supports natively, and building one (XA transactions, saga compensating-transaction engines) would add enormous complexity for a marginal consistency gain that most ERP integrations don't actually need.

Consequences the implementing engineer must design for:
- A Kanvas `Order` can be marked `PAID` and committed to MySQL *before* the corresponding `sale.order` is confirmed in Odoo (the queue job runs after the HTTP response). If the Odoo call fails, the **order remains valid and paid in Kanvas** — Odoo sync is a downstream projection, not a precondition for the sale.
- We accept **eventual consistency**: the Nervous System Ledger (`src/Domains/NervousSystem/Ledger`) plus `EntityIntegrationHistory` provide the audit trail needed to detect and manually/automatically reconcile drift (failed jobs land in `failed_jobs` / dead-letter queue and are retried or alerted on).
- Do not add code that rolls back a Kanvas order/payment because the *Odoo* call failed. The failure must be isolated to the Odoo sync job.

### 4.2 Odoo Custom Field Validation

Odoo installations are frequently customized by the client's Odoo implementation partner: required fields are added to `res.partner`, `product.template`, or `sale.order` via Studio, custom modules, or Python `_sql_constraints` / `@api.constrains` decorators, entirely inside Odoo's own codebase.

- Kanvas has **no visibility** into these rules ahead of time. There is no schema-introspection call in this spec that reliably enumerates "is this field required right now" (Odoo's `fields_get` RPC method *can* report `required: true` for statically-required fields, but does not report dynamically computed/conditional requirements defined in Python `@api.constrains`).
- **Practical guardrail:** treat every `create`/`write` RPC call as capable of throwing an `OdooRpcException` wrapping an Odoo `ValidationError`. `SyncToOdooJob` must catch, log the full Odoo fault message (it contains the human-readable field name in most cases), and surface it in `EntityIntegrationHistory` as a `FAILED` status — **not** silently swallow it.
- When a new required field appears on the Odoo side, the fix is **always** on the Kanvas side: extend the relevant `OdooXSyncService::mapXPayload()` method with the new field, backed by a new `CustomFieldEnum`/mapping-config entry if the value needs to come from a Kanvas custom field. There is no way to make this automatic — flag it explicitly as a manual "mapping config out of date" runbook item, and alert on repeated `OdooRpcException` for the same model/method combination (e.g. via a scheduled job that scans `EntityIntegrationHistory` for repeated Odoo failures in the last hour and pages the integration owner).

### 4.3 Direct Database Manipulation

**Never** connect directly to Odoo's PostgreSQL instance from Kanvas code, migrations, or ad-hoc scripts, even for "just reading a value."

- Odoo's ORM (`models.Model`) enforces record rules, access control lists (`ir.rule`, `ir.model.access`), computed/related fields, and `@api.constrains`/`@api.onchange` business logic entirely in Python at the ORM layer — **none of that runs if you `INSERT`/`UPDATE` PostgreSQL rows directly.** Direct writes will silently desynchronize computed fields (stock quantities, accounting move lines, `product.template` ↔ `product.product` variant linkage), corrupt multi-company record rules, and can violate Odoo's own foreign-key/consistency invariants in ways that are very hard to detect until a report breaks weeks later.
- It also breaks any workflow automations/triggers configured inside Odoo (server actions, automated actions, mail templates) that only fire on ORM-level `create`/`write`/`unlink` calls — direct SQL writes are invisible to them.
- **The XML-RPC (`/xmlrpc/2/object`) API is the only sanctioned integration surface.** If a future requirement seems to need direct DB access for performance, escalate — the correct answer is almost always "batch the RPC calls" or "add an Odoo-side custom RPC-exposed method," not a direct DB connection.

### 4.4 Pre-checkout Real-time Stock Reservation

Do **not** call `sale.order.action_confirm()` (or otherwise reserve stock via `stock.quant`) at add-to-cart time or during checkout-in-progress (e.g. while a payment provider 3DS challenge is pending).

- Odoo's stock reservation (`stock.move` / `stock.quant` locking) uses row-level PostgreSQL locks on the underlying quant records. Under concurrent checkout traffic, reserving stock speculatively for carts that may never convert creates lock contention and can measurably degrade Odoo's inventory throughput, especially on high-SKU-count warehouses.
- It also creates orphaned reservations that must be cleaned up (Odoo does have scheduled "unreserve" cron jobs for abandoned quotations, but relying on that as the primary mechanism is fragile and delays real availability for other shoppers).
- **Guardrail implemented in this spec:** `sale.order` is created in Odoo (as a `draft` quotation, no stock impact) only from the `OrderPaidListener` path — i.e., **after** payment capture is already confirmed inside Kanvas (`Order::markAsPaid()`). `action_confirm()` (which triggers stock reservation/delivery order creation) is called immediately after creation in that same job, not before. Kanvas inventory levels (`Variants`/`VariantsWarehouses`) remain the source of truth for storefront "in stock" display up through checkout; Odoo's stock module is a downstream fulfillment/accounting system, not the real-time availability check.

---

## 5. Vendor/Souk Split Logic Implementation

Kanvas Souk order line items (`OrderItem`) can originate from different **vendors** (in this codebase's marketplace model, the vendor is the `Companies` record that owns the selling `Variants`/`Products` — exposed today via `Variants->product->company` or a dedicated `vendor_id` if/when the multi-vendor marketplace schema adds one explicitly; confirm the exact column at implementation time and adjust `OdooVendorSplitService::resolveVendorForItem()` accordingly). When a single Kanvas cart/order contains items from multiple vendors, Odoo needs **one `sale.order` (billing the end customer)** plus **one `purchase.order` per vendor (Odoo's representation of "we owe this vendor for these goods")**, linked via Odoo's dropship routing so fulfillment flows directly vendor → customer without the item ever needing to sit in the marketplace operator's own warehouse.

### 5.1 Step-by-step split algorithm

Implemented in `OdooVendorSplitService::splitAndSyncPurchaseOrders(Order $order, int $saleOrderId)`, invoked from `SyncOrderToOdooJob` right after the main `sale.order` is created (§3.2.3):

1. **Group line items by vendor.**
   ```php
   $itemsByVendor = $order->items->groupBy(
       fn (OrderItem $item) => $item->variant->product->company_id // or dedicated vendor_id
   );
   ```

2. **Resolve each vendor's Odoo `res.partner` id.** Every vendor `Companies` record must itself be synced as a `res.partner` with `supplier_rank >= 1` (mirrors §3.2.1, but with `supplier_rank` set instead of/in addition to `customer_rank`). If the vendor has no Odoo partner id yet (`ODOO_PARTNER_ID` custom field empty), synchronously create it inline before proceeding — vendor onboarding is low-volume enough that this does not need its own async job, but it **must** still go through `OdooClient`, not be hardcoded.

3. **Compute vendor cost-of-goods (COGS) per line.** Use the vendor's `standard_price` (cost) captured on the Kanvas `Variants`/pricing record at the time the product was synced to Odoo (§3.2.2), **not** the customer-facing `price_unit` used on the `sale.order` line. This is the amount the marketplace operator owes the vendor, independent of markup/commission logic already applied elsewhere in Souk.
   ```php
   $purchaseLine = [
       'product_id'      => $odooProductVariantId,
       'product_qty'      => $item->quantity,
       'price_unit'       => $variant->cost, // vendor cost, not customer price
   ];
   ```

4. **Create one `purchase.order` per vendor group**, with `order_line` built via the same `(0, 0, {...})` one2many command syntax as `sale.order.order_line`:
   ```php
   $purchaseOrderId = $client->create('purchase.order', [
       'partner_id' => $vendorOdooPartnerId,
       'origin'     => 'SO' . $saleOrderId, // human-readable back-reference; Odoo also tracks this natively via the dropship route
       'order_line' => $vendorLines->map(fn ($line) => [0, 0, $line])->all(),
   ]);
   ```

5. **Link the purchase order to the sales order via Odoo's dropship (or cross-dock) route**, rather than a manual reference field, so Odoo's own MTO (Make-To-Order) procurement engine drives fulfillment automatically:
   - The clean, low-maintenance approach is to set the **route** on the relevant `sale.order.line` (or on the product's `route_ids`) to Odoo's built-in **"Dropship"** route (module `stock_dropshipping`) *before* confirming the sale order. When `action_confirm()` runs, Odoo's procurement engine (`MTO` + `Dropship` route combination) auto-generates the matching `purchase.order` with the vendor as `partner_id`, sourced from the product's configured vendor (`product.supplierinfo`) — this is the **native, most robust** mechanism, and step 4 above becomes optional/redundant for it *if* every vendor-product pairing is pre-registered as a `product.supplierinfo` record on the `product.template`.
   - If per-order dynamic vendor assignment is required (i.e., the vendor for a given SKU can vary order-to-order, which the native route mechanism doesn't support well since it's keyed off `product.supplierinfo`, not the order), use the **explicit approach** in steps 3–4 above and set `purchase.order.origin` **and** write the `purchase.order.id` back onto the originating `sale.order.line` via a custom field (`x_studio_source_sale_order_line_id` or equivalent Studio-added field, coordinated with the Odoo implementation partner) so the linkage is queryable from either side. Record this decision explicitly in `ODOO_PURCHASE_ORDER_ID` custom fields on the Kanvas side regardless of which mechanism Odoo uses internally.
6. **Confirm the purchase order** so it becomes actionable for the vendor (RFQ → confirmed PO), mirroring the `action_confirm` step used for `sale.order`:
   ```php
   $client->call('purchase.order', 'button_confirm', [[$purchaseOrderId]]);
   ```
7. **Persist the mapping** — store `purchase.order.id` under `ODOO_PURCHASE_ORDER_ID` on a per-vendor-split record (e.g. a new lightweight `OrderVendorSplit` model keyed by `order_id` + `vendor_company_id`, or reuse `EntityIntegrationHistory` payload if a dedicated table is overkill for the initial version) so retries are idempotent per vendor group, not per whole order — a failure syncing vendor B's purchase order must not force re-creating vendor A's already-successful purchase order.
8. **Failure isolation:** each vendor's purchase order sync should be its own queued job (`SyncVendorPurchaseOrderToOdooJob`, one per vendor group, dispatched from `SyncOrderToOdooJob` after the main `sale.order` succeeds) so that one vendor's Odoo validation failure (§4.2) doesn't block the others or the main sales order sync.

---

## 6. Implementation Hand-off Blueprint & Checklist

A programming agent implementing this spec should proceed in this exact order:

1. **Add the `ripaclet/odoo-client` (or `php-xmlrpc/php-xmlrpc`) composer dependency** and confirm it resolves cleanly against the project's PHP version; wrap it entirely behind `OdooClientInterface` so the choice of library is swappable later without touching any calling code.
2. **Scaffold the directory tree** in `src/Domains/Connectors/Odoo/` exactly as laid out in §2.1, including empty `Enums/ConfigurationEnum.php` and `Enums/CustomFieldEnum.php` with the keys listed in §2.4.
3. **Implement `OdooClient` + `OdooClientInterface`** (§2.3.1–2.3.2) with unit tests that mock the underlying XML-RPC transport — do not hit a live Odoo instance in unit tests; add a separate `tests/Connectors/Odoo/*IntegrationTest.php` suite gated behind an env flag (`ODOO_INTEGRATION_TESTS=true`) for real-instance smoke tests, matching the `Traits/Has{ConnectorName}Configuration.php` pattern used by other connectors.
4. **Implement `OdooHandler extends BaseIntegration`**, validating `odoo_base_url`, `odoo_db`, `odoo_username`, `odoo_api_key` and performing a live `connect()` call as the credential-validation step of `setup()`.
5. **Implement the three sync services** (`OdooCustomerSyncService`, `OdooProductSyncService`, `OdooSalesOrderSyncService`) with the exact payload shapes from §3.2, each with an idempotent "does a mapped Odoo id already exist on this entity's custom fields → update, else → create" branch (§2.4).
6. **Add the new narrowly-scoped domain events** (`ProductCreatedEvent`, `ProductUpdatedEvent`, `CustomerCreatedEvent`, `OrderPaidEvent`) fired from the correct existing Action/model method (e.g. `Order::markAsPaid()` for `OrderPaidEvent`) — do **not** reuse the existing broadcast-only `ProductUpdateEvent`/`OrderUpdateEvent` classes (§2.3.4 note).
7. **Implement `SyncToOdooJob` and its concrete subclasses**, plus the `Listeners/`, and register the event→listener map in the service provider (§2.3.3–2.3.4). Verify retries: force a transient `OdooRpcException` in a test and assert the job is retried per `$backoff` and lands in `failed_jobs` only after `$tries` exhausted.
8. **Implement `OdooVendorSplitService`** per §5, as its own queued job per vendor group, with the idempotent per-vendor-group persistence described in step 7 of §5.
9. **Register the integration**: add `IntegrationsEnum::ODOO`, seed the `integrations` SQL row (§2.2), add the GraphQL `odooSetup` mutation + schema (mirroring `.claude/skills/kanvas-connector/SKILL.md` §7), and register any `#[WorkflowAction]`-tagged `Activities/` classes.
10. **Write the test suite and runbook**: unit tests for every payload mapping (§3.2) asserting the exact array shape sent to `OdooClient`; an integration test for the full "create product → create customer → create order → split vendor POs" happy path against a disposable Odoo instance/mock; and a short `docs/` runbook entry (or addendum to this file) documenting the manual steps to take when Odoo rejects a payload due to a newly-added required field (§4.2), including how to find the failing field in the raw `OdooRpcException` message inside `EntityIntegrationHistory`.
