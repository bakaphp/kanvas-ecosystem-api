<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('agent_deployments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('apps_id')->index();
            $table->unsignedBigInteger('companies_id')->index();
            $table->unsignedBigInteger('agent_id')->index();
            $table->unsignedBigInteger('agent_machine_id')->index();
            $table->string('system_user');
            $table->string('home_directory');
            $table->integer('gateway_port');
            $table->integer('proxy_port');
            $table->string('container_name');
            $table->string('status')->default('pending')->index();
            $table->timestamp('launched_at')->nullable();
            $table->timestamp('terminated_at')->nullable();
            $table->timestamp('last_health_check')->nullable();
            $table->text('error_message')->nullable();
            $table->boolean('is_deleted')->default(false);
            $table->timestamps();

            $table->unique(['agent_machine_id', 'gateway_port'], 'agent_deploy_machine_gateway_unique');
            $table->unique(['agent_machine_id', 'system_user'], 'agent_deploy_machine_user_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_deployments');
    }
};
