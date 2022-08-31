<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddForeignKeysToFilesystemEntitiesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('filesystem_entities', function (Blueprint $table) {
            $table->foreign(['filesystem_id'], 'filesystem_entities_ibfk_1')->references(['id'])->on('filesystem')->onUpdate('NO ACTION')->onDelete('NO ACTION');
            $table->foreign(['companies_id'], 'filesystem_entities_ibfk_2')->references(['id'])->on('companies')->onUpdate('NO ACTION')->onDelete('NO ACTION');
            $table->foreign(['system_modules_id'], 'filesystem_entities_ibfk_3')->references(['id'])->on('system_modules')->onUpdate('NO ACTION')->onDelete('NO ACTION');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('filesystem_entities', function (Blueprint $table) {
            $table->dropForeign('filesystem_entities_ibfk_1');
            $table->dropForeign('filesystem_entities_ibfk_2');
            $table->dropForeign('filesystem_entities_ibfk_3');
        });
    }
}
