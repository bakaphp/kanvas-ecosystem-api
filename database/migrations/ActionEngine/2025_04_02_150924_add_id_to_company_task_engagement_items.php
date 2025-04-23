<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // First step: drop the existing primary key
        Schema::table('company_task_engagement_items', function (Blueprint $table) {
            $table->dropPrimary(['task_list_item_id', 'lead_id']);
        });

        // Second step: add the new auto-incrementing ID column
        Schema::table('company_task_engagement_items', function (Blueprint $table) {
            $table->bigIncrements('id')->first();

            // Add a unique constraint on the original primary key columns
            $table->unique(['task_list_item_id', 'lead_id']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('company_task_engagement_items', function (Blueprint $table) {
            // Remove the unique constraint first
            $table->dropUnique(['task_list_item_id', 'lead_id']);

            // Drop the ID column
            $table->dropColumn('id');

            // Re-add the original primary key
            $table->primary(['task_list_item_id', 'lead_id']);
        });
    }
};
