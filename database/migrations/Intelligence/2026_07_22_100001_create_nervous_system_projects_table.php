<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    protected $connection = 'intelligence';

    public function up(): void
    {
        Schema::connection('intelligence')->create('nervous_system_projects', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->char('uuid', 36)->unique();
            $table->unsignedInteger('apps_id');
            $table->unsignedBigInteger('companies_id');
            $table->unsignedBigInteger('users_id');
            $table->unsignedBigInteger('workspace_id')->nullable();
            $table->unsignedBigInteger('agent_id');
            $table->unsignedBigInteger('swarm_id')->nullable();
            $table->unsignedBigInteger('parent_project_id')->nullable();
            $table->string('title', 255);
            $table->string('slug', 255);
            $table->text('objective')->nullable();
            $table->text('description')->nullable();
            $table->string('status', 50)->default('draft');
            $table->integer('priority')->default(0);
            $table->timestamp('deadline_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedTinyInteger('completion_pct')->default(0);
            $table->unsignedSmallInteger('heartbeat_interval_minutes')->default(15);
            $table->timestamp('last_heartbeat_at')->nullable();
            $table->timestamp('next_heartbeat_at')->nullable();
            $table->unsignedBigInteger('default_channel_id')->nullable();
            $table->unsignedBigInteger('receiver_webhook_id')->nullable();
            $table->json('config')->nullable();
            $table->json('metadata')->nullable();
            $table->boolean('is_deleted')->default(0);
            $table->timestamp('created_at')->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->timestamp('updated_at')->nullable();

            $table->index(['apps_id', 'companies_id', 'is_deleted'], 'pr_tenant');
            $table->index(['apps_id', 'companies_id', 'slug'], 'pr_slug');
            $table->index(['users_id'], 'pr_owner');
            $table->index(['workspace_id'], 'pr_workspace');
            $table->index(['agent_id'], 'pr_agent');
            $table->index(['swarm_id'], 'pr_swarm');
            $table->index(['parent_project_id'], 'pr_parent');
            $table->index(['apps_id', 'companies_id', 'status', 'is_deleted'], 'pr_status');
            $table->index(['apps_id', 'companies_id', 'deadline_at'], 'pr_deadline');
            $table->index(['is_deleted', 'status', 'next_heartbeat_at'], 'pr_heartbeat');
        });
    }

    public function down(): void
    {
        Schema::connection('intelligence')->dropIfExists('nervous_system_projects');
    }
};
