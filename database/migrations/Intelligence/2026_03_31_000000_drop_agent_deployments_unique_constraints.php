<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('agent_deployments', function (Blueprint $table) {
            $table->dropUnique('agent_deploy_machine_gateway_unique');
            $table->dropUnique('agent_deploy_machine_user_unique');

            $table->index(['agent_machine_id', 'status'], 'agent_deploy_machine_status_idx');
            $table->index('container_name', 'agent_deploy_container_name_idx');
        });
    }

    public function down(): void
    {
        Schema::table('agent_deployments', function (Blueprint $table) {
            $table->dropIndex('agent_deploy_machine_status_idx');
            $table->dropIndex('agent_deploy_container_name_idx');

            $table->unique(['agent_machine_id', 'gateway_port'], 'agent_deploy_machine_gateway_unique');
            $table->unique(['agent_machine_id', 'system_user'], 'agent_deploy_machine_user_unique');
        });
    }
};
