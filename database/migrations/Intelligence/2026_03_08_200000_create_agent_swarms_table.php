<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('agent_swarms', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique()->index();
            $table->unsignedBigInteger('apps_id')->index();
            $table->unsignedBigInteger('companies_id')->index();
            $table->unsignedBigInteger('users_id')->index();
            $table->string('name')->index();
            $table->string('slug')->index();
            $table->longText('description')->nullable();
            $table->string('status', 50)->default('draft')->index();
            $table->json('config')->nullable();
            $table->boolean('is_active')->default(1)->index();
            $table->timestamp('created_at')->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->timestamp('updated_at')->nullable();
            $table->boolean('is_deleted')->default(0)->index();

            $table->unique(['apps_id', 'companies_id', 'slug'], 'idx_agent_swarms_slug_unique');
            $table->index(['apps_id', 'companies_id', 'is_deleted'], 'idx_agent_swarms_company');
        });

        Schema::create('agent_swarm_members', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agent_swarm_id')->index();
            $table->unsignedBigInteger('agent_id')->index();
            $table->string('role', 50)->nullable();
            $table->json('config')->nullable();
            $table->boolean('is_deleted')->default(0)->index();
            $table->timestamp('created_at')->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->timestamp('updated_at')->nullable();

            $table->unique(['agent_swarm_id', 'agent_id'], 'idx_swarm_member_unique');

            $table->foreign('agent_swarm_id')->references('id')->on('agent_swarms');
            $table->foreign('agent_id')->references('id')->on('agents');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_swarm_members');
        Schema::dropIfExists('agent_swarms');
    }
};
