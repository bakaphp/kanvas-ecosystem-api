<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The chat conversation a live coding session answers to. Set only for a coding chat (an agent with
 * `CODING_CHAT_MODE`), where every message in that conversation is the next prompt to the same opencode
 * session in the factory checkout, and every finished turn is posted back there.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('intelligence')->table(
            'agent_task_sessions',
            function (Blueprint $table): void {
                $table->unsignedBigInteger('chat_session_id')->nullable()->after('task_id')->index();
            }
        );
    }

    public function down(): void
    {
        Schema::connection('intelligence')->table(
            'agent_task_sessions',
            function (Blueprint $table): void {
                $table->dropIndex(['chat_session_id']);
                $table->dropColumn('chat_session_id');
            }
        );
    }
};
