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

class AgentDeploymentStatusChanged implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public AgentDeployment $deployment,
        public string $previousStatus,
    ) {
    }

    #[Override]
    public function broadcastOn(): Channel
    {
        return new Channel(
            'company-' . $this->deployment->companies_id
            . '-app-' . $this->deployment->apps_id
            . '-deployments'
        );
    }

    public function broadcastAs(): string
    {
        return 'deployment.status.changed';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'deployment_id' => $this->deployment->id,
            'agent_id' => $this->deployment->agent_id,
            'status' => $this->deployment->status,
            'previous_status' => $this->previousStatus,
            'error_message' => $this->deployment->error_message,
        ];
    }
}
