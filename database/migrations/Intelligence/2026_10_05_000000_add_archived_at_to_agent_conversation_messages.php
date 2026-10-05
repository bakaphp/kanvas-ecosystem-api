<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('intelligence')->table('agent_conversation_messages', function (Blueprint $table): void {
            $table->dateTime('archived_at')->nullable()->after('status');
            // The active window's order. A kept turn re-activated after a summary must follow the summary,
            // and neither created_at (seconds) nor the uuid7 id (construction time) can say so.
            $table->unsignedBigInteger('sequence')->nullable()->after('archived_at');
            $table->index(['conversation_id', 'archived_at', 'sequence'], 'conversation_window_index');
        });
    }

    public function down(): void
    {
        Schema::connection('intelligence')->table('agent_conversation_messages', function (Blueprint $table): void {
            $table->dropIndex('conversation_window_index');
            $table->dropColumn(['archived_at', 'sequence']);
        });
    }
};
