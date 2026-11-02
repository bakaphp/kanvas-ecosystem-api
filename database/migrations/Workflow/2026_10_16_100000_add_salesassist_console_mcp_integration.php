<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\Connectors\Mcp\Handlers\McpHandler;
use Kanvas\Workflow\Enums\IntegrationTypeEnum;

/**
 * The SalesAssist Console's own server (jitsubai `console/api`, `POST <CONSOLE_API_URL>/mcp`): nine read-only
 * telemetry tools over the events the extension and the mobile app send.
 *
 * The address is per connection because it is a deployment variable (`CONSOLE_API_URL`) that lives in Vercel,
 * not in this repo. One address serves both environments — the key's prefix picks which one it reads
 * (`trr_live_` prod, `trr_test_` staging) — so an admin connects with the address and a read key.
 *
 * Bearer only, no OAuth: the server answers 401 with no `resource_metadata` and publishes no `.well-known`,
 * so there is nothing to discover. It is stateless (no `Mcp-Session-Id`, `GET` answers 405) and replies in
 * plain JSON, which the guarded transport already handles. Every tool answers at once, so no `async_jobs`.
 *
 * `sa_console` keeps the longest prefixed name (`session_timeline`) well under the 64 character limit and
 * stops the generic names (`get_trace`, `query_events`) from colliding with another server's.
 */
return new class () extends Migration {
    protected $connection = 'workflow';

    public function up(): void
    {
        $exists = DB::connection('workflow')->table('integrations')
            ->where('name', 'salesassist_console_mcp')
            ->where('apps_id', 0)
            ->exists();

        if ($exists) {
            return;
        }

        DB::connection('workflow')->table('integrations')->insert([
            'uuid' => (string) Str::uuid(),
            'name' => 'salesassist_console_mcp',
            'handler' => McpHandler::class,
            'type' => IntegrationTypeEnum::MCP->value,
            'apps_id' => 0,
            'config' => json_encode([]),
            'metadata' => json_encode([
                'vendor' => 'SalesAssist Console',
                'transport' => 'http',
                'auth_methods' => ['bearer'],
                'prefix' => 'sa_console',
                'exclude' => [],
                'timeout_ms' => 30000,
                'url_per_connection' => true,
            ]),
            'is_deleted' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::connection('workflow')->table('integrations')
            ->where('name', 'salesassist_console_mcp')
            ->where('apps_id', 0)
            ->delete();
    }
};
