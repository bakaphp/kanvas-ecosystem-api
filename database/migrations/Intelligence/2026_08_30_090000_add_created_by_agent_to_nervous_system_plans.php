<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which agent CREATED the plan — normally the PM that decomposed the work.
 *
 * `agent_id` cannot answer this: it is the current assignee. `assign_nervous_system_plan` overwrites
 * it with whoever the work is handed to, and assigning to a human sets it to NULL outright — so the
 * creator is erased by the first delegation, which is exactly when you start needing to know it.
 *
 * `users_id` holds the creator today, but as a USER id, and that is ambiguous by construction: an
 * agent's user is frequently a real person's account too (the PM of project 1834 writes as user 667,
 * which is also a human's login), so "an agent made this, and it was that one" is not recoverable
 * from it. `Agent::fromUser()` only guesses — user 2 backs 28 different agents.
 *
 * Recording it explicitly is what lets a finished plan notify the agent that asked for it, rather
 * than inferring a PM from the project — which is wrong for a plan with no project, and stale when
 * the project's PM has since changed.
 *
 * Nullable: plans created by a human, a cron, a workflow or a swarm mission have no creating agent.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('intelligence')->table('nervous_system_plans', function (Blueprint $table): void {
            $table->unsignedBigInteger('created_by_agent_id')->nullable()->after('agent_id');
            $table->index(['created_by_agent_id'], 'ns_plans_created_by_agent_idx');
        });
    }

    public function down(): void
    {
        Schema::connection('intelligence')->table('nervous_system_plans', function (Blueprint $table): void {
            $table->dropIndex('ns_plans_created_by_agent_idx');
            $table->dropColumn('created_by_agent_id');
        });
    }
};
