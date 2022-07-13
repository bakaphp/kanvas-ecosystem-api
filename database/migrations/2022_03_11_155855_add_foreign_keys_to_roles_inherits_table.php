<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddForeignKeysToRolesInheritsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('roles_inherits', function (Blueprint $table) {
            $table->foreign(['roles_id'], 'roles_inherits_ibfk_1')->references(['id'])->on('roles')->onUpdate('NO ACTION')->onDelete('NO ACTION');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('roles_inherits', function (Blueprint $table) {
            $table->dropForeign('roles_inherits_ibfk_1');
        });
    }
}
