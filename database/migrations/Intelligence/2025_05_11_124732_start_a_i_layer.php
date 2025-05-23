<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('agent_types', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique()->index();
            $table->unsignedBigInteger('apps_id')->index();
            $table->string('name')->index();
            $table->string('description')->nullable();
            $table->json('config')->nullable();
            $table->longText('role');
            $table->boolean('is_active')->default(1)->index();
            $table->boolean('is_published')->default(0)->index();
            $table->boolean('is_multi_agent')->default(0)->index();
            $table->json('multi_agent_list');
            $table->timestamp('created_at')->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->timestamp('updated_at')->nullable();
            $table->boolean('is_deleted')->default(0)->index();

            // Indexes
            $table->index(['name', 'apps_id', 'is_deleted'], 'idx_agent_types_search');
        });

        Schema::create('communication_channels', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique()->index();
            $table->unsignedBigInteger('apps_id')->index();
            $table->string('name')->index();
            $table->text('description')->nullable();
            $table->string('handler');
            $table->json('config')->nullable();
            $table->boolean('is_active')->default(1)->index();
            $table->boolean('is_published')->default(0)->index();
            $table->timestamp('created_at')->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->timestamp('updated_at')->nullable();
            $table->boolean('is_deleted')->default(0)->index();

            // Indexes
            $table->index(['name', 'apps_id', 'is_deleted'], 'idx_communication_channels_search');
        });

        Schema::create('agent_models', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique()->index();
            $table->unsignedBigInteger('apps_id')->index();
            $table->string('name')->index();
            $table->json('config')->nullable();
            $table->boolean('is_active')->default(1)->index();
            $table->boolean('is_published')->default(0)->index();
            $table->timestamp('created_at')->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->timestamp('updated_at')->nullable();
            $table->boolean('is_deleted')->default(0)->index();

            // Indexes
            $table->index(['name', 'apps_id', 'is_deleted'], 'idx_agent_models_search');
        });

        Schema::create('agents', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique()->index();
            $table->unsignedBigInteger('apps_id')->index();
            $table->unsignedBigInteger('companies_id')->index();
            $table->unsignedBigInteger('agent_type_id')->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->longText('description')->nullable();
            $table->json('config')->nullable();
            $table->unsignedBigInteger('company_task_list_id')->index()->nullable();
            $table->longText('role');
            $table->unsignedBigInteger('agent_model_id')->index()->nullable();
            $table->boolean('is_active')->default(1)->index();
            $table->timestamp('created_at')->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->timestamp('updated_at')->nullable();
            $table->boolean('is_deleted')->default(0)->index();

            // Indexes
            $table->index(['apps_id', 'companies_id', 'is_deleted'], 'idx_agents_company');
            $table->index(['user_id', 'is_active', 'is_deleted'], 'idx_agents_user_active');

            // Foreign keys
            $table->foreign('agent_type_id')->references('id')->on('agent_types');
            $table->foreign('agent_model_id')->references('id')->on('agent_models');
        });

        Schema::create('agent_histories', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique()->index();
            $table->unsignedBigInteger('agent_id')->index();
            $table->unsignedBigInteger('companies_id')->index();
            $table->unsignedBigInteger('apps_id')->index();
            $table->unsignedBigInteger('company_task_engagement_item_id')->index()->nullable();
            $table->unsignedBigInteger('message_id')->nullable();
            $table->string('entity_namespace');
            $table->unsignedBigInteger('entity_id');
            $table->longText('context');
            $table->json('config')->nullable();
            $table->json('external_reference')->nullable();
            $table->json('input')->nullable();
            $table->json('output')->nullable();
            $table->json('error')->nullable();
            $table->timestamp('created_at')->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->timestamp('updated_at')->nullable();
            $table->boolean('is_deleted')->default(0)->index();

            // Indexes
            $table->index(['agent_id', 'created_at'], 'idx_agent_histories_timeline');

            // Foreign keys
            $table->foreign('agent_id')->references('id')->on('agents');
        });

        Schema::create('agent_communication_channels', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agent_id')->index();
            $table->unsignedBigInteger('communication_channel_id')->index();
            $table->string('entry_point')->nullable();
            $table->json('config')->nullable();
            $table->timestamp('created_at')->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->timestamp('updated_at')->nullable();
            $table->boolean('is_deleted')->default(0)->index();

            // Unique constraint
            $table->unique(['agent_id', 'communication_channel_id'], 'idx_agent_channel_unique');

            // Foreign keys
            $table->foreign('agent_id')->references('id')->on('agents');
            $table->foreign('communication_channel_id')->references('id')->on('communication_channels');
        });

        Schema::create('agent_versions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agent_id')->index();
            $table->string('version', 50)->index();
            $table->json('config')->nullable();
            $table->text('changes')->nullable();
            $table->unsignedBigInteger('created_by')->index();
            $table->timestamp('created_at')->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->boolean('is_active')->default(0);
            $table->boolean('is_deleted')->default(0)->index();

            // Unique constraint
            $table->unique(['agent_id', 'version'], 'idx_agent_versions_unique');

            // Foreign keys
            $table->foreign('agent_id')->references('id')->on('agents');
        });

        Schema::create('agent_feedback', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agent_history_id')->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->integer('rating')->index()->default(0);
            $table->text('feedback_text')->nullable();
            $table->timestamp('created_at')->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->timestamp('updated_at')->nullable();
            $table->boolean('is_deleted')->default(0)->index();

            // Unique constraint
            $table->unique(['agent_history_id', 'user_id'], 'idx_agent_feedback_unique');

            // Foreign keys
            $table->foreign('agent_history_id')->references('id')->on('agent_histories');
        });

        Schema::create('agent_performance_metrics', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agent_id')->index();
            $table->unsignedBigInteger('agent_history_id')->index();
            $table->string('metric_type', 100)->index();
            $table->float('value')->index()->default(0);
            $table->timestamp('period_start')->index();
            $table->timestamp('period_end');
            $table->timestamp('created_at')->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->timestamp('updated_at')->nullable();
            $table->boolean('is_deleted')->default(0)->index();

            // Indexes
            $table->index(['agent_id', 'metric_type', 'period_start'], 'idx_agent_metrics_search');

            // Foreign keys
            $table->foreign('agent_id')->references('id')->on('agents');
            $table->foreign('agent_history_id')->references('id')->on('agent_histories');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agent_performance_metrics');
        Schema::dropIfExists('agent_feedback');
        Schema::dropIfExists('agent_versions');
        Schema::dropIfExists('agent_communication_channels');
        Schema::dropIfExists('agent_histories');
        Schema::dropIfExists('agents');
        Schema::dropIfExists('agent_models');
        Schema::dropIfExists('communication_channels');
        Schema::dropIfExists('agent_types');
    }
};
