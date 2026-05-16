<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Counter cache for "how many agents are actively using this skill" — used by
 * the dashboard's recent-skill-updates feed. Replaces a per-request COUNT
 * subquery with a stored column maintained by AgentSkillObserver on grant /
 * revoke / soft-delete transitions.
 *
 * Backfill counts grants that are currently is_active=1, is_deleted=0, and
 * not expired. Going forward the observer keeps the column in sync.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('intelligence')->table('nervous_system_skills', function (Blueprint $table) {
            $table->unsignedInteger('agents_using_count')->default(0)->after('is_active');
        });

        DB::connection('intelligence')->statement('
            UPDATE nervous_system_skills s
            SET agents_using_count = (
                SELECT COUNT(*) FROM nervous_system_agent_skills g
                WHERE g.skill_id = s.id
                  AND g.is_active = 1
                  AND g.is_deleted = 0
                  AND (g.expires_at IS NULL OR g.expires_at > NOW())
            )
        ');
    }

    public function down(): void
    {
        Schema::connection('intelligence')->table('nervous_system_skills', function (Blueprint $table) {
            $table->dropColumn('agents_using_count');
        });
    }
};
