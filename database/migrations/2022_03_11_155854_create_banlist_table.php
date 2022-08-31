<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateBanlistTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('banlist', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('users_id')->default(0)->index('users_id');
            $table->string('ip', 35)->default('');
            $table->string('email')->nullable()->index('email');

            $table->index(['ip', 'users_id'], 'ban_ip_user_id');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('banlist');
    }
}
