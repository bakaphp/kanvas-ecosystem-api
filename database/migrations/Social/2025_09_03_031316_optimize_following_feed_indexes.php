<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class OptimizeFollowingFeedIndexes extends Migration
{
    public function up(): void
    {
        // Fix messages_id type in user_messages
        Schema::table('user_messages', function (Blueprint $table) {
            // Change column type (if needed)
            $table->unsignedBigInteger('messages_id')->change();

            // Add optimized index for message-user-app-is_deleted
            $table->index(
                ['messages_id', 'users_id', 'apps_id', 'is_deleted'],
                'idx_user_messages_msg_user_app_deleted'
            );
        });

        // Add optimized composite index to users_follows
        Schema::table('users_follows', function (Blueprint $table) {
            $table->index(
                ['users_id', 'entity_id', 'entity_namespace', 'is_deleted', 'apps_id'],
                'idx_users_follows_all'
            );
        });
    }

    public function down(): void
    {
        Schema::table('user_messages', function (Blueprint $table) {
            $table->dropIndex('idx_user_messages_msg_user_app_deleted');
            // Cannot revert column type safely — left intentionally blank
        });

        Schema::table('users_follows', function (Blueprint $table) {
            $table->dropIndex('idx_users_follows_all');
        });
    }
}
