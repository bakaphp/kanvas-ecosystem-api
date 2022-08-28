<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddForeignKeysToUserLinkedSourcesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('user_linked_sources', function (Blueprint $table) {
            $table->foreign(['users_id'], 'user_linked_sources_ibfk_1')->references(['id'])->on('users')->onUpdate('NO ACTION')->onDelete('NO ACTION');
            $table->foreign(['source_id'], 'user_linked_sources_ibfk_2')->references(['id'])->on('sources')->onUpdate('NO ACTION')->onDelete('NO ACTION');
            $table->foreign(['source_users_id'], 'user_linked_sources_ibfk_3')->references(['email'])->on('users')->onUpdate('NO ACTION')->onDelete('NO ACTION');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('user_linked_sources', function (Blueprint $table) {
            $table->dropForeign('user_linked_sources_ibfk_1');
            $table->dropForeign('user_linked_sources_ibfk_2');
            $table->dropForeign('user_linked_sources_ibfk_3');
        });
    }
}
