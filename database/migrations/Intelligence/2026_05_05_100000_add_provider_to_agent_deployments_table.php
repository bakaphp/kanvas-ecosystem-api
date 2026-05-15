<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('agent_deployments', function (Blueprint $table) {
            $table->string('provider')->default('openclaw')->after('container_name');
        });
    }

    public function down(): void
    {
        Schema::table('agent_deployments', function (Blueprint $table) {
            $table->dropColumn('provider');
        });
    }
};
