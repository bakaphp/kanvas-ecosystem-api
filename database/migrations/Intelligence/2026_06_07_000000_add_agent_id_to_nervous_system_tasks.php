<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Per-task assignee for the Hermes kanban sync: a Hermes child task is assigned to one
// profile (agent), and a Kanvas Plan can now span agents. The Hermes link itself
// (external task id, status, board, …) rides in custom fields — agent_id is the one
// relational field that earns a real column (indexed + GraphQL @belongsTo relation).
return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('intelligence')->table('nervous_system_tasks', function (Blueprint $table) {
            $table->unsignedBigInteger('agent_id')->nullable()->after('plan_id');
            $table->index(['agent_id', 'status'], 'agent_status_idx');
        });
    }

    public function down(): void
    {
        Schema::connection('intelligence')->table('nervous_system_tasks', function (Blueprint $table) {
            $table->dropIndex('agent_status_idx');
            $table->dropColumn('agent_id');
        });
    }
};
