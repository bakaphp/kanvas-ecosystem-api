<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddForeignKeysToFilesystemSettingsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('filesystem_settings', function (Blueprint $table) {
            $table->foreign(['filesystem_id'], 'filesystem_settings_ibfk_1')->references(['id'])->on('filesystem')->onUpdate('NO ACTION')->onDelete('NO ACTION');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('filesystem_settings', function (Blueprint $table) {
            $table->dropForeign('filesystem_settings_ibfk_1');
        });
    }
}
