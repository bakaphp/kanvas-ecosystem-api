<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateNotificationChannelsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('notification_channels', function (Blueprint $table) {
            $table->integer('id', true);
            $table->string('name', 64);
            $table->string('slug', 64)->nullable();
            $table->dateTime('created_at');
            $table->dateTime('updated_at')->nullable();
            $table->tinyInteger('is_deleted')->default(0);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('notification_channels');
    }
}
