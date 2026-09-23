<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        // idx_apps_companies_url is (apps_id, companies_id, url). ImageConversionService::convertImageUrl
        // scopes by app only, skipping the middle column, so it degrades to the apps_id prefix.
        Schema::table('filesystem', function (Blueprint $table) {
            $table->index(['apps_id', 'url'], 'idx_apps_url');
        });

        // apps_id_3 stops at (apps_id, companies_id); is_deleted and displayname were filtered
        // row-by-row because displayname had no index on this table at all.
        Schema::table('users_associated_apps', function (Blueprint $table) {
            $table->index(
                ['apps_id', 'companies_id', 'is_deleted', 'displayname'],
                'idx_app_company_deleted_displayname'
            );
        });

        // name is the second column of PRIMARY (users_id, name), so a lookup without users_id could
        // not use it and fell back to a full index scan. Callers match on a company-scoped prefix
        // (`user_key_{companies_id}%`), which a plain index serves as a range.
        Schema::table('user_config', function (Blueprint $table) {
            $table->index('name', 'idx_user_config_name');
        });

        // Every channels composite leads with companies_id/apps_id and idx_channels_entity_ns_entity
        // leads with entity_namespace. A subquery that only knows entity_id matches none of them.
        Schema::connection('social')->table('channels', function (Blueprint $table) {
            $table->index('entity_id', 'idx_channels_entity_id');
        });
    }

    public function down(): void
    {
        Schema::table('filesystem', function (Blueprint $table) {
            $table->dropIndex('idx_apps_url');
        });

        Schema::table('users_associated_apps', function (Blueprint $table) {
            $table->dropIndex('idx_app_company_deleted_displayname');
        });

        Schema::table('user_config', function (Blueprint $table) {
            $table->dropIndex('idx_user_config_name');
        });

        Schema::connection('social')->table('channels', function (Blueprint $table) {
            $table->dropIndex('idx_channels_entity_id');
        });
    }
};
