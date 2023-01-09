<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateUsersNotificationEntityImportanceTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('users_notification_entity_importance', function (Blueprint $table) {
            $table->integer('apps_id');
            $table->integer('users_id');
            $table->char('entity_id', 50);
            $table->integer('system_modules_id');
            $table->integer('importance_id')->index('relevancies_id');
            $table->dateTime('created_at')->index('created_at');
            $table->dateTime('updated_at')->nullable()->index('updated_at');
            $table->tinyInteger('is_deleted')->default(0)->index('is_deleted');

            $table->index(['apps_id', 'users_id', 'entity_id', 'is_deleted'], 'apps_id_users_id_entity_id_is_deleted');
            $table->index(['apps_id', 'users_id', 'entity_id', 'system_modules_id', 'is_deleted'], 'apps_id_users_id_entity_system_module');
            $table->primary(['apps_id', 'users_id', 'entity_id', 'system_modules_id'], 'users_notification_entity_importance_primary');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('users_notification_entity_importance');
    }
}
