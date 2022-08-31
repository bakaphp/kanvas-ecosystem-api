<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddForeignKeysToFilesystemTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('filesystem', function (Blueprint $table) {
            $table->foreign(['companies_id'], 'filesystem_ibfk_1')->references(['id'])->on('companies')->onUpdate('NO ACTION')->onDelete('NO ACTION');
            $table->foreign(['apps_id'], 'filesystem_ibfk_2')->references(['id'])->on('apps')->onUpdate('NO ACTION')->onDelete('NO ACTION');
            $table->foreign(['users_id'], 'filesystem_ibfk_3')->references(['id'])->on('users')->onUpdate('NO ACTION')->onDelete('NO ACTION');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('filesystem', function (Blueprint $table) {
            $table->dropForeign('filesystem_ibfk_1');
            $table->dropForeign('filesystem_ibfk_2');
            $table->dropForeign('filesystem_ibfk_3');
        });
    }
}
