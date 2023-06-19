<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::connection('social')->create('users_lists_messages', function (Blueprint $table) {
            $table->id();
            $table->integer('users_lists_id');
            $table->bigInteger('messages_id');
            $table->boolean('is_deleted')->default(false)->index('is_deleted');
            $table->foreign('users_lists_id')->references('id')->on('users_lists');
            $table->foreign('messages_id')->references('id')->on('messages');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users_lists_messages');
    }
};
