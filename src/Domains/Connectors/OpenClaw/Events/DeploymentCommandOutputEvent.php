<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenClaw\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Override;

class DeploymentCommandOutputEvent implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        protected int $appsId,
        protected int $companiesId,
        protected int $deploymentId,
        protected string $sessionId,
        protected string $output,
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
        return 'terminal.output';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'deployment_id' => $this->deploymentId,
            'session_id' => $this->sessionId,
            'output' => $this->sanitizeOutput($this->output),
            'timestamp' => now()->toIso8601String(),
        ];
    }

    /**
     * Strip ANSI escape codes and ensure valid UTF-8 for JSON encoding.
     */
    protected function sanitizeOutput(string $output): string
    {
        // Strip ANSI escape sequences (colors, cursor control, etc.)
        $clean = (string) preg_replace('/\x1B\[[0-9;]*[A-Za-z]/', '', $output);
        $clean = (string) preg_replace('/\x1B\].*?\x07/', '', $clean);
        $clean = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $clean);

        // Ensure valid UTF-8
        if (! mb_check_encoding($clean, 'UTF-8')) {
            $converted = mb_convert_encoding($clean, 'UTF-8', 'UTF-8');
            $clean = is_string($converted) ? $converted : '';
        }

        return $clean;
    }
}
