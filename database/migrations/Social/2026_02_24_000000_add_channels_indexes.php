<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddChannelsIndexes extends Migration
{
    public function up(): void
    {
        Schema::connection('social')->table('channels', function (Blueprint $table) {
            $table->index(
                ['companies_id', 'apps_id', 'name', 'slug', 'entity_id'],
                'idx_channels_company_app_name_slug_entity'
            );
            $table->index(
                ['companies_id', 'apps_id', 'name', 'slug', 'entity_namespace', 'entity_id', 'is_deleted'],
                'idx_channels_company_app_name_slug_ns_entity_deleted'
            );
            $table->index(
                ['companies_id', 'apps_id', 'name', 'slug', 'entity_id', 'is_deleted'],
                'idx_channels_company_app_name_slug_entity_deleted'
            );
            $table->index(
                ['entity_namespace', 'entity_id'],
                'idx_channels_entity_ns_entity'
            );
        });

        Schema::connection('social')->table('channel_messages', function (Blueprint $table) {
            $table->index(
                ['messages_id', 'channel_id'],
                'idx_channel_messages_msg_channel'
            );
        });
    }

    public function down(): void
    {
        Schema::connection('social')->table('channels', function (Blueprint $table) {
            $table->dropIndex('idx_channels_company_app_name_slug_entity');
            $table->dropIndex('idx_channels_company_app_name_slug_ns_entity_deleted');
            $table->dropIndex('idx_channels_company_app_name_slug_entity_deleted');
            $table->dropIndex('idx_channels_entity_ns_entity');
        });

        Schema::connection('social')->table('channel_messages', function (Blueprint $table) {
            $table->dropIndex('idx_channel_messages_msg_channel');
        });
    }
}
