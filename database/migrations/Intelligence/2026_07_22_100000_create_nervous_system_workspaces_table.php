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
        Schema::connection('intelligence')->create('nervous_system_workspaces', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->char('uuid', 36)->unique();
            $table->unsignedInteger('apps_id');
            $table->unsignedBigInteger('companies_id');
            $table->unsignedBigInteger('users_id');
            $table->unsignedBigInteger('agent_id')->nullable();
            $table->string('name', 255);
            $table->string('slug', 255);
            $table->text('description')->nullable();
            $table->string('status', 50)->default('active');
            $table->json('config')->nullable();
            $table->json('metadata')->nullable();
            $table->boolean('is_deleted')->default(0);
            $table->timestamp('created_at')->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->timestamp('updated_at')->nullable();

            $table->index(['apps_id', 'companies_id', 'is_deleted'], 'ws_tenant');
            $table->index(['apps_id', 'companies_id', 'slug'], 'ws_slug');
            $table->index(['apps_id', 'companies_id', 'status', 'is_deleted'], 'ws_status');
            $table->index(['users_id'], 'ws_owner');
            $table->index(['agent_id'], 'ws_agent');
        });
    }

    public function down(): void
    {
        Schema::connection('intelligence')->dropIfExists('nervous_system_workspaces');
    }
};
