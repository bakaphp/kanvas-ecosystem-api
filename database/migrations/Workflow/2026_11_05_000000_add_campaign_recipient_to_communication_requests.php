<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('communication_requests', function (Blueprint $table) {
            // The lead_campaign_recipients slot a campaign send was for. That table lives on crm, so
            // a campaign's delivered/failed counts are read from here instead of copied onto it.
            $table->unsignedBigInteger('campaign_recipient_id')->nullable()->after('filed_message_id');
            $table->index('campaign_recipient_id', 'communication_requests_campaign_recipient_index');
        });
    }

    public function down(): void
    {
        Schema::table('communication_requests', function (Blueprint $table) {
            $table->dropIndex('communication_requests_campaign_recipient_index');
            $table->dropColumn('campaign_recipient_id');
        });
    }
};
