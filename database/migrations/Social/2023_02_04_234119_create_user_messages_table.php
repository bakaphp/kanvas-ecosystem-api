<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateUserMessagesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::connection('social')->create('user_messages', function (Blueprint $table) {
            $table->char('messages_id', 36);
            $table->bigInteger('users_id');
            $table->longText('notes')->nullable();
            $table->text('activities')->nullable();
            $table->boolean('is_liked')->default(false)->index('is_liked');
            $table->boolean('is_saved')->default(false)->index('is_saved');
            $table->boolean('is_shared')->default(false)->index('is_shared');
            $table->boolean('is_reported')->default(false)->index('is_reported');
            $table->longText('reactions')->nullable()->index('reactions');
            $table->longText('saved_lists')->nullable()->index('saved_lists');
            $table->dateTime('created_at')->index('created_at');
            $table->tinyInteger('is_deleted')->default(0)->index('is_deleted');

            $table->primary(['messages_id', 'users_id']);
            $table->index(['users_id', 'is_deleted'], 'users_id');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::connection('social')->dropIfExists('user_messages');
    }
}
