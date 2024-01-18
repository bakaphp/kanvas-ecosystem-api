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
        //remove verb and event from notification types
        Schema::table('notification_types', function (Blueprint $table) {
            $table->dropColumn('verb');
            $table->dropColumn('event');
            $table->index(['apps_id', 'key'], 'notification_types_apps_id_key_index');
            $table->index(['apps_id', 'key', 'is_published'], 'notification_types_apps_id_key_index_published');
        });

        //remove message type from notification_message_logic
        Schema::table('notification_types_message_logic', function (Blueprint $table) {
            $table->dropColumn('messages_type_id');
            $table->index(['notifications_type_id', 'apps_id'], 'notification_logic_type_id_apps_id_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //add verb and event from notification types
        Schema::table('notification_types', function (Blueprint $table) {
            $table->string('verb', 45)->nullable();
            $table->string('event', 45)->nullable();
            $table->dropIndex('notification_types_apps_id_key_index');
            $table->dropIndex('notification_types_apps_id_key_index_published');
        });

        //add message type from notification_message_logic
        Schema::table('notification_types_message_logic', function (Blueprint $table) {
            $table->integer('messages_type_id');
            $table->dropIndex('notification_logic_type_id_apps_id_index');
        });
    }
};
