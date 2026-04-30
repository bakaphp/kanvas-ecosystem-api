<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('agent_machines', function (Blueprint $table) {
            $table->boolean('is_connected')->default(false)->after('is_active');
            $table->timestamp('last_ping_at')->nullable()->after('is_connected');
        });
    }

    public function down(): void
    {
        Schema::table('agent_machines', function (Blueprint $table) {
            $table->dropColumn(['is_connected', 'last_ping_at']);
        });
    }
};
