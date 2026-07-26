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
        Schema::connection('intelligence')->create('nervous_system_project_members', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('apps_id');
            $table->unsignedBigInteger('companies_id');
            $table->unsignedBigInteger('project_id');
            $table->string('member_type', 20);
            $table->unsignedBigInteger('users_id');
            $table->unsignedBigInteger('agent_id')->nullable();
            $table->string('role', 30)->default('contributor');
            $table->json('config')->nullable();
            $table->boolean('is_active')->default(1);
            $table->boolean('is_deleted')->default(0);
            $table->timestamp('created_at')->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->timestamp('updated_at')->nullable();

            // One membership per (project, user) — a re-add restores the soft-deleted row.
            $table->unique(['project_id', 'users_id'], 'pm_project_user');
            $table->index(['project_id', 'member_type', 'is_deleted'], 'pm_project');
            $table->index(['users_id'], 'pm_user');
            $table->index(['agent_id'], 'pm_agent');
            $table->index(['apps_id', 'companies_id'], 'pm_tenant');
        });
    }

    public function down(): void
    {
        Schema::connection('intelligence')->dropIfExists('nervous_system_project_members');
    }
};
