<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddForeignKeysToResourcesAccessesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('resources_accesses', function (Blueprint $table) {
            $table->foreign(['resources_id'], 'resources_accesses_ibfk_1')->references(['id'])->on('resources')->onUpdate('NO ACTION')->onDelete('NO ACTION');
            $table->foreign(['apps_id'], 'resources_accesses_ibfk_2')->references(['id'])->on('apps')->onUpdate('NO ACTION')->onDelete('NO ACTION');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('resources_accesses', function (Blueprint $table) {
            $table->dropForeign('resources_accesses_ibfk_1');
            $table->dropForeign('resources_accesses_ibfk_2');
        });
    }
}
