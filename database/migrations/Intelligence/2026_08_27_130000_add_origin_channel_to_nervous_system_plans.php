<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where the plan was asked for, so its outcome can be reported back there.
 *
 * A plan gets its own Activities channel, and that is where the agent has been posting. It is the
 * right home for the plan's own history and the wrong place to tell anyone anything: it is per-plan,
 * created after the fact, and nobody is subscribed to it. The person who asked was in a conversation
 * somewhere else — a chat, a Slack channel — and never heard back.
 *
 * `socialChannels` cannot carry this. It is a HasMany over channels the plan OWNS (entity_id points
 * back at the plan), so pointing it at an existing conversation would rewrite that conversation's
 * owner. The origin has to be recorded on the plan instead.
 *
 * Nullable because most plans have no conversation behind them — a cron, a workflow, a swarm mission
 * — and those should keep reporting only to the Activities channel.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('intelligence')->table('nervous_system_plans', function (Blueprint $table): void {
            $table->unsignedBigInteger('origin_channel_id')->nullable()->after('entity_id');
            $table->index(['origin_channel_id'], 'ns_plans_origin_channel_idx');
        });
    }

    public function down(): void
    {
        Schema::connection('intelligence')->table('nervous_system_plans', function (Blueprint $table): void {
            $table->dropIndex('ns_plans_origin_channel_idx');
            $table->dropColumn('origin_channel_id');
        });
    }
};
