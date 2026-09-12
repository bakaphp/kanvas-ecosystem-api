<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\Connectors\Mcp\Handlers\McpHandler;

/**
 * GitHub's remote MCP server, reached with a personal access token.
 *
 * Distinct from the existing `github` row (GithubHandler, REST): same provider, different flow, so a
 * separate row — one row per handler. A PAT is a plain bearer with no refresh leg, which makes this the
 * row that exercises the whole MCP path end to end without an OAuth app. The URL is the one the
 * ClaudeAgent connector already uses against the same server.
 */
return new class () extends Migration {
    protected $connection = 'workflow';

    private const string NAME = 'github_mcp';

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
                'vendor' => 'github',
                'url' => 'https://api.githubcopilot.com/mcp/',
                'transport' => 'http',
                'auth_methods' => ['bearer'],
                'prefix' => 'github',
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
