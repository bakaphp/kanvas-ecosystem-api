<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    private const CONNECTION = 'intelligence';

    public function up(): void
    {
        // Membership identity is the member ENTITY (agent_id for agents, users_id for humans), not the
        // user alone — two distinct agents can share one Kanvas user. Add agent_id to the unique so
        // both coexist; matching on users_id alone would collapse them into one row.
        Schema::connection(self::CONNECTION)->table('nervous_system_project_members', function (Blueprint $table) {
            $table->dropUnique('pm_tenant_project_user');
            $table->unique(
                ['apps_id', 'companies_id', 'project_id', 'users_id', 'agent_id'],
                'pm_tenant_member',
            );
        });
    }

    public function down(): void
    {
        Schema::connection(self::CONNECTION)->table('nervous_system_project_members', function (Blueprint $table) {
            $table->dropUnique('pm_tenant_member');
            $table->unique(
                ['apps_id', 'companies_id', 'project_id', 'users_id'],
                'pm_tenant_project_user',
            );
        });
    }
};
