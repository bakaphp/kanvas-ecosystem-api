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
            //
            // Who the customer on the other side of this message is. `users_id` is NOT NULL and
            // always the INTERNAL actor — for inbound it is the receiver webhook's configured user,
            // not the person who wrote the message — so before this column there was no way to
            // answer "who is talking" without joining app_module_message -> leads -> people_id
            // across the social/crm connection boundary.
            //
            // Set only on real communication messages (the ones that get a sender_type) — an
            // internal note or system row attached to a lead is not a message with a customer.
            // NULL = no person on the other side (social post, comment, ai-chat, note, system row).
            //
            // Deliberately NOT a foreign key: `messages` lives on the `social` connection and
            // `peoples` on `crm`, so MySQL cannot enforce it across databases. It is a soft
            // reference by necessity — do not "fix" this by adding a constraint.
            $table->unsignedBigInteger('people_id')->nullable()->after('users_id');

            $table->index(
                ['apps_id', 'companies_id', 'people_id', 'created_at'],
                'ix_messages_people_id',
            );
        });
    }

    public function down(): void
    {
        Schema::connection('social')->table('messages', function (Blueprint $table) {
            $table->dropIndex('ix_messages_people_id');
            $table->dropColumn('people_id');
        });
    }
};
