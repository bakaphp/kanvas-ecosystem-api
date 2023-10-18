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
            $table->bigInteger('users_lists_id')->unsigned();
            $table->bigInteger('messages_id');
            $table->integer('weight')->default(0);
            $table->boolean('is_deleted')->default(false)->index('is_deleted');
            $table->softDeletes();
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
