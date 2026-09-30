<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `limit_extensions` is how many extra time/cost blocks a human has granted; the limits scale by it.
 *
 * `turn_offset` is how many closed turns (opencode `idle` messages) belong to work already accounted
 * for. A resumed session keeps its whole history, including the `aborted` idle the pause produced, and
 * without an offset that old turn is read as the current one — the run is finalized or failed on the
 * first tick after it was told to continue.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('intelligence')->table(
            'agent_task_sessions',
            function (Blueprint $table): void {
                $table->unsignedTinyInteger('limit_extensions')->default(0)->after('estimated_cost');
                $table->unsignedInteger('turn_offset')->default(0)->after('last_cursor');
            }
        );
    }

    public function down(): void
    {
        Schema::connection('intelligence')->table(
            'agent_task_sessions',
            function (Blueprint $table): void {
                $table->dropColumn(['limit_extensions', 'turn_offset']);
            }
        );
    }
};
