<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether the job is expected to change files. Off for a run / test / screenshot / inspect job, so
 * an empty diff on completion is the result rather than the "model answered in prose" failure the
 * finalizer otherwise records and retries.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('intelligence')->table(
            'agent_task_sessions',
            function (Blueprint $table): void {
                $table->boolean('expects_changes')->default(true)->after('retry_of_session_id');
            }
        );
    }

    public function down(): void
    {
        Schema::connection('intelligence')->table(
            'agent_task_sessions',
            function (Blueprint $table): void {
                $table->dropColumn('expects_changes');
            }
        );
    }
};
