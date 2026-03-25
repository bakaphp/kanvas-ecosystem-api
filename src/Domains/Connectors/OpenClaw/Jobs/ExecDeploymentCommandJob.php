<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenClaw\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kanvas\Connectors\OpenClaw\Events\DeploymentCommandCompletedEvent;
use Kanvas\Connectors\OpenClaw\Events\DeploymentCommandOutputEvent;
use Kanvas\Connectors\OpenClaw\SshClient;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use Throwable;

class ExecDeploymentCommandJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        protected AgentDeployment $deployment,
        protected string $command,
        protected string $sessionId,
    ) {
    }

    /**
     * Pusher has a 10KB limit per message on the full JSON payload.
     * JSON encoding can expand text (unicode escapes, newlines, quotes),
     * so we chunk conservatively at 4KB of raw text.
     */
    private const int MAX_CHUNK_BYTES = 4096;

    public function handle(): void
    {
        $client = SshClient::fromMachine($this->deployment->machine);

        try {
            $output = $client->exec(
                'docker exec ' . escapeshellarg($this->deployment->container_name)
                . ' node /app/dist/index.js ' . $this->command
                . ' 2>&1',
                120,
            );

            $this->broadcastOutput($output);

            DeploymentCommandCompletedEvent::dispatch(
                $this->deployment->apps_id,
                $this->deployment->companies_id,
                $this->deployment->id,
                $this->sessionId,
                0,
            );
        } catch (Throwable $e) {
            DeploymentCommandCompletedEvent::dispatch(
                $this->deployment->apps_id,
                $this->deployment->companies_id,
                $this->deployment->id,
                $this->sessionId,
                1,
                $e->getMessage(),
            );
        } finally {
            $client->disconnect();
        }
    }

    protected function broadcastOutput(string $output): void
    {
        if ($output === '') {
            return;
        }

        $chunks = str_split($output, self::MAX_CHUNK_BYTES);

        foreach ($chunks as $chunk) {
            DeploymentCommandOutputEvent::dispatch(
                $this->deployment->apps_id,
                $this->deployment->companies_id,
                $this->deployment->id,
                $this->sessionId,
                $chunk,
            );
        }
    }
}
