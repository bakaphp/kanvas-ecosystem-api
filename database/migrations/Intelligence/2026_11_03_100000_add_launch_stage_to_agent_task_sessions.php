<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which step of the launch a session is in, written by the launch job as it goes and cleared once the
 * prompt is sent. A live row with a stage and no endpoint is still launching; the sweeper gives each
 * stage its own patience, and the PM reads it instead of guessing "stuck" from a quiet heartbeat.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('intelligence')->table(
            'agent_task_sessions',
            function (Blueprint $table): void {
                $table->string('launch_stage', 32)->nullable()->after('status');
            }
        );
    }

    public function down(): void
    {
        Schema::connection('intelligence')->table(
            'agent_task_sessions',
            function (Blueprint $table): void {
                $table->dropColumn('launch_stage');
            }
        );
    }
};
