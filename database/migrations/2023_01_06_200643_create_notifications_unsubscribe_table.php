<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateNotificationsUnsubscribeTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('notifications_unsubscribe', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('users_id')->index('users_id');
            $table->integer('companies_id')->index('companies_id');
            $table->integer('apps_id')->index('apps_id');
            $table->integer('system_modules_id')->index('system_modules_id');
            $table->integer('notification_type_id')->index('notification_type_id');
            $table->string('email');
            $table->dateTime('created_at')->index('created_at');
            $table->dateTime('updated_at')->nullable()->index('updated_at');
            $table->integer('is_deleted')->index('is_deleted');

            $table->index(['users_id', 'companies_id', 'apps_id', 'notification_type_id', 'is_deleted'], 'users_id_companies_id_apps_id_notification_type_id_is_deleted');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('notifications_unsubscribe');
    }
}
