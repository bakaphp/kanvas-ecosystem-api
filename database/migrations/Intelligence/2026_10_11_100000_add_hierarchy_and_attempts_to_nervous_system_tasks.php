<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A task that delegates — a coding job, a sub-task — points its child back at itself, so the child's
// outcome knows which task to resume. The attempt id is the identity of one run of a task: minted when
// it enters in_progress, fresh again when it is reopened, and what an outcome row is keyed on, so a
// retried delivery of the same run collides instead of resuming the parent twice.
return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('intelligence')->table('nervous_system_tasks', function (Blueprint $table) {
            $table->unsignedBigInteger('parent_task_id')->nullable()->after('plan_id');
            $table->char('current_attempt_id', 36)->nullable()->after('wake_at');
            // The task's own objective revision, and the parent's at the moment this child was linked —
            // an outcome started under an older objective is recorded but cannot satisfy the new one.
            $table->unsignedInteger('revision')->default(1)->after('current_attempt_id');
            $table->unsignedInteger('parent_revision')->nullable()->after('revision');
            $table->index(['parent_task_id', 'status'], 'parent_task_status_idx');
        });
    }

    public function down(): void
    {
        Schema::connection('intelligence')->table('nervous_system_tasks', function (Blueprint $table) {
            $table->dropIndex('parent_task_status_idx');
            $table->dropColumn(['parent_task_id', 'current_attempt_id', 'revision', 'parent_revision']);
        });
    }
};
