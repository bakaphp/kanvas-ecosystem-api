<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('crm')->table('leads_receivers', function (Blueprint $table) {
            $table->string('notification_email')->nullable()->after('source_name');
        });
    }

    public function down(): void
    {
        Schema::connection('crm')->table('leads_receivers', function (Blueprint $table) {
            $table->dropColumn('notification_email');
        });
    }
};
