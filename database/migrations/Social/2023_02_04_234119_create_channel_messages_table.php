<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateChannelMessagesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::connection('social')->create('channel_messages', function (Blueprint $table) {
            $table->bigInteger('channel_id');
            $table->bigInteger('messages_id');
            $table->bigInteger('users_id');
            $table->dateTime('created_at')->index('created_at');
            $table->dateTime('updated_at')->nullable()->index('updated_at');
            $table->boolean('is_deleted')->default(false)->index('is_deleted');

            $table->index(['channel_id', 'messages_id', 'is_deleted'], 'channel_id_messages_id_is_deleted');
            $table->primary(['channel_id', 'messages_id', 'users_id']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::connection('social')->dropIfExists('channel_messages');
    }
}
