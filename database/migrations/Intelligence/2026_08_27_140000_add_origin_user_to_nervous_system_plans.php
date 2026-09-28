<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The person who asked, as distinct from the plan's owner.
 *
 * `users_id` is who the plan belongs to, and on agent-created work that is another agent — a PM opens
 * a plan and owns it. So every route to a human ran through a bot: plan owner → agent, project owner
 * → agent, and the @mention that would have notified is suppressed for agent users on purpose.
 *
 * Worse, the suppression cannot be reasoned around, because an agent may share a human's account:
 * ten agents currently sit on user 2, so `Agent::fromUser()` reports that human as an agent and every
 * mention of them is dropped. Asking "is this owner a person" gives the wrong answer on real data.
 *
 * Recording who was in the conversation sidesteps all of it. It is a fact about the request, captured
 * once at creation, and it is also the better recipient: the person who asked is the one who wants to
 * hear that it is finished.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('intelligence')->table('nervous_system_plans', function (Blueprint $table): void {
            $table->unsignedBigInteger('origin_users_id')->nullable()->after('origin_channel_id');
            $table->index(['origin_users_id'], 'ns_plans_origin_user_idx');
        });
    }

    public function down(): void
    {
        Schema::connection('intelligence')->table('nervous_system_plans', function (Blueprint $table): void {
            $table->dropIndex('ns_plans_origin_user_idx');
            $table->dropColumn('origin_users_id');
        });
    }
};
