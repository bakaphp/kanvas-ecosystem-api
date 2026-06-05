<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    protected $connection = 'intelligence';

    public function up(): void
    {
        Schema::create('agent_swarm_budgets', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedInteger('apps_id');
            $table->unsignedInteger('companies_id');
            $table->unsignedBigInteger('agent_swarm_id');

            $table->enum('period', ['daily', 'weekly', 'monthly'])->default('monthly');
            $table->decimal('monthly_cost_cap_usd', 12, 2)->nullable();
            $table->unsignedBigInteger('monthly_token_cap')->nullable();
            $table->unsignedInteger('monthly_task_cap')->nullable();
            $table->boolean('hard_stop_at_cap')->default(false);
            $table->unsignedTinyInteger('warn_at_pct')->default(80);
            $table->unsignedTinyInteger('period_resets_on')->default(1);
            $table->unsignedTinyInteger('last_warned_at_pct')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_deleted')->default(false);
            $table->timestamps();

            $table->unique(['agent_swarm_id', 'period', 'is_deleted'], 'uniq_swarm_budget_period');
            $table->index(['apps_id', 'companies_id'], 'idx_tenant_budget');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_swarm_budgets');
    }
};
