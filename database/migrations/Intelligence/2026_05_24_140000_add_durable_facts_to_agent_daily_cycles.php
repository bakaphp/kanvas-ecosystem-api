<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stores the LLM-produced durable_facts list alongside the human-facing
 * briefing on AgentDailyCycle. The same list is also pushed to the agent's
 * runtime memory bank (MEMORY.md for Hermes), but persisting it on the
 * Kanvas side lets the daily digest email replay what was written and lets
 * operators audit-trail what landed in agent memory without SSHing.
 */
return new class () extends Migration {
    protected $connection = 'intelligence';

    public function up(): void
    {
        Schema::table('agent_daily_cycles', function (Blueprint $table) {
            $table->json('durable_facts')->nullable()->after('skills_emerged');
        });
    }

    public function down(): void
    {
        Schema::table('agent_daily_cycles', function (Blueprint $table) {
            $table->dropColumn('durable_facts');
        });
    }
};
