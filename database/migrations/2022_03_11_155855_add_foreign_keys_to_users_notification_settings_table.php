<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddForeignKeysToUsersNotificationSettingsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('users_notification_settings', function (Blueprint $table) {
            $table->foreign(['users_id'], 'users_notification_settings_ibfk_1')->references(['id'])->on('users')->onUpdate('NO ACTION')->onDelete('NO ACTION');
            $table->foreign(['apps_id'], 'users_notification_settings_ibfk_2')->references(['id'])->on('apps')->onUpdate('NO ACTION')->onDelete('NO ACTION');
            $table->foreign(['notifications_types_id'], 'users_notification_settings_ibfk_3')->references(['id'])->on('notification_types')->onUpdate('NO ACTION')->onDelete('NO ACTION');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('users_notification_settings', function (Blueprint $table) {
            $table->dropForeign('users_notification_settings_ibfk_1');
            $table->dropForeign('users_notification_settings_ibfk_2');
            $table->dropForeign('users_notification_settings_ibfk_3');
        });
    }
}
