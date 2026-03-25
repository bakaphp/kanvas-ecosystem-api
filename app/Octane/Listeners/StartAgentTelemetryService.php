<?php

declare(strict_types=1);

namespace App\Octane\Listeners;

use Kanvas\Connectors\OpenClaw\Services\AgentTelemetryService;
use Laravel\Octane\Events\WorkerStarting;

class StartAgentTelemetryService
{
    public function handle(WorkerStarting $event): void
    {
        app(AgentTelemetryService::class)->start();
    }
}
