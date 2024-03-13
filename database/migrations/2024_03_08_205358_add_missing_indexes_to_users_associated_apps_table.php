<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users_associated_apps', function (Blueprint $table) {
            // Add the missing indexes
            $table->index('companies_id', 'companies_id');
            $table->index('apps_id', 'apps_id_2');
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->index(['id', 'is_deleted'], 'companies_id_is_deleted');
        });

        Schema::table('filesystem_entities', function (Blueprint $table) {
            // Add the missing indexes
            $table->index(['filesystem_id', 'entity_id', 'system_modules_id', 'field_name', 'is_deleted'], 'filesystem_id_2');
            $table->index(['filesystem_id', 'entity_id', 'system_modules_id'], 'filesystem_id_3');
            $table->index(['entity_id', 'system_modules_id', 'is_deleted', 'field_name', 'filesystem_id'], 'idx_optimize_query');
            $table->index('entity_id', 'entity_id_2');
            $table->index('system_modules_id');
            $table->index('is_deleted');
            $table->index('field_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users_associated_apps', function (Blueprint $table) {
            $table->dropIndex('companies_id');
            $table->dropIndex('apps_id_2');
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->dropIndex('companies_id_is_deleted');
        });

        Schema::table('filesystem_entities', function (Blueprint $table) {
            // Drop the indexes if they exist
            $table->dropIndex('filesystem_id_2');
            $table->dropIndex('filesystem_id_3');
            $table->dropIndex('idx_optimize_query');
            $table->dropIndex('entity_id_2');
            $table->dropIndex('system_modules_id');
            $table->dropIndex('is_deleted');
            $table->dropIndex('field_name');
        });
    }
};
