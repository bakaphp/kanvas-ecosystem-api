<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        // Workflow's move_agent_rules_to_agent_listeners runs first and adds it on an upgraded database.
        if (Schema::connection('intelligence')->hasColumn('agents', 'reach_out_config')) {
            return;
        }

        Schema::connection('intelligence')->table('agents', function (Blueprint $table) {
            $table->json('reach_out_config')->nullable()->after('whatsapp_config');
        });
    }

    public function down(): void
    {
        Schema::connection('intelligence')->table('agents', function (Blueprint $table) {
            $table->dropColumn('reach_out_config');
        });
    }
};
