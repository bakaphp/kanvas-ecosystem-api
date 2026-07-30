<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('agent_deployments', function (Blueprint $table) {
            $table->unsignedInteger('health_check_failures')->default(0)->after('last_health_check');
        });
    }

    public function down(): void
    {
        Schema::table('agent_deployments', function (Blueprint $table) {
            $table->dropColumn('health_check_failures');
        });
    }
};
