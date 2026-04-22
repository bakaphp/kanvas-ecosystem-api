<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('crm')->table('leads', function (Blueprint $table) {
            $table->bigInteger('leads_sub_sources_id')->nullable()->default(null)->index()->after('leads_sources_id');
        });
    }

    public function down(): void
    {
        Schema::connection('crm')->table('leads', function (Blueprint $table) {
            $table->dropColumn('leads_sub_sources_id');
        });
    }
};
