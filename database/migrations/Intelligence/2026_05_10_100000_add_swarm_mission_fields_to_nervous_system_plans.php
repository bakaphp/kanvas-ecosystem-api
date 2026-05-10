<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mission card on the dashboard's swarm tile reads:
 *   - title + description (existing on plan)
 *   - completion_pct (existing on plan)
 *   - impact_summary  ("+$1.4M expected pipeline")
 *   - status_pill     ("AHEAD OF TARGET" / "ON TRACK" / etc.)
 *
 * The swarm-mission link itself uses two columns:
 *   - swarm_id           — which swarm this plan is the mission for (nullable)
 *   - is_swarm_mission   — boolean flag, only one active=true per swarm
 *                          (enforced in CreatePlanAction / UpdatePlanAction)
 *
 * Done missions retain is_swarm_mission=true so swarm history stays
 * intact; activeMission() relation filters by non-terminal status, so
 * completed missions automatically drop out of the swarm card UI and
 * the operator must explicitly set a new one.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('intelligence')->table('nervous_system_plans', function (Blueprint $table) {
            $table->unsignedBigInteger('swarm_id')->nullable()->after('agent_id');
            $table->boolean('is_swarm_mission')->default(false)->after('swarm_id');
            $table->string('impact_summary', 500)->nullable()->after('output');
            $table->string('status_pill', 64)->nullable()->after('impact_summary');

            $table->index(['swarm_id', 'is_swarm_mission'], 'swarm_mission_idx');
        });
    }

    public function down(): void
    {
        Schema::connection('intelligence')->table('nervous_system_plans', function (Blueprint $table) {
            $table->dropIndex('swarm_mission_idx');
            $table->dropColumn(['swarm_id', 'is_swarm_mission', 'impact_summary', 'status_pill']);
        });
    }
};
