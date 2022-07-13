<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddForeignKeysToRegisterRolesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('register_roles', function (Blueprint $table) {
            $table->foreign(['apps_id'], 'register_roles_ibfk_1')->references(['id'])->on('apps')->onUpdate('NO ACTION')->onDelete('NO ACTION');
            $table->foreign(['companies_id'], 'register_roles_ibfk_2')->references(['id'])->on('companies')->onUpdate('NO ACTION')->onDelete('NO ACTION');
            $table->foreign(['roles_id'], 'register_roles_ibfk_3')->references(['id'])->on('roles')->onUpdate('NO ACTION')->onDelete('NO ACTION');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('register_roles', function (Blueprint $table) {
            $table->dropForeign('register_roles_ibfk_1');
            $table->dropForeign('register_roles_ibfk_2');
            $table->dropForeign('register_roles_ibfk_3');
        });
    }
}
