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
        Schema::connection('intelligence')->create('nervous_system_plans', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->char('uuid', 36)->unique();
            $table->unsignedInteger('apps_id');
            $table->unsignedBigInteger('companies_id');
            $table->unsignedBigInteger('agent_id')->nullable();
            $table->unsignedBigInteger('users_id')->nullable();
            $table->unsignedBigInteger('parent_plan_id')->nullable();
            $table->string('entity_namespace', 191)->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->string('plan_type', 64);
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->string('status', 32)->default('draft');
            $table->unsignedSmallInteger('priority')->default(0);
            $table->timestamp('deadline_at')->nullable();
            $table->unsignedTinyInteger('completion_pct')->default(0);
            $table->json('input')->nullable();
            $table->json('output')->nullable();
            $table->decimal('confidence_score', 4, 3)->nullable();
            $table->boolean('requires_human_approval')->default(0);
            $table->unsignedBigInteger('approved_by_user_id')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->text('review_outcome')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('error_message')->nullable();
            $table->boolean('is_deleted')->default(0);
            $table->timestamp('created_at')->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->timestamp('updated_at')->nullable();

            $table->index(['apps_id', 'companies_id', 'status', 'deadline_at'], 'apps_company_status_deadline');
            $table->index(['agent_id', 'status'], 'agent_status');
            $table->index(['users_id', 'status'], 'users_status');
            $table->index(['parent_plan_id'], 'parent_plan_idx');
            $table->index(['entity_namespace', 'entity_id'], 'entity_idx');
            $table->index(['plan_type', 'status'], 'plan_type_status');
            $table->index(['is_deleted'], 'is_deleted_idx');
        });

        Schema::connection('intelligence')->create('nervous_system_tasks', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->char('uuid', 36)->unique();
            $table->unsignedBigInteger('plan_id');
            $table->unsignedInteger('apps_id');
            $table->unsignedBigInteger('companies_id');
            $table->unsignedInteger('sequence')->default(0);
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->string('status', 32)->default('pending');
            $table->json('result')->nullable();
            $table->text('blocked_reason')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->boolean('is_deleted')->default(0);
            $table->timestamp('created_at')->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->timestamp('updated_at')->nullable();

            $table->index(['plan_id', 'sequence'], 'plan_sequence');
            $table->index(['plan_id', 'status'], 'plan_status');
            $table->index(['apps_id', 'companies_id', 'status'], 'apps_company_status');
            $table->index(['status', 'started_at'], 'status_started');
            $table->index(['is_deleted'], 'is_deleted_idx');

            $table->foreign('plan_id')
                ->references('id')
                ->on('nervous_system_plans')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::connection('intelligence')->dropIfExists('nervous_system_tasks');
        Schema::connection('intelligence')->dropIfExists('nervous_system_plans');
    }
};
