<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create("notification_types_message_logic", function (Blueprint $table) {
            $table->id();
            $table->integer('apps_id')->index('apps_id');
            $table->integer('messages_type_id')->index('messages_type_id');
            $table->integer('notifications_type_id')->index('notifications_type_id');
            $table->json('logic');
            $table->dateTime('created_at')->index('created_at');
            $table->dateTime('updated_at')->nullable()->index('updated_at');
            $table->tinyInteger('is_deleted')->default(0)->index('is_deleted');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notification_types_message_logic');
    }
};
