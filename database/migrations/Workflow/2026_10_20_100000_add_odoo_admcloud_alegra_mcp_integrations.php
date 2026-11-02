<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\Connectors\Mcp\Handlers\McpHandler;
use Kanvas\Workflow\Enums\IntegrationTypeEnum;

/**
 * Three ERP / accounting servers.
 *
 *  - Odoo (20.0+) serves `/mcp` on each database, so the admin gives the URL per connection. OAuth is for
 *    internal users and needs the Odoo admin to tick "Allow Dynamic Client Registration" under Settings →
 *    Integrations → MCP OAuth (we publish no CIMD document); portal users and anyone who skips that use an
 *    API key with the MCP scope as the bearer. Unprobed — no live Odoo 20 instance at hand. `odoo` is the
 *    native connector's row, hence `odoo_mcp`.
 *  - ADM Cloud, probed 2026-10-20: 401 → path-aware protected-resource document → the server itself as
 *    authorization server, PKCE S256, public clients, `/register` accepted `{app.url}/v1/oauth/callback` →
 *    one click. Only scope advertised is `mcp`. 149 tools that create and delete posted accounting
 *    documents, and the vendor says deletes cannot be undone, so every `delete_*` is excluded; widening is a
 *    decision, not a fix. Company and user group are picked in-band with `select_company` /
 *    `select_user_group`.
 *  - Alegra, probed 2026-10-20: 401 on the origin (`/mcp` is 404) → `auth-api.alegra.com`, PKCE S256, public
 *    clients, a registration endpoint (not exercised), scope `owner`. Read-only by the vendor's own
 *    statement, and authorising needs an Alegra admin login.
 *
 * Both probed resources name themselves without a path; if the token step answers `invalid_target`, set
 * `include_resource: false` (Amplitude precedent).
 */
return new class () extends Migration {
    protected $connection = 'workflow';

    private const array ADMCLOUD_DELETES = [
        'delete_account',
        'delete_bill',
        'delete_bill_payment',
        'delete_contact',
        'delete_credit_memo',
        'delete_customer',
        'delete_department',
        'delete_deposit',
        'delete_employee',
        'delete_invoice',
        'delete_item',
        'delete_item_class',
        'delete_kit',
        'delete_opportunity',
        'delete_payment',
        'delete_payment_term',
        'delete_purchase_order',
        'delete_quote',
        'delete_sales_order',
        'delete_sales_receipt',
        'delete_service',
        'delete_time_off',
        'delete_transfer',
        'delete_vendor',
        'delete_vendor_credit',
    ];

    public function up(): void
    {
        foreach ($this->servers() as $name => $metadata) {
            $exists = DB::connection('workflow')->table('integrations')
                ->where('name', $name)
                ->where('apps_id', 0)
                ->exists();

            if ($exists) {
                continue;
            }

            DB::connection('workflow')->table('integrations')->insert([
                'uuid' => (string) Str::uuid(),
                'name' => $name,
                'handler' => McpHandler::class,
                'type' => IntegrationTypeEnum::MCP->value,
                'apps_id' => 0,
                'config' => json_encode([]),
                'metadata' => json_encode($metadata),
                'is_deleted' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::connection('workflow')->table('integrations')
            ->whereIn('name', array_keys($this->servers()))
            ->where('apps_id', 0)
            ->delete();
    }

    private function servers(): array
    {
        return [
            'odoo_mcp' => [
                'vendor' => 'Odoo',
                'url_per_connection' => true,
                'transport' => 'http',
                'auth_methods' => [
                    'oauth',
                    'bearer',
                ],
                'prefix' => 'odoo',
                'exclude' => [],
                'timeout_ms' => 30000,
            ],
            'admcloud_mcp' => [
                'vendor' => 'ADM Cloud',
                'url' => 'https://mcp.admcloud.net/mcp',
                'url_per_connection' => false,
                'transport' => 'http',
                'auth_methods' => ['oauth'],
                'oauth' => [
                    'scopes' => ['mcp'],
                ],
                'prefix' => 'admcloud',
                'exclude' => self::ADMCLOUD_DELETES,
                'timeout_ms' => 30000,
            ],
            'alegra_mcp' => [
                'vendor' => 'Alegra',
                'url' => 'https://mcp.alegra.com',
                'url_per_connection' => false,
                'transport' => 'http',
                'auth_methods' => ['oauth'],
                'oauth' => [
                    'scopes' => ['owner'],
                ],
                'prefix' => 'alegra',
                'exclude' => [],
                'timeout_ms' => 30000,
            ],
        ];
    }
};
