<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\Connectors\Mcp\Handlers\McpHandler;

/**
 * First curated MCP server.
 *
 * `metadata` is the platform-side descriptor (never a company form field); `config` is the company
 * form — one token, the same shape every MCP row uses.
 *
 * A key connection uses an Atlassian service-account API key, sent as `Bearer`. An org admin must
 * enable "Allow API token authentication" under Rovo MCP server → Authentication first. OAuth is added
 * by 2026_09_11_100002.
 *
 * `v2/mcp` (Streamable HTTP), not `v1/sse`: GuardedHttpMcpTransport POSTs JSON-RPC straight to `url`,
 * and the legacy SSE endpoint only accepts POSTs to the per-session URL its GET stream hands out — a
 * direct POST answers 404 "Missing sessionId parameter".
 */
return new class () extends Migration {
    protected $connection = 'workflow';

    private const string NAME = 'atlassian_mcp';

    public function up(): void
    {
        $exists = DB::connection('workflow')->table('integrations')
            ->where('name', self::NAME)
            ->where('apps_id', 0)
            ->exists();

        if ($exists) {
            return;
        }

        DB::connection('workflow')->table('integrations')->insert([
            'uuid' => (string) Str::uuid(),
            'name' => self::NAME,
            'handler' => McpHandler::class,
            'apps_id' => 0,
            'config' => json_encode([
                'token' => ['type' => 'text', 'required' => true],
            ]),
            'metadata' => json_encode([
                'vendor' => 'atlassian',
                'url' => 'https://mcp.atlassian.com/v2/mcp',
                'transport' => 'http',
                'auth_methods' => ['bearer'],
                'prefix' => 'jira',
                'exclude' => [],
                'timeout_ms' => 20000,
            ]),
            'is_deleted' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::connection('workflow')->table('integrations')
            ->where('name', self::NAME)
            ->where('apps_id', 0)
            ->delete();
    }
};
