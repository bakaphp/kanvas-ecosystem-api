<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Guarded with hasIndex because an earlier revision of this migration also touched the `social`
     * connection and aborted partway, leaving these three applied but the migration unrecorded.
     */
    public function up(): void
    {
        // idx_apps_companies_url is (apps_id, companies_id, url). ImageConversionService::convertImageUrl
        // scopes by app only, skipping the middle column, so it degrades to the apps_id prefix.
        if (! Schema::hasIndex('filesystem', 'idx_apps_url')) {
            Schema::table('filesystem', function (Blueprint $table) {
                $table->index(['apps_id', 'url'], 'idx_apps_url');
            });
        }

        // apps_id_3 stops at (apps_id, companies_id); is_deleted and displayname were filtered
        // row-by-row because displayname had no index on this table at all.
        if (! Schema::hasIndex('users_associated_apps', 'idx_app_company_deleted_displayname')) {
            Schema::table('users_associated_apps', function (Blueprint $table) {
                $table->index(
                    ['apps_id', 'companies_id', 'is_deleted', 'displayname'],
                    'idx_app_company_deleted_displayname'
                );
            });
        }

        // name is the second column of PRIMARY (users_id, name), so a lookup without users_id could
        // not use it and fell back to a full index scan. Callers match on a company-scoped prefix
        // (`user_key_{companies_id}%`), which a plain index serves as a range.
        if (! Schema::hasIndex('user_config', 'idx_user_config_name')) {
            Schema::table('user_config', function (Blueprint $table) {
                $table->index('name', 'idx_user_config_name');
            });
        }
    }

    public function down(): void
    {
        $indexes = [
            'filesystem' => 'idx_apps_url',
            'users_associated_apps' => 'idx_app_company_deleted_displayname',
            'user_config' => 'idx_user_config_name',
        ];

        foreach ($indexes as $table => $index) {
            if (! Schema::hasIndex($table, $index)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($index) {
                $blueprint->dropIndex($index);
            });
        }
    }
};
