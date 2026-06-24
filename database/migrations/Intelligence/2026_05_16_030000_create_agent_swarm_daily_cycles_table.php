<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    protected $connection = 'intelligence';

    public function up(): void
    {
        Schema::create('agent_swarm_daily_cycles', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedInteger('apps_id');
            $table->unsignedInteger('companies_id');
            $table->unsignedBigInteger('agent_swarm_id');
            $table->date('cycle_date');
            $table->dateTime('generated_at');

            $table->unsignedInteger('members_active_count')->default(0);
            $table->unsignedInteger('members_idle_count')->default(0);
            $table->unsignedInteger('events_processed_count')->default(0);
            $table->unsignedInteger('proactive_actions_count')->default(0);

            $table->decimal('mission_progress_pct', 5, 2)->nullable();
            $table->decimal('progress_delta_since_yesterday', 5, 2)->nullable();

            $table->text('bottleneck_summary')->nullable();
            $table->json('proposed_options')->nullable();
            $table->json('emergent_patterns')->nullable();
            $table->text('briefing_text')->nullable();
            $table->string('signed_by_text', 255)->nullable();
            $table->decimal('self_improvement_score', 4, 3)->nullable();

            $table->boolean('is_deleted')->default(false);
            $table->timestamps();

            $table->unique(['agent_swarm_id', 'cycle_date'], 'uniq_swarm_cycle');
            $table->index(['apps_id', 'companies_id', 'cycle_date'], 'idx_tenant_cycle_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_swarm_daily_cycles');
    }
};
