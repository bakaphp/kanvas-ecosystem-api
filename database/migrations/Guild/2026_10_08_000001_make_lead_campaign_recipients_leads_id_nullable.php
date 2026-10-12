<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('crm')->table('lead_campaign_recipients', function (Blueprint $table) {
            $table->unsignedBigInteger('leads_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::connection('crm')->table('lead_campaign_recipients', function (Blueprint $table) {
            $table->unsignedBigInteger('leads_id')->nullable(false)->change();
        });
    }
};
