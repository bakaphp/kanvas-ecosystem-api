<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('social')->table('messages', function (Blueprint $table) {
            // Nullable, no default -> INSTANT DDL on Aurora MySQL 3 / MySQL 8 (no table rebuild).
            // NULL = non-communication row (social post/comment); populated for SMS/email/whatsapp.
            $table->string('sender_type', 16)->nullable()->after('users_id');

            $table->index(
                ['apps_id', 'companies_id', 'is_deleted', 'sender_type', 'created_at'],
                'ix_messages_sender_type',
            );
        });
    }

    public function down(): void
    {
        Schema::connection('social')->table('messages', function (Blueprint $table) {
            $table->dropIndex('ix_messages_sender_type');
            $table->dropColumn('sender_type');
        });
    }
};
