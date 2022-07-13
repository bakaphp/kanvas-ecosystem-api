<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddForeignKeysToSystemModulesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('system_modules', function (Blueprint $table) {
            $table->foreign(['apps_id'], 'system_modules_ibfk_1')->references(['id'])->on('apps')->onUpdate('NO ACTION')->onDelete('NO ACTION');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('system_modules', function (Blueprint $table) {
            $table->dropForeign('system_modules_ibfk_1');
        });
    }
}
