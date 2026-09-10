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
 * Atlassian's remote MCP is OAuth 2.1, so a company connects it by pasting an access token obtained
 * through the OAuth app registered for this integration; `McpOAuthService` keeps it alive from there.
 * Verify `url` against Atlassian's current docs when wiring the OAuth app — a wrong value fails
 * `McpHandler::setup()` immediately with the vendor's own error rather than half-working.
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
                'kind' => 'mcp',
                'vendor' => 'atlassian',
                'url' => 'https://mcp.atlassian.com/v1/sse',
                'transport' => 'sse',
                'auth' => 'oauth',
                'prefix' => 'jira',
                'exclude' => [],
                'timeout_ms' => 20000,
                'oauth' => [
                    'token_url' => 'https://auth.atlassian.com/oauth/token',
                    'authorize_url' => 'https://auth.atlassian.com/authorize',
                ],
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
