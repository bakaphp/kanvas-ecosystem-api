<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddForeignKeysToSessionKeysTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('session_keys', function (Blueprint $table) {
            $table->foreign(['sessions_id'], 'session_keys_ibfk_1')->references(['id'])->on('sessions')->onUpdate('NO ACTION')->onDelete('NO ACTION');
            $table->foreign(['users_id'], 'session_keys_ibfk_2')->references(['id'])->on('users')->onUpdate('NO ACTION')->onDelete('NO ACTION');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('session_keys', function (Blueprint $table) {
            $table->dropForeign('session_keys_ibfk_1');
            $table->dropForeign('session_keys_ibfk_2');
        });
    }
}
