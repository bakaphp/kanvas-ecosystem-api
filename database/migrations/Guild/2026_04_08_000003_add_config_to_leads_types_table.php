<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('crm')->table('leads_types', function (Blueprint $table) {
            $table->json('config')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::connection('crm')->table('leads_types', function (Blueprint $table) {
            $table->dropColumn('config');
        });
    }
};
