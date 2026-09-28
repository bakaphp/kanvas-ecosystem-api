<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add `is_public` to agent_conversation_messages so an internally-injected turn (e.g. a scheduled
 * agent-task wake instruction, which drives the agent but was never typed by a person) can be hidden
 * from the chat UI. Mirrors the Social `messages.is_public` convention (1 = visible, 0 = hidden).
 * Defaults to 1 so every existing row and every normal turn stays visible.
 */
return new class () extends Migration {
    protected $connection = 'intelligence';

    public function up(): void
    {
        Schema::connection('intelligence')->table('agent_conversation_messages', function (Blueprint $table) {
            $table->boolean('is_public')->default(1)->after('role');
        });
    }

    public function down(): void
    {
        Schema::connection('intelligence')->table('agent_conversation_messages', function (Blueprint $table) {
            $table->dropColumn('is_public');
        });
    }
};
