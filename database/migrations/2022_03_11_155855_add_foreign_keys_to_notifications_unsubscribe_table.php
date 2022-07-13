<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddForeignKeysToNotificationsUnsubscribeTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('notifications_unsubscribe', function (Blueprint $table) {
            $table->foreign(['users_id'], 'notifications_unsubscribe_ibfk_1')->references(['id'])->on('users')->onUpdate('NO ACTION')->onDelete('NO ACTION');
            $table->foreign(['companies_id'], 'notifications_unsubscribe_ibfk_2')->references(['id'])->on('companies')->onUpdate('NO ACTION')->onDelete('NO ACTION');
            $table->foreign(['apps_id'], 'notifications_unsubscribe_ibfk_3')->references(['id'])->on('apps')->onUpdate('NO ACTION')->onDelete('NO ACTION');
            $table->foreign(['system_modules_id'], 'notifications_unsubscribe_ibfk_4')->references(['id'])->on('system_modules')->onUpdate('NO ACTION')->onDelete('NO ACTION');
            $table->foreign(['notification_type_id'], 'notifications_unsubscribe_ibfk_5')->references(['id'])->on('notification_types')->onUpdate('NO ACTION')->onDelete('NO ACTION');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('notifications_unsubscribe', function (Blueprint $table) {
            $table->dropForeign('notifications_unsubscribe_ibfk_1');
            $table->dropForeign('notifications_unsubscribe_ibfk_2');
            $table->dropForeign('notifications_unsubscribe_ibfk_3');
            $table->dropForeign('notifications_unsubscribe_ibfk_4');
            $table->dropForeign('notifications_unsubscribe_ibfk_5');
        });
    }
}
