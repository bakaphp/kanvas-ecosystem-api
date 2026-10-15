<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per finished attempt of a delegated task, written when the child reaches a terminal state
 * and read by the job that resumes the parent. Its own table rather than a JSON list on the parent
 * because children finish concurrently: two read-modify-writes of one array lose one of them.
 *
 * `(child_task_id, attempt_id)` is unique so a retried delivery of the same attempt is a no-op, and
 * `delivered_at` is what the reconciler reads to find an outcome whose wake was never queued.
 */
return new class () extends Migration {
    protected $connection = 'intelligence';

    public function up(): void
    {
        Schema::connection('intelligence')->create('nervous_system_task_child_outcomes', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedInteger('apps_id');
            $table->unsignedBigInteger('companies_id');
            $table->unsignedBigInteger('parent_task_id');
            $table->unsignedBigInteger('child_task_id');
            $table->char('attempt_id', 36);
            $table->unsignedInteger('revision')->nullable();
            $table->string('status', 32);
            $table->text('summary')->nullable();
            // The coding session that produced this outcome, for tracing only — the dedupe key is the attempt.
            $table->unsignedBigInteger('agent_task_session_id')->nullable();
            $table->dateTime('delivered_at')->nullable();
            $table->dateTime('created_at');

            $table->unique(['child_task_id', 'attempt_id'], 'child_attempt_unique');
            $table->index(['parent_task_id', 'created_at'], 'parent_created_idx');
            $table->index(['delivered_at', 'created_at'], 'undelivered_idx');
        });
    }

    public function down(): void
    {
        Schema::connection('intelligence')->dropIfExists('nervous_system_task_child_outcomes');
    }
};
