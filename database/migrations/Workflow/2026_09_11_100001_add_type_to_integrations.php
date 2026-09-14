<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Kanvas\Connectors\Mcp\Handlers\McpHandler;
use Kanvas\Workflow\Enums\IntegrationTypeEnum;

/**
 * Every existing row is connected through the pasted-credentials form, so KEY is both the default and
 * the backfill; only the MCP servers are re-typed. No row is connected through a consent screen yet —
 * OAUTH is for the first one that is.
 */
return new class () extends Migration {
    protected $connection = 'workflow';

    public function up(): void
    {
        Schema::connection('workflow')->table('integrations', function (Blueprint $table): void {
            $table->string('type', 20)->default(IntegrationTypeEnum::KEY->value)->after('handler');
            $table->index(['type', 'apps_id'], 'integrations_type_apps_index');
        });

        DB::connection('workflow')->table('integrations')
            ->where('handler', McpHandler::class)
            ->update(['type' => IntegrationTypeEnum::MCP->value]);
    }

    public function down(): void
    {
        Schema::connection('workflow')->table('integrations', function (Blueprint $table): void {
            $table->dropIndex('integrations_type_apps_index');
            $table->dropColumn('type');
        });
    }
};
