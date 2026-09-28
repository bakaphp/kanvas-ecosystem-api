<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('workflow')->table('entity_integration_history', function (Blueprint $table) {
            $table->longText('input')->nullable()->after('rules_id');
        });
    }

    public function down(): void
    {
        Schema::connection('workflow')->table('entity_integration_history', function (Blueprint $table) {
            $table->dropColumn('input');
        });
    }
};
