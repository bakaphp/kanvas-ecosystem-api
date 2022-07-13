<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddForeignKeysToMenusLinksTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('menus_links', function (Blueprint $table) {
            $table->foreign(['menus_id'], 'menus_links_ibfk_1')->references(['id'])->on('menus')->onUpdate('NO ACTION')->onDelete('NO ACTION');
            $table->foreign(['system_modules_id'], 'menus_links_ibfk_2')->references(['id'])->on('system_modules')->onUpdate('NO ACTION')->onDelete('NO ACTION');
            $table->foreign(['parent_id'], 'menus_links_ibfk_3')->references(['id'])->on('menus_links')->onUpdate('NO ACTION')->onDelete('NO ACTION');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('menus_links', function (Blueprint $table) {
            $table->dropForeign('menus_links_ibfk_1');
            $table->dropForeign('menus_links_ibfk_2');
            $table->dropForeign('menus_links_ibfk_3');
        });
    }
}
