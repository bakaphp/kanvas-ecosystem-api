<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Schema only. Existing rows get their kind from `agents:backfill-conversation-kind`, run directly
 * after this migrates; until it has run, old summaries and tool rounds show in the chat again.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('intelligence')->table('agent_conversation_messages', function (Blueprint $table): void {
            $table->string('kind', 20)->nullable()->after('role');
            $table->index(['conversation_id', 'kind'], 'conversation_kind_index');
        });
    }

    public function down(): void
    {
        Schema::connection('intelligence')->table('agent_conversation_messages', function (Blueprint $table): void {
            $table->dropIndex('conversation_kind_index');
            $table->dropColumn('kind');
        });
    }
};
