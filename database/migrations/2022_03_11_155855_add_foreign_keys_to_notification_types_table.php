<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddForeignKeysToNotificationTypesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('notification_types', function (Blueprint $table) {
            $table->foreign(['apps_id'], 'notification_types_ibfk_1')->references(['id'])->on('apps')->onUpdate('NO ACTION')->onDelete('NO ACTION');
            $table->foreign(['system_modules_id'], 'notification_types_ibfk_2')->references(['id'])->on('system_modules')->onUpdate('NO ACTION')->onDelete('NO ACTION');
            $table->foreign(['parent_id'], 'notification_types_ibfk_3')->references(['id'])->on('notification_types')->onUpdate('NO ACTION')->onDelete('NO ACTION');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('notification_types', function (Blueprint $table) {
            $table->dropForeign('notification_types_ibfk_1');
            $table->dropForeign('notification_types_ibfk_2');
            $table->dropForeign('notification_types_ibfk_3');
        });
    }
}
