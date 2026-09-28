<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MCP connections are per agent (plan §18), so the descriptor snapshot is too: what a server exposes
 * depends on whose credential asked.
 *
 * Existing rows are deleted, not migrated. They are a cache, and every one was taken with a
 * company-level credential that no longer exists; an agent's snapshot is rebuilt when it connects.
 */
return new class () extends Migration {
    protected $connection = 'intelligence';

    public function up(): void
    {
        DB::connection('intelligence')->table('nervous_system_mcp_tool_snapshots')->delete();

        Schema::connection('intelligence')->table('nervous_system_mcp_tool_snapshots', function (Blueprint $table): void {
            $table->dropUnique('mcp_tool_snapshots_tenant_integration_unique');
            $table->unsignedBigInteger('agents_id')->after('companies_id');
            $table->unique(
                ['apps_id', 'companies_id', 'agents_id', 'integrations_id'],
                'mcp_tool_snapshots_agent_integration_unique'
            );
            $table->index('agents_id', 'mcp_tool_snapshots_agents_id_idx');
        });
    }

    public function down(): void
    {
        DB::connection('intelligence')->table('nervous_system_mcp_tool_snapshots')->delete();

        Schema::connection('intelligence')->table('nervous_system_mcp_tool_snapshots', function (Blueprint $table): void {
            $table->dropUnique('mcp_tool_snapshots_agent_integration_unique');
            $table->dropIndex('mcp_tool_snapshots_agents_id_idx');
            $table->dropColumn('agents_id');
            $table->unique(
                ['apps_id', 'companies_id', 'integrations_id'],
                'mcp_tool_snapshots_tenant_integration_unique'
            );
        });
    }
};
