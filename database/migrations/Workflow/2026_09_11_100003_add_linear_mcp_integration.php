<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\Connectors\Mcp\Handlers\McpHandler;
use Kanvas\Workflow\Enums\IntegrationTypeEnum;

/**
 * Linear's remote MCP server, connected with OAuth only.
 *
 * Its authorization server is the MCP host itself and follows the MCP authorization spec exactly —
 * RFC 9728 metadata, dynamic registration, public clients, S256 — so no vendor quirks are configured.
 * Checked against the live metadata on 2026-09-11.
 */
return new class () extends Migration {
    protected $connection = 'workflow';

    private const string NAME = 'linear_mcp';

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
                'vendor' => 'linear',
                'url' => 'https://mcp.linear.app/mcp',
                'transport' => 'http',
                'auth_methods' => ['oauth'],
                'prefix' => 'linear',
                'exclude' => [],
                'timeout_ms' => 20000,
                'oauth' => [
                    'scopes' => [
                        'read',
                        'write',
                    ],
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
