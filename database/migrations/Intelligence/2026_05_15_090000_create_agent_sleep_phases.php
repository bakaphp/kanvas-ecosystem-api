<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    protected $connection = 'intelligence';

    public function up(): void
    {
        Schema::create('agent_sleep_phases', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('apps_id');
            $table->unsignedBigInteger('agent_daily_cycle_id');
            $table->unsignedBigInteger('agent_id');
            $table->enum('phase', ['light', 'deep', 'dream_rem']);
            $table->timestamp('started_at');
            $table->timestamp('ended_at');
            $table->unsignedInteger('duration_minutes');
            $table->string('outcome_summary', 500)->nullable();
            $table->json('payload')->nullable();
            $table->boolean('is_deleted')->default(false);
            $table->timestamps();

            $table->foreign('agent_daily_cycle_id', 'fk_sleep_phase_cycle')
                ->references('id')
                ->on('agent_daily_cycles')
                ->onDelete('cascade');
            $table->index(['agent_id', 'started_at'], 'idx_agent_phase_started');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_sleep_phases');
    }
};
