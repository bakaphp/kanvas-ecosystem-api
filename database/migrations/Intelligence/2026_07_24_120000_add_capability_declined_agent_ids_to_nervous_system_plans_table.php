<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    protected $connection = 'intelligence';

    public function up(): void
    {
        Schema::connection('intelligence')->table('nervous_system_plans', function (Blueprint $table) {
            // Agents that blocked this plan for a capability reason ("I have no tool for this"). The PM
            // must not re-hand the plan to one of them — it forces escalation to a different agent/human
            // instead of the re-block loop (NS-6909).
            $table->json('capability_declined_agent_ids')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::connection('intelligence')->table('nervous_system_plans', function (Blueprint $table) {
            $table->dropColumn('capability_declined_agent_ids');
        });
    }
};
