<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddForeignKeysToUsersTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreign(['roles_id'], 'users_ibfk_1')->references(['id'])->on('roles')->onUpdate('NO ACTION')->onDelete('NO ACTION');
            // $table->foreign(['city_id'], 'users_ibfk_2')->references(['id'])->on('countries_cities')->onUpdate('NO ACTION')->onDelete('NO ACTION');
            // $table->foreign(['state_id'], 'users_ibfk_3')->references(['id'])->on('countries_states')->onUpdate('NO ACTION')->onDelete('NO ACTION');
            // $table->foreign(['country_id'], 'users_ibfk_4')->references(['id'])->on('countries')->onUpdate('NO ACTION')->onDelete('NO ACTION');
            $table->foreign(['system_modules_id'], 'users_ibfk_2')->references(['id'])->on('system_modules')->onUpdate('NO ACTION')->onDelete('NO ACTION');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign('users_ibfk_1');
            // $table->dropForeign('users_ibfk_4');
            // $table->dropForeign('users_ibfk_5');
            // $table->dropForeign('users_ibfk_6');
            $table->dropForeign('users_ibfk_2');
        });
    }
}
