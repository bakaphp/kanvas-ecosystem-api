<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\Connectors\Mcp\Handlers\McpHandler;
use Kanvas\Workflow\Enums\IntegrationTypeEnum;

/**
 * KIAS task management for administrative agents. Procurement tools are excluded from this grant;
 * the server's organization-bound token remains the authority for access to business data.
 */
return new class () extends Migration {
    protected $connection = 'workflow';

    public function up(): void
    {
        $exists = DB::connection('workflow')->table('integrations')
            ->where('name', 'kias_mcp')
            ->where('apps_id', 0)
            ->exists();

        if ($exists) {
            return;
        }

        DB::connection('workflow')->table('integrations')->insert([
            'uuid' => (string) Str::uuid(),
            'name' => 'kias_mcp',
            'handler' => McpHandler::class,
            'type' => IntegrationTypeEnum::MCP->value,
            'apps_id' => 0,
            'config' => json_encode([]),
            'metadata' => json_encode([
                'vendor' => 'KIAS',
                'transport' => 'http',
                'auth_methods' => ['bearer'],
                'prefix' => 'kias',
                'exclude' => [
                    'upsert_procurement_process',
                    'update_procurement_process',
                    'sync_process_requirements',
                    'update_process_requirement',
                    'add_process_document',
                    'update_process_document',
                    'log_process_activity',
                ],
                'timeout_ms' => 20000,
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
            ->where('name', 'kias_mcp')
            ->where('type', IntegrationTypeEnum::MCP->value)
            ->where('apps_id', 0)
            ->delete();
    }
};
