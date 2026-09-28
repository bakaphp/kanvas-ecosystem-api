<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\Connectors\Mcp\Handlers\McpHandler;
use Kanvas\Workflow\Enums\IntegrationTypeEnum;

/**
 * Tavily's hosted search/research server, connected with OAuth — it registers its own client (PKCE S256),
 * so an admin just clicks Connect.
 *
 * Tavily also accepts the API key in the address (`?tavilyApiKey=...`), which is deliberately NOT offered
 * here: that path needs `url_per_connection`, which would make every admin paste a URL even on the
 * one-click OAuth path.
 *
 * Note the overlap: Kanvas already ships native Tavily tools (search / extract / crawl / map) that run on
 * one app-wide API key. This row is the per-agent alternative — grant one or the other, not both.
 */
return new class () extends Migration {
    protected $connection = 'workflow';

    public function up(): void
    {
        $exists = DB::connection('workflow')->table('integrations')
            ->where('name', 'tavily_mcp')
            ->where('apps_id', 0)
            ->exists();

        if ($exists) {
            return;
        }

        DB::connection('workflow')->table('integrations')->insert([
            'uuid' => (string) Str::uuid(),
            'name' => 'tavily_mcp',
            'handler' => McpHandler::class,
            'type' => IntegrationTypeEnum::MCP->value,
            'apps_id' => 0,
            'config' => json_encode([]),
            'metadata' => json_encode([
                'vendor' => 'Tavily',
                'url' => 'https://mcp.tavily.com/mcp',
                'transport' => 'http',
                'auth_methods' => ['oauth'],
                'prefix' => 'tavily',
                'exclude' => [],
                'timeout_ms' => 30000,
                'oauth' => [
                    'scopes' => ['openid', 'offline_access'],
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
            ->where('name', 'tavily_mcp')
            ->where('apps_id', 0)
            ->delete();
    }
};
