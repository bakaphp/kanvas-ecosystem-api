<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddForeignKeysToUsersAssociatedAppsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('users_associated_apps', function (Blueprint $table) {
            $table->foreign(['users_id'], 'users_associated_apps_ibfk_1')->references(['id'])->on('users')->onUpdate('NO ACTION')->onDelete('NO ACTION');
            $table->foreign(['apps_id'], 'users_associated_apps_ibfk_2')->references(['id'])->on('apps')->onUpdate('NO ACTION')->onDelete('NO ACTION');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('users_associated_apps', function (Blueprint $table) {
            $table->dropForeign('users_associated_apps_ibfk_1');
            $table->dropForeign('users_associated_apps_ibfk_2');
        });
    }
}
