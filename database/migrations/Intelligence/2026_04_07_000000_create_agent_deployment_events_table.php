<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('agent_deployment_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('deployment_id')->index();
            $table->string('event_type')->index();   // gateway_down, gateway_up, health_fail, health_recover, session_started, agent_unreachable
            $table->json('payload')->nullable();      // previous + current values that triggered the event
            $table->timestamp('occurred_at')->useCurrent()->index();

            $table->foreign('deployment_id')
                ->references('id')
                ->on('agent_deployments')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_deployment_events');
    }
};
