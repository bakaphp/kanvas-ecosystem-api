<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('crm')->table('deals', function (Blueprint $table) {
            $table->bigInteger('leads_id')->nullable()->default(null)->index()->after('companies_id');
            $table->integer('companies_branches_id')->default(0)->index()->after('companies_id');
            $table->integer('apps_id')->nullable()->default(null)->index()->after('uuid');
        });
    }

    public function down(): void
    {
        Schema::connection('crm')->table('deals', function (Blueprint $table) {
            $table->dropColumn(['leads_id', 'companies_branches_id', 'apps_id']);
        });
    }
};
