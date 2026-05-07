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
        Schema::table('users_associated_apps', function (Blueprint $table) {
            $table->integer('unread_notifications_count')->default(0)->after('configuration')->index();
            $table->integer('total_messages_count')->default(0)->after('configuration')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users_associated_apps', function (Blueprint $table) {
            $table->dropColumn('unread_notifications_count');
            $table->dropColumn('total_messages_count');
        });
    }
};
