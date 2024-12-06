<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up()
    {
        Schema::table('users_associated_apps', function (Blueprint $table) {
            // Drop the existing primary key and redundant indexes
            $table->dropPrimary(['users_id', 'apps_id', 'companies_id']);
            //$table->dropIndex('apps_id');
            //$table->dropIndex('apps_id_2');
            //$table->dropIndex('apps_id_3');
            //$table->dropIndex('users_id');
            $table->dropIndex('users_associated_deleted_index');
            $table->dropIndex('users_associated_active_deleted_index');
            $table->dropIndex('users_associated_apps_apps_id_companies_id_index');
            $table->dropIndex('users_associated_apps_apps_id_companies_id_is_deleted_index');

            // Add a new surrogate primary key
            $table->bigIncrements('id')->first();

            // Add a unique constraint for the previous compound primary key
            $table->unique(['users_id', 'apps_id', 'companies_id'], 'unique_users_apps_companies');

            // Adjust the row format to DYNAMIC
            DB::statement('ALTER TABLE `users_associated_apps` ROW_FORMAT=DYNAMIC;');
        });

        // Optionally run an optimize table command
        // DB::statement('OPTIMIZE TABLE `users_associated_apps`;');
    }

    /**
     * Reverse the migrations.
     */
    public function down()
    {
        Schema::table('users_associated_apps', function (Blueprint $table) {
            // Remove the new primary key and unique constraint
            $table->dropColumn('id');
            $table->dropUnique('unique_users_apps_companies');

            // Restore the original compound primary key
            $table->primary(['users_id', 'apps_id', 'companies_id']);

            // Add back the original indexes
            $table->index('apps_id');
            $table->index(['apps_id', 'companies_id'], 'apps_id_3');
            $table->index(['apps_id', 'is_deleted']);
            $table->index(['users_id', 'apps_id', 'is_deleted']);
            $table->index(['users_id', 'apps_id', 'companies_id', 'is_deleted'], 'users_associated_deleted_index');
            $table->index(['users_id', 'apps_id', 'companies_id', 'is_active', 'is_deleted'], 'users_associated_active_deleted_index');
            $table->index(['apps_id', 'companies_id'], 'users_associated_apps_apps_id_companies_id_index');
            $table->index(['apps_id', 'companies_id', 'is_deleted'], 'users_associated_apps_apps_id_companies_id_is_deleted_index');
        });
    }
};
