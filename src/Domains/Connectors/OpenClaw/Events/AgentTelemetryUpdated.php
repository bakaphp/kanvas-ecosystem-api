<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenClaw\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use Override;

class AgentTelemetryUpdated implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public AgentDeployment $deployment,
        public array $telemetry,
        public string $collectedAt,
    ) {
    }

    #[Override]
    public function broadcastOn(): Channel
    {
        return new Channel(
            'company-' . $this->deployment->companies_id
            . '-app-' . $this->deployment->apps_id
            . '-agent-telemetry'
        );
    }

    public function broadcastAs(): string
    {
        return 'agent.telemetry.updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return $this->telemetry;
    }
}
