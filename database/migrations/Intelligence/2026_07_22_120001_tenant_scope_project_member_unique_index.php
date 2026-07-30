<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    private const CONNECTION = 'intelligence';

    public function up(): void
    {
        // Every membership lookup scopes by tenant (apps_id, companies_id) first, so make the unique
        // constraint and the covering index start with the tenant. The old (project_id, users_id)
        // unique + separate (apps_id, companies_id) index are both subsumed by this one.
        Schema::connection(self::CONNECTION)->table('nervous_system_project_members', function (Blueprint $table) {
            $table->dropUnique('pm_project_user');
            $table->dropIndex('pm_tenant');
            $table->unique(
                ['apps_id', 'companies_id', 'project_id', 'users_id'],
                'pm_tenant_project_user',
            );
        });
    }

    public function down(): void
    {
        Schema::connection(self::CONNECTION)->table('nervous_system_project_members', function (Blueprint $table) {
            $table->dropUnique('pm_tenant_project_user');
            $table->unique(['project_id', 'users_id'], 'pm_project_user');
            $table->index(['apps_id', 'companies_id'], 'pm_tenant');
        });
    }
};
