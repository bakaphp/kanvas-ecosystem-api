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
        Schema::table('receiver_webhooks', function (Blueprint $table) {
            $table->index('apps_id');
            $table->index('created_at');
            $table->index('updated_at');
        });

        Schema::table('receiver_webhook_calls', function (Blueprint $table) {
            $table->index('created_at');
            $table->index('updated_at');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('receiver_webhooks', function (Blueprint $table) {
            $table->dropIndex(['apps_id']);
        });

        Schema::table('receiver_webhook_calls', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
            $table->dropIndex(['updated_at']);
        });
    }
};
