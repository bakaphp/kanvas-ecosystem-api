<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\Connectors\Mcp\Handlers\McpHandler;
use Kanvas\Workflow\Enums\IntegrationTypeEnum;

/**
 * n8n over MCP, on each company's own n8n instance — so no URL here: the admin gives it per connection
 * (`url_per_connection`). Either n8n's instance-level MCP server (Settings → Instance-level MCP), which
 * takes an access token or OAuth, or one workflow's MCP Server Trigger URL, which takes a bearer token.
 * Both speak streamable HTTP.
 */
return new class () extends Migration {
    protected $connection = 'workflow';

    private const string NAME = 'n8n_mcp';

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
            'type' => IntegrationTypeEnum::MCP->value,
            'apps_id' => 0,
            'config' => json_encode([]),
            'metadata' => json_encode([
                'vendor' => 'n8n',
                'url_per_connection' => true,
                'transport' => 'http',
                'auth_methods' => [
                    'bearer',
                    'oauth',
                ],
                'prefix' => 'n8n',
                'exclude' => [],
                'timeout_ms' => 30000,
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
