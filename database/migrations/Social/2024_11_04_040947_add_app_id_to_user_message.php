<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('user_messages', function (Blueprint $table) {
            // Add apps_id column
            $table->unsignedBigInteger('apps_id')->after('users_id')->nullable();

            // Drop the auto-increment id column since we have a composite primary key
            $table->dropColumn('id');

            // Add single column index
            $table->index('apps_id');

            // Add relationship-based compound indexes
            $table->index(['messages_id', 'users_id', 'apps_id'], 'message_user_app_index');
            $table->index(['users_id', 'apps_id'], 'user_app_index');
            $table->index(['messages_id', 'apps_id'], 'message_app_index');

            // Add query pattern compound indexes
            $table->index(['users_id', 'created_at'], 'user_messages_user_time_index');
            $table->index(['apps_id', 'created_at'], 'user_messages_app_time_index');
            $table->index(['users_id', 'is_saved', 'created_at'], 'user_messages_user_saved_time_index');
            $table->index(['users_id', 'is_liked', 'created_at'], 'user_messages_user_liked_time_index');
            $table->index(['users_id', 'is_shared', 'created_at'], 'user_messages_user_shared_time_index');
            $table->index(['users_id', 'is_deleted'], 'user_messages_user_deleted_index');
            $table->index(['apps_id', 'is_deleted'], 'user_messages_app_deleted_index');
        });

        // Update apps_id from messages table
        DB::statement('
            UPDATE user_messages um
            INNER JOIN messages m ON um.messages_id = m.id
            SET um.apps_id = m.apps_id
        ');

        // Make apps_id not nullable after populating data
        Schema::table('user_messages', function (Blueprint $table) {
            //   $table->unsignedBigInteger('apps_id')->nullable(false)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_messages', function (Blueprint $table) {
            // Drop all new indexes
            $table->dropIndex('message_user_app_index');
            $table->dropIndex('user_app_index');
            $table->dropIndex('message_app_index');

            $table->dropIndex(['apps_id']);
            $table->dropIndex('user_messages_user_time_index');
            $table->dropIndex('user_messages_app_time_index');
            $table->dropIndex('user_messages_user_saved_time_index');
            $table->dropIndex('user_messages_user_liked_time_index');
            $table->dropIndex('user_messages_user_shared_time_index');
            $table->dropIndex('user_messages_user_deleted_index');
            $table->dropIndex('user_messages_app_deleted_index');

            // Drop apps_id column
            $table->dropColumn('apps_id');

            // Recreate the id column
            $table->integer('id')->autoIncrement();
        });
    }
};
