<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateSessionsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id', 45)->primary();
            $table->integer('users_id')->index('user_id');
            $table->text('token');
            $table->integer('start')->index('start');
            $table->integer('time')->default(0)->index('time');
            $table->string('ip', 39);
            $table->string('page', 45);
            $table->enum('logged_in', ['0', '1'])->default('0')->index('logged_in');
            $table->enum('is_admin', ['0', '1'])->nullable()->default('0')->index('is_admin');
            $table->dateTime('created_at')->index('created_at');
            $table->dateTime('updated_at')->nullable()->index('updated_at');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('sessions');
    }
}
