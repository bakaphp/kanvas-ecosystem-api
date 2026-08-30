<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two columns, both about reaching the person who asked.
 *
 * `origin_session_id` — the CONVERSATION, not just the channel. A chat thread is session-scoped, so a
 * report posted to the channel without the session renders outside it and is never seen; the room is
 * not enough to be read in.
 *
 * `blocked_needs` — WHY it is blocked, so only the blocks a person can act on interrupt them. "Waiting
 * on your approval" belongs in their conversation; "I lack a tool for this" is an operator's problem
 * and belongs on the board. Without it the only choices are every block or none.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('intelligence')->table('nervous_system_plans', function (Blueprint $table): void {
            $table->unsignedBigInteger('origin_session_id')->nullable()->after('origin_channel_id');
            $table->string('blocked_needs', 32)->nullable()->after('error_message');
            $table->index(['origin_session_id'], 'ns_plans_origin_session_idx');
        });
    }

    public function down(): void
    {
        Schema::connection('intelligence')->table('nervous_system_plans', function (Blueprint $table): void {
            $table->dropIndex('ns_plans_origin_session_idx');
            $table->dropColumn(['origin_session_id', 'blocked_needs']);
        });
    }
};
