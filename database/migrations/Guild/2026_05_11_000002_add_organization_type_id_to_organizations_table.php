<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('crm')->table('organizations', function (Blueprint $table) {
            $table->bigInteger('organization_type_id')->nullable()->after('companies_id')->index('organization_type_id');
        });
    }

    public function down(): void
    {
        Schema::connection('crm')->table('organizations', function (Blueprint $table) {
            $table->dropColumn('organization_type_id');
        });
    }
};
