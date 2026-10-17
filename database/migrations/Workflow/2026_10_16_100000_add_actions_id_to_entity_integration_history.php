<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('workflow')->table('entity_integration_history', function (Blueprint $table) {
            $table->unsignedBigInteger('actions_id')->nullable()->after('rules_id')->index();
        });
    }

    public function down(): void
    {
        Schema::connection('workflow')->table('entity_integration_history', function (Blueprint $table) {
            $table->dropIndex(['actions_id']);
            $table->dropColumn('actions_id');
        });
    }
};
