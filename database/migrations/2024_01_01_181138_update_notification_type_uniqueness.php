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
        //unique notificatyion_type_id plus channel_id
        Schema::table('notification_type_channels', function (Blueprint $table) {
            $table->unique(['notification_type_id', 'notification_channel_id'], 'notification_type_channel_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('notification_type_channels', function (Blueprint $table) {
            $table->dropIndex('notification_type_channel_unique');
        });
    }
};
