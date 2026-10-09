<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\Connectors\Mcp\Handlers\McpHandler;
use Kanvas\Workflow\Enums\IntegrationTypeEnum;

/**
 * Mercury's hosted server — read-only banking data (accounts, transactions, statements).
 *
 * Probed 2026-10-08: the 401's `WWW-Authenticate` names no `resource_metadata` and the path-aware
 * well-known 404s, but the origin-level protected-resource document points at `mcp.mercury.com` itself,
 * whose `/register` accepted `{app.url}/v1/oauth/callback` as a public client — one click, no hand-made
 * client. PKCE S256 only. Mercury rejects a `client_name` starting with "Mercury" (ours is "Kanvas").
 *
 * The resource also advertises write scopes (`transfers:request`, `transactions:update`, ...); only `read`
 * is requested on purpose — money movement is not something an agent gets by connecting.
 */
return new class () extends Migration {
    protected $connection = 'workflow';

    public function up(): void
    {
        $exists = DB::connection('workflow')->table('integrations')
            ->where('name', 'mercury_mcp')
            ->where('apps_id', 0)
            ->exists();

        if ($exists) {
            return;
        }

        DB::connection('workflow')->table('integrations')->insert([
            'uuid' => (string) Str::uuid(),
            'name' => 'mercury_mcp',
            'handler' => McpHandler::class,
            'type' => IntegrationTypeEnum::MCP->value,
            'apps_id' => 0,
            'config' => json_encode([]),
            'metadata' => json_encode([
                'vendor' => 'Mercury',
                'transport' => 'http',
                'url' => 'https://mcp.mercury.com/mcp',
                'auth_methods' => ['oauth'],
                'oauth' => [
                    'scopes' => ['read', 'offline_access'],
                ],
                'prefix' => 'mercury',
                'exclude' => [],
                'timeout_ms' => 20000,
                'url_per_connection' => false,
            ]),
            'is_deleted' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::connection('workflow')->table('integrations')
            ->where('name', 'mercury_mcp')
            ->where('apps_id', 0)
            ->delete();
    }
};
