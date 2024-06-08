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
        Schema::create('company_task_engagement_items', function (Blueprint $table) {
            $table->bigInteger('task_list_item_id')->index();
            $table->bigInteger('lead_id')->index();
            $table->bigInteger('companies_id')->index();
            $table->bigInteger('apps_id')->index();
            $table->bigInteger('users_id')->index();
            $table->enum('status', ['pending', 'in_progress', 'completed'])->default('pending')->comment('pending, in_progress, completed')->index();
            $table->bigInteger('engagement_start_id')->index();
            $table->bigInteger('engagement_end_id')->index();
            $table->json('config');
            $table->timestamps();
            $table->tinyInteger('is_deleted')->default(0)->index();

            $table->primary(['task_list_item_id', 'lead_id']);
            $table->index(['task_list_item_id', 'lead_id', 'companies_id'], 'task_list_company_index');
            $table->index(['task_list_item_id', 'lead_id', 'apps_id'], 'task_list_apps_index');
            $table->index(['task_list_item_id', 'lead_id', 'users_id'], 'task_list_users_index');
            $table->index(['task_list_item_id', 'lead_id', 'engagement_start_id'], 'task_list_engagement_start_index');
            $table->index(['task_list_item_id', 'lead_id', 'engagement_end_id'], 'task_list_engagement_end_index');
            $table->index(['task_list_item_id', 'lead_id', 'apps_id', 'companies_id'], 'task_list_apps_company_index');
            $table->index(['task_list_item_id', 'lead_id', 'status'], 'task_list_status_index');
            $table->index(['task_list_item_id', 'lead_id', 'is_deleted'], 'task_list_deleted_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('company_task_engagement_items');
    }
};
