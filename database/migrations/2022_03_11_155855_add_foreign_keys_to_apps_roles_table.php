<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddForeignKeysToAppsRolesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('apps_roles', function (Blueprint $table) {
            $table->foreign(['apps_id'], 'apps_roles_ibfk_1')->references(['id'])->on('apps')->onUpdate('NO ACTION')->onDelete('NO ACTION');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('apps_roles', function (Blueprint $table) {
            $table->dropForeign('apps_roles_ibfk_1');
        });
    }
}
