<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    protected $connection = 'intelligence';

    /**
     * Materialize the day's cost rollup onto the swarm cycle row so the
     * dashboard's COST TODAY card doesn't have to re-aggregate across
     * member deployments on every request. RecordSwarmDailyCycleAction
     * writes this when it generates the cycle overnight.
     */
    public function up(): void
    {
        Schema::table('agent_swarm_daily_cycles', function (Blueprint $table) {
            $table->decimal('cost_usd_today', 12, 6)->nullable()->after('proactive_actions_count');
        });
    }

    public function down(): void
    {
        Schema::table('agent_swarm_daily_cycles', function (Blueprint $table) {
            $table->dropColumn('cost_usd_today');
        });
    }
};
