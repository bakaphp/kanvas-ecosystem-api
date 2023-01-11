<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateUsersNotificationSettingsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('users_notification_settings', function (Blueprint $table) {
            $table->integer('users_id');
            $table->integer('apps_id');
            $table->integer('notifications_types_id');
            $table->integer('is_enabled')->default(1)->index('is_enabled');
            $table->text('channels')->nullable();
            $table->dateTime('created_at')->index('created_at');
            $table->dateTime('updated_at')->nullable()->index('updated_at');
            $table->tinyInteger('is_deleted')->default(0)->index('is_deleted');

            $table->primary(['users_id', 'apps_id', 'notifications_types_id'], 'users_apps_notifications_types_primary');
            $table->index(['users_id', 'apps_id', 'notifications_types_id', 'is_deleted'], 'users_apps_notifications_types');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('users_notification_settings');
    }
}
