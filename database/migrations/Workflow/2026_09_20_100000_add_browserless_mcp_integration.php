<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\Connectors\Mcp\Handlers\McpHandler;
use Kanvas\Workflow\Enums\IntegrationTypeEnum;

/**
 * Browserless's hosted server, on the Browserbase pattern: the key rides in the query string
 * (`?token=`), so the row carries `auth_query_param` and the admin pastes the token alone.
 *
 * Probed 2026-09-20 against `browserless-mcp` 1.30.0: no key at all is a 401, but a wrong one still
 * completes `initialize` and lists all 14 tools — it is only checked when a browser actually runs.
 * `tools/list` needs the `Mcp-Session-Id` from the handshake (our transport carries it); without it the
 * server answers with an empty list rather than an error.
 *
 * 130s, above every other row, because their session timeout is 120s: `browserless_agent` holds a session
 * open across calls and their own log ends it with `browserless_killed/session_timeout · 120001ms`. Giving
 * up first meant abandoning a result the account had already been billed 15 units for.
 *
 * No `async_jobs`: unlike Browser Use, nothing here hands work to a vendor-side agent. `browserless_agent`
 * is a loop OUR model drives — snapshot, decide, act — so every call returns at once and the cost lands
 * on our turn instead. A long portal flow is therefore bounded by the per-turn MCP call budget, not by a
 * background job.
 */
return new class () extends Migration {
    protected $connection = 'workflow';

    public function up(): void
    {
        $exists = DB::connection('workflow')->table('integrations')
            ->where('name', 'browserless_mcp')
            ->where('apps_id', 0)
            ->exists();

        if ($exists) {
            return;
        }

        DB::connection('workflow')->table('integrations')->insert([
            'uuid' => (string) Str::uuid(),
            'name' => 'browserless_mcp',
            'handler' => McpHandler::class,
            'type' => IntegrationTypeEnum::MCP->value,
            'apps_id' => 0,
            'config' => json_encode([]),
            'metadata' => json_encode([
                'vendor' => 'Browserless',
                'transport' => 'http',
                'url' => 'https://mcp.browserless.io/mcp',
                'auth_methods' => ['bearer'],
                'auth_query_param' => 'token',
                'prefix' => 'browserless',
                'exclude' => [],
                'timeout_ms' => 130000,
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
            ->where('name', 'browserless_mcp')
            ->where('apps_id', 0)
            ->delete();
    }
};
