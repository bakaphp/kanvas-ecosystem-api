<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('crm')->table('lead_campaigns', function (Blueprint $table) {
            $table->boolean('is_important')->default(false)->after('template_name');
        });
    }

    public function down(): void
    {
        Schema::connection('crm')->table('lead_campaigns', function (Blueprint $table) {
            $table->dropColumn('is_important');
        });
    }
};
