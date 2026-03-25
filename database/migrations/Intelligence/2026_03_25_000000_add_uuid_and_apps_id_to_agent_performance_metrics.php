<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('intelligence')->table('agent_performance_metrics', function (Blueprint $table) {
            $table->charUuid('uuid')->nullable()->after('id')->index();
            $table->unsignedBigInteger('apps_id')->after('id')->index();
        });
    }

    public function down(): void
    {
        Schema::connection('intelligence')->table('agent_performance_metrics', function (Blueprint $table) {
            $table->dropColumn(['uuid', 'apps_id']);
        });
    }
};
