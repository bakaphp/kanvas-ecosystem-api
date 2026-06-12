<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    protected $connection = 'intelligence';

    /**
     * Direct agent attribution on usage snapshots. Until now the only link to an
     * agent was through agent_deployment_id -> agent_deployments.agent_id, which
     * excludes in-process backends (Neuron, Laravel) that have no deployment row.
     * A nullable agent_id lets every backend write into one table, queried by
     * agent_id alone.
     */
    public function up(): void
    {
        Schema::connection('intelligence')->table('agent_usage_snapshots', function (Blueprint $table) {
            $table->unsignedBigInteger('agent_id')->nullable()->after('companies_id');
            $table->index(
                ['agent_id', 'apps_id', 'companies_id', 'snapshot_date'],
                'idx_usage_snapshot_agent_date'
            );
        });

        DB::connection('intelligence')->statement(
            'UPDATE agent_usage_snapshots s
             JOIN agent_deployments d ON d.id = s.agent_deployment_id
             SET s.agent_id = d.agent_id
             WHERE s.agent_id IS NULL'
        );
    }

    public function down(): void
    {
        Schema::connection('intelligence')->table('agent_usage_snapshots', function (Blueprint $table) {
            $table->dropIndex('idx_usage_snapshot_agent_date');
            $table->dropColumn('agent_id');
        });
    }
};
