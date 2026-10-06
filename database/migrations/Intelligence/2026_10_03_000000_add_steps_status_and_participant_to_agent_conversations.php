<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Ai\Migrations\AiMigration;

/**
 * Laravel AI 1.x stores an assistant turn as `steps` — one entry per model round trip, each tool result
 * on the call that produced it — with a `status`, and keys a conversation to a polymorphic participant
 * (Users, People or Agent). Everything is added nullable and `tool_calls`/`tool_results` are kept so
 * every writer can dual-write while `agents:backfill-conversation-steps` rewrites the existing rows;
 * the old columns drop in a later migration once no row is missing `steps`.
 */
return new class () extends AiMigration {
    public function getConnection(): ?string
    {
        return 'intelligence';
    }

    public function up(): void
    {
        Schema::connection('intelligence')->table('agent_conversations', function (Blueprint $table) {
            $table->string('participant_type')->nullable()->after('user_id');
            $table->unsignedBigInteger('participant_id')->nullable()->after('participant_type');

            $table->index(['participant_type', 'participant_id', 'updated_at'], 'participant_updated_at_index');
        });

        Schema::connection('intelligence')->table('agent_conversation_messages', function (Blueprint $table) {
            $table->string('participant_type')->nullable()->after('user_id');
            $table->unsignedBigInteger('participant_id')->nullable()->after('participant_type');
            $table->longText('steps')->nullable()->after('tool_results');
            $table->string('status', 25)->default('completed')->after('steps');

            $table->index(['participant_type', 'participant_id', 'agent'], 'participant_index');
        });
    }

    public function down(): void
    {
        Schema::connection('intelligence')->table('agent_conversation_messages', function (Blueprint $table) {
            $table->dropIndex('participant_index');
            $table->dropColumn(['participant_type', 'participant_id', 'steps', 'status']);
        });

        Schema::connection('intelligence')->table('agent_conversations', function (Blueprint $table) {
            $table->dropIndex('participant_updated_at_index');
            $table->dropColumn(['participant_type', 'participant_id']);
        });
    }
};
