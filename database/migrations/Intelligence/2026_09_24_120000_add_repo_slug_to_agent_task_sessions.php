<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which repository a session worked on.
 *
 * It is derivable from the plan's `input` JSON, but the question this answers — "what did we learn the
 * last few times we worked on THIS repo" — is asked on every dispatch, and answering it by scanning a
 * JSON column across every plan is the kind of query that is fine until it isn't.
 */
return new class () extends Migration {
    protected $connection = 'intelligence';

    public function up(): void
    {
        Schema::connection('intelligence')->table('agent_task_sessions', function (Blueprint $table): void {
            $table->string('repo_slug', 191)->nullable()->after('agent_machine_id');

            $table->index(['companies_id', 'repo_slug', 'completed_at'], 'agent_task_sessions_repo_history_idx');
        });
    }

    public function down(): void
    {
        Schema::connection('intelligence')->table('agent_task_sessions', function (Blueprint $table): void {
            $table->dropIndex('agent_task_sessions_repo_history_idx');
            $table->dropColumn('repo_slug');
        });
    }
};
