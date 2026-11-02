<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A retry is a new session row on the same task; this is what makes it a retry rather than a
 * continuation (`continues_session_id` carries a branch over, a retry starts clean). Set means
 * "already the second try" — the only guard against retrying forever.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('intelligence')->table(
            'agent_task_sessions',
            function (Blueprint $table): void {
                $table->unsignedBigInteger('retry_of_session_id')->nullable()->after('continues_session_id');
            }
        );
    }

    public function down(): void
    {
        Schema::connection('intelligence')->table(
            'agent_task_sessions',
            function (Blueprint $table): void {
                $table->dropColumn('retry_of_session_id');
            }
        );
    }
};
