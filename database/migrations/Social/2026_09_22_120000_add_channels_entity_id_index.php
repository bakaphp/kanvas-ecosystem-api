<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Every channels composite leads with companies_id/apps_id and idx_channels_entity_ns_entity
     * leads with entity_namespace. A subquery that only knows entity_id matches none of them, which
     * forced the message-by-channel-entity lookups to drive from `messages` instead of `channels`.
     */
    public function up(): void
    {
        if (Schema::connection('social')->hasIndex('channels', 'idx_channels_entity_id')) {
            return;
        }

        Schema::connection('social')->table('channels', function (Blueprint $table) {
            $table->index('entity_id', 'idx_channels_entity_id');
        });
    }

    public function down(): void
    {
        if (! Schema::connection('social')->hasIndex('channels', 'idx_channels_entity_id')) {
            return;
        }

        Schema::connection('social')->table('channels', function (Blueprint $table) {
            $table->dropIndex('idx_channels_entity_id');
        });
    }
};
