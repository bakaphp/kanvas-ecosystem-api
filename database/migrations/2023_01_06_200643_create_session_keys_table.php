<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateSessionKeysTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('session_keys', function (Blueprint $table) {
            $table->string('sessions_id', 45)->index('session_id');
            $table->unsignedInteger('users_id')->index('user_id');
            $table->string('last_ip', 39)->nullable();
            $table->integer('last_login')->index('last_login');

            $table->primary(['sessions_id', 'users_id']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('session_keys');
    }
}
