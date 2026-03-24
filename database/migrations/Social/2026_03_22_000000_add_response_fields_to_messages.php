<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddResponseFieldsToMessages extends Migration
{
    public function up(): void
    {
        Schema::connection('social')->table('messages', function (Blueprint $table) {
            $table->boolean('un_response')->default(false)->after('is_premium');
            $table->unsignedBigInteger('response_message_id')->nullable()->after('un_response');

            $table->index('un_response', 'idx_messages_un_response');
            $table->index('response_message_id', 'idx_messages_response_message_id');
        });
    }

    public function down(): void
    {
        Schema::connection('social')->table('messages', function (Blueprint $table) {
            $table->dropIndex('idx_messages_un_response');
            $table->dropIndex('idx_messages_response_message_id');
            $table->dropColumn(['un_response', 'response_message_id']);
        });
    }
}
