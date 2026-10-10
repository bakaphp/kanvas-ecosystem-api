<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('crm')->table('lead_campaigns', function (Blueprint $table) {
            $table->string('template_name')->nullable()->after('subject');
        });
    }

    public function down(): void
    {
        Schema::connection('crm')->table('lead_campaigns', function (Blueprint $table) {
            $table->dropColumn('template_name');
        });
    }
};
