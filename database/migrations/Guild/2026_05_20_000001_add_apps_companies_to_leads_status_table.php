<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('crm')->table('leads_status', function (Blueprint $table) {
            $table->integer('apps_id')->default(0)->index()->after('id');
            $table->integer('companies_id')->default(0)->index()->after('apps_id');
        });
    }

    public function down(): void
    {
        Schema::connection('crm')->table('leads_status', function (Blueprint $table) {
            $table->dropColumn(['apps_id', 'companies_id']);
        });
    }
};
