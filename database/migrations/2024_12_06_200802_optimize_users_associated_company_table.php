<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::table('users_associated_company', function (Blueprint $table) {
            // Drop the existing primary key and redundant indexes
            $table->dropPrimary(['users_id', 'companies_id', 'companies_branches_id']);
            $table->dropUnique('users_id');
            $table->dropIndex('users_id_2');
            $table->dropIndex('user_active');
            $table->dropIndex('created_at');
            $table->dropIndex('updated_at');
            $table->dropIndex('is_deleted');
            $table->dropIndex('companies_branches_id');
            $table->dropIndex('user_role');
            $table->dropIndex('identify_id');
            $table->dropIndex('companies_id');
            $table->dropIndex('users_associated_company_companies_id_is_deleted_index');

            // Add a new surrogate primary key
            $table->bigIncrements('id')->first();

            // Add a unique constraint for the previous compound primary key
            $table->unique(['users_id', 'companies_id', 'companies_branches_id'], 'unique_users_companies_branches');

            // Adjust the row format to DYNAMIC
            DB::statement('ALTER TABLE `users_associated_company` ROW_FORMAT=DYNAMIC;');
        });

        // Optionally run an optimize table command
        //DB::statement('OPTIMIZE TABLE `users_associated_company`;');
    }

    /**
     * Reverse the migrations.
     */
    public function down()
    {
        Schema::table('users_associated_company', function (Blueprint $table) {
            // Remove the new primary key and unique constraint
            $table->dropColumn('id');
            $table->dropUnique('unique_users_companies_branches');

            // Restore the original compound primary key
            $table->primary(['users_id', 'companies_id', 'companies_branches_id']);

            // Add back the original indexes
            $table->unique(['users_id', 'companies_branches_id', 'companies_id'], 'users_id');
            $table->index(['users_id', 'companies_id'], 'users_id_2');
            $table->index('user_active');
            $table->index('created_at');
            $table->index('updated_at');
            $table->index('is_deleted');
            $table->index('companies_branches_id');
            $table->index('user_role');
            $table->index('identify_id');
            $table->index(['companies_id', 'is_deleted'], 'companies_id');
            $table->index(['companies_id', 'is_deleted'], 'users_associated_company_companies_id_is_deleted_index');
        });
    }
};
