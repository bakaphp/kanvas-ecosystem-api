<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenClaw\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Override;

class DeploymentCommandCompletedEvent implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        protected int $appsId,
        protected int $companiesId,
        protected int $deploymentId,
        protected string $sessionId,
        protected int $exitCode,
        protected ?string $error = null,
    ) {
    }

    #[Override]
    public function broadcastOn(): Channel
    {
        return new Channel(
            'terminal-' . $this->appsId . '-' . $this->companiesId . '-' . $this->sessionId
        );
    }

    public function broadcastAs(): string
    {
        return 'terminal.completed';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'deployment_id' => $this->deploymentId,
            'session_id' => $this->sessionId,
            'exit_code' => $this->exitCode,
            'error' => $this->error,
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
