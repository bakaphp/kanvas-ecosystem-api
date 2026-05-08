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
        Schema::connection('intelligence')->create('nervous_system_skills', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->char('uuid', 36)->unique();
            $table->unsignedInteger('apps_id');
            $table->string('name', 128);
            $table->text('description')->nullable();
            $table->string('skill_type', 16)->default('system');
            $table->string('handler', 255)->nullable();
            $table->json('definition')->nullable();
            $table->json('frameworks');
            $table->string('version', 32)->default('1.0.0');
            $table->boolean('is_active')->default(1);
            $table->boolean('is_deleted')->default(0);
            $table->timestamp('created_at')->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->timestamp('updated_at')->nullable();

            $table->index(['apps_id', 'name'], 'apps_name_idx');
            $table->index(['apps_id', 'is_active', 'is_deleted'], 'apps_active_deleted_idx');
            $table->index(['skill_type'], 'skill_type_idx');
        });

        Schema::connection('intelligence')->create('nervous_system_tools', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->char('uuid', 36)->unique();
            $table->unsignedInteger('apps_id');
            $table->string('name', 128);
            $table->text('description')->nullable();
            $table->string('tool_type', 16)->default('system');
            $table->string('handler', 255)->nullable();
            $table->json('input_schema')->nullable();
            $table->json('output_schema')->nullable();
            $table->json('requires_permission')->nullable();
            $table->json('frameworks');
            $table->string('version', 32)->default('1.0.0');
            $table->boolean('is_active')->default(1);
            $table->boolean('is_deleted')->default(0);
            $table->timestamp('created_at')->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->timestamp('updated_at')->nullable();

            $table->index(['apps_id', 'name'], 'apps_name_idx');
            $table->index(['apps_id', 'is_active', 'is_deleted'], 'apps_active_deleted_idx');
            $table->index(['tool_type'], 'tool_type_idx');
        });

        Schema::connection('intelligence')->create('nervous_system_agent_skills', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->char('uuid', 36)->unique();
            $table->unsignedInteger('apps_id');
            $table->unsignedBigInteger('companies_id');
            $table->unsignedBigInteger('agent_id');
            $table->unsignedBigInteger('skill_id');
            $table->unsignedBigInteger('granted_by_users_id')->nullable();
            $table->timestamp('granted_at')->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_active')->default(1);
            $table->json('config')->nullable();
            $table->boolean('is_deleted')->default(0);
            $table->timestamp('created_at')->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->timestamp('updated_at')->nullable();

            $table->index(['agent_id', 'is_active', 'is_deleted'], 'agent_active_idx');
            $table->index(['skill_id'], 'skill_id_idx');
            $table->index(['apps_id', 'companies_id'], 'apps_company_idx');
            $table->index(['expires_at', 'is_active'], 'expires_active_idx');
            $table->unique(['agent_id', 'skill_id', 'is_deleted'], 'agent_skill_unique');

            $table->foreign('skill_id')
                ->references('id')
                ->on('nervous_system_skills')
                ->onDelete('cascade');
        });

        Schema::connection('intelligence')->create('nervous_system_agent_tools', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->char('uuid', 36)->unique();
            $table->unsignedInteger('apps_id');
            $table->unsignedBigInteger('companies_id');
            $table->unsignedBigInteger('agent_id');
            $table->unsignedBigInteger('tool_id');
            $table->unsignedBigInteger('granted_by_users_id')->nullable();
            $table->timestamp('granted_at')->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_active')->default(1);
            $table->json('config')->nullable();
            $table->boolean('is_deleted')->default(0);
            $table->timestamp('created_at')->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->timestamp('updated_at')->nullable();

            $table->index(['agent_id', 'is_active', 'is_deleted'], 'agent_active_idx');
            $table->index(['tool_id'], 'tool_id_idx');
            $table->index(['apps_id', 'companies_id'], 'apps_company_idx');
            $table->index(['expires_at', 'is_active'], 'expires_active_idx');
            $table->unique(['agent_id', 'tool_id', 'is_deleted'], 'agent_tool_unique');

            $table->foreign('tool_id')
                ->references('id')
                ->on('nervous_system_tools')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::connection('intelligence')->dropIfExists('nervous_system_agent_tools');
        Schema::connection('intelligence')->dropIfExists('nervous_system_agent_skills');
        Schema::connection('intelligence')->dropIfExists('nervous_system_tools');
        Schema::connection('intelligence')->dropIfExists('nervous_system_skills');
    }
};
