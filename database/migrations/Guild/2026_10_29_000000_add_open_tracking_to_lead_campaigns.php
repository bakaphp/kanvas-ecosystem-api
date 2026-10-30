<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('crm')->table('lead_campaign_recipients', function (Blueprint $table) {
            $table->enum('status', ['pending', 'sent', 'opened', 'failed', 'skipped'])->default('pending')->change();
            $table->timestamp('opened_at')->nullable()->after('sent_at');
            $table->unsignedInteger('open_count')->default(0)->after('opened_at');
        });

        Schema::connection('crm')->table('lead_campaigns', function (Blueprint $table) {
            $table->unsignedInteger('opened_count')->default(0)->after('skipped_count');
        });
    }

    public function down(): void
    {
        Schema::connection('crm')->table('lead_campaigns', function (Blueprint $table) {
            $table->dropColumn('opened_count');
        });

        Schema::connection('crm')->table('lead_campaign_recipients', function (Blueprint $table) {
            $table->dropColumn(['opened_at', 'open_count']);
            $table->enum('status', ['pending', 'sent', 'failed', 'skipped'])->default('pending')->change();
        });
    }
};
