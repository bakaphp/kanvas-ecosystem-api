<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    protected $connection = 'intelligence';

    public function up(): void
    {
        Schema::create('agent_daily_cycles', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('apps_id');
            $table->unsignedBigInteger('companies_id');
            $table->unsignedBigInteger('agent_id');
            $table->date('cycle_date');

            $table->timestamp('awake_started_at')->nullable();
            $table->timestamp('awake_ended_at')->nullable();
            $table->timestamp('sleep_started_at')->nullable();
            $table->timestamp('sleep_ended_at')->nullable();
            $table->unsignedInteger('awake_duration_minutes')->default(0);
            $table->unsignedInteger('sleep_duration_minutes')->default(0);

            $table->unsignedInteger('proactive_actions_count')->default(0);
            $table->unsignedInteger('events_processed_count')->default(0);

            $table->text('morning_briefing')->nullable();
            $table->json('proposed_actions')->nullable();
            $table->json('skills_emerged')->nullable();
            $table->decimal('self_improvement_score', 5, 3)->default(0);
            $table->string('signed_by_text', 255)->nullable();

            $table->boolean('is_deleted')->default(false);
            $table->timestamps();

            $table->foreign('agent_id')->references('id')->on('agents')->onDelete('cascade');
            $table->unique(['agent_id', 'cycle_date'], 'uniq_agent_cycle_date');
            $table->index(['apps_id', 'companies_id', 'cycle_date'], 'idx_tenant_cycle_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_daily_cycles');
    }
};
