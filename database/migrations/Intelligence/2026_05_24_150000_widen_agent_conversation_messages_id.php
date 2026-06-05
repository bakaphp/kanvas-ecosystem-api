<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Widen `agent_conversation_messages.id` from VARCHAR(36) to VARCHAR(100).
 *
 * Original schema sized for raw UUIDs (KanvasConversationStore writes UUIDs).
 * The Hermes/OpenClaw transcript-ingest path (`BaseCollectSessionTranscriptsAction::buildMessageRow`)
 * composes ids as `<sessionId>:<runtimeMessageId>` — 36 + ':' + N which
 * overflows the original column immediately, producing either silent
 * truncation (and duplicate-key collisions on second-message inserts) or
 * a constraint exception, depending on MySQL strict mode.
 *
 * 100 chars covers <36-char UUID> + ':' + <63-char runtime id>, well within
 * the utf8mb4 indexable-PK limit. Existing rows (all 36-char UUIDs) are
 * preserved without modification.
 *
 * `conversation_id` and `agent_conversations.id` stay at 36 — conversation
 * ids remain raw UUIDs; only the per-message composite needs the wider
 * format.
 */
return new class () extends Migration {
    protected $connection = 'intelligence';

    public function up(): void
    {
        Schema::connection('intelligence')->table('agent_conversation_messages', function (Blueprint $table) {
            $table->string('id', 100)->change();
        });
    }

    public function down(): void
    {
        Schema::connection('intelligence')->table('agent_conversation_messages', function (Blueprint $table) {
            // Will fail with data-truncation if any row's id is already >36
            // chars — that's the right behavior. Down-migration without
            // first re-keying composite ids would corrupt the table.
            $table->string('id', 36)->change();
        });
    }
};
