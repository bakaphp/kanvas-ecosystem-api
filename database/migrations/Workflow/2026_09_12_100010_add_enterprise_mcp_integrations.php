<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\Connectors\Mcp\Handlers\McpHandler;
use Kanvas\Workflow\Enums\IntegrationTypeEnum;

/**
 * Five vendor MCP servers that have no single address: each company connects its OWN one, so all of them
 * take `url_per_connection` like n8n.
 *
 *  - SQL Server ships inside Data API Builder and is hosted by whoever runs the database.
 *  - Salesforce hosts per org, at `api.salesforce.com/platform/mcp/v1/d/{myDomain}/{server}` (Enterprise
 *    Edition and above), with OAuth + PKCE and the `mcp_api` scope.
 *  - SAP's BTP administration server is a remote endpoint per landscape.
 *  - Shopify's is the STOREFRONT surface at `{shop}.myshopify.com/api/mcp` — catalog, cart and policies,
 *    open to the public, which is why it needs no credential. It is not an admin API.
 *  - BigCommerce gives each storefront its own URL in the control panel, behind a bearer token, and is
 *    still in beta.
 *
 * Salesforce and Shopify also have native Kanvas connectors; these rows are the per-agent alternative,
 * and an agent should hold one or the other.
 */
return new class () extends Migration {
    protected $connection = 'workflow';

    private const array SERVERS = [
        'mssql_mcp' => [
            'vendor' => 'Microsoft SQL Server',
            'prefix' => 'mssql',
            'auth_methods' => ['bearer', 'none'],
            'timeout_ms' => 30000,
            'oauth' => null,
        ],
        'salesforce_mcp' => [
            'vendor' => 'Salesforce',
            'prefix' => 'salesforce',
            'auth_methods' => ['oauth', 'bearer'],
            'timeout_ms' => 20000,
            'oauth' => ['scopes' => ['mcp_api', 'refresh_token', 'offline_access']],
        ],
        'sap_btp_mcp' => [
            'vendor' => 'SAP',
            'prefix' => 'sap',
            'auth_methods' => ['bearer', 'oauth'],
            'timeout_ms' => 30000,
            'oauth' => null,
        ],
        'shopify_mcp' => [
            'vendor' => 'Shopify',
            'prefix' => 'shopify',
            'auth_methods' => ['none', 'bearer'],
            'timeout_ms' => 20000,
            'oauth' => null,
        ],
        'bigcommerce_mcp' => [
            'vendor' => 'BigCommerce',
            'prefix' => 'bigcommerce',
            'auth_methods' => ['bearer'],
            'timeout_ms' => 20000,
            'oauth' => null,
        ],
    ];

    public function up(): void
    {
        foreach (self::SERVERS as $name => $server) {
            $exists = DB::connection('workflow')->table('integrations')
                ->where('name', $name)
                ->where('apps_id', 0)
                ->exists();

            if ($exists) {
                continue;
            }

            $metadata = [
                'vendor' => $server['vendor'],
                'transport' => 'http',
                'auth_methods' => $server['auth_methods'],
                'prefix' => $server['prefix'],
                'exclude' => [],
                'timeout_ms' => $server['timeout_ms'],
                'url_per_connection' => true,
            ];

            if ($server['oauth'] !== null) {
                $metadata['oauth'] = $server['oauth'];
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
            ->whereIn('name', array_keys(self::SERVERS))
            ->where('apps_id', 0)
            ->delete();
    }
};
