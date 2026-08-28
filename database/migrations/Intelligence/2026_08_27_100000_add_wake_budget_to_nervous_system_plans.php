<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The stopping condition for the continuation loop.
 *
 * A plan that cannot finish re-enters through WakeAgentForPlanJob forever: the agent looks, finds
 * nothing it can move, says so, and the next task-status change wakes it again. Counting re-entries
 * is what turns that from an unbounded spend into a plan that gives up and says why.
 *
 * `max_wakes` is nullable so a plan can opt out of the cap entirely; the resolved default lives in
 * app config rather than here, so it can be tuned without a migration.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('intelligence')->table('nervous_system_plans', function (Blueprint $table): void {
            $table->unsignedInteger('wake_count')->default(0)->after('completion_pct');
            $table->unsignedInteger('max_wakes')->nullable()->after('wake_count');
        });
    }

    public function down(): void
    {
        Schema::connection('intelligence')->table('nervous_system_plans', function (Blueprint $table): void {
            $table->dropColumn(['wake_count', 'max_wakes']);
        });
    }
};
