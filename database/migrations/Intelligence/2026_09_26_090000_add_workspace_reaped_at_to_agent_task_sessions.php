<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marks a session whose checkout has been removed from its machine.
 *
 * Without it the reaper re-selects the same rows on every run and re-issues `rm -rf` for paths it
 * deleted days ago — forever. The path itself is kept rather than nulled: `kanvas:coding:sessions`
 * reads a null `workspace_path` as "attach mode", so clearing it would relabel finished launch-mode
 * runs as something they never were.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('intelligence')->table(
            'agent_task_sessions',
            function (Blueprint $table): void {
                $table->timestamp('workspace_reaped_at')->nullable()->after('workspace_path');
                $table->index(
                    ['agent_machine_id', 'workspace_reaped_at'],
                    'agent_task_sessions_reap_idx'
                );
            }
        );
    }

    public function down(): void
    {
        Schema::connection('intelligence')->table(
            'agent_task_sessions',
            function (Blueprint $table): void {
                $table->dropIndex('agent_task_sessions_reap_idx');
                $table->dropColumn('workspace_reaped_at');
            }
        );
    }
};
