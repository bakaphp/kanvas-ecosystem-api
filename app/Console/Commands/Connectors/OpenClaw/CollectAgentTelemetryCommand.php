<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\OpenClaw;

use Illuminate\Console\Command;
use Kanvas\Connectors\OpenClaw\Services\AgentTelemetryService;

class CollectAgentTelemetryCommand extends Command
{
    protected $signature = 'kanvas-openclaw:telemetry-collect';

    protected $description = 'Dispatch a CollectAgentTelemetryJob for every running OpenClaw deployment';

    public function handle(AgentTelemetryService $service): int
    {
        $service->collect();

        return self::SUCCESS;
    }
}
