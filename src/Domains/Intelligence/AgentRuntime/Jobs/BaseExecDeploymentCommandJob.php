<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kanvas\Intelligence\AgentRuntime\Events\DeploymentCommandCompletedEvent;
use Kanvas\Intelligence\AgentRuntime\Events\DeploymentCommandOutputEvent;
use Kanvas\Intelligence\AgentRuntime\SshClient;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use Kanvas\Intelligence\Agents\Models\AgentMachine;
use Throwable;

/**
 * Provider-agnostic exec-command worker. Streams output back to the caller's WebSocket
 * channel via {@see DeploymentCommandOutputEvent}, then emits a single completion event.
 *
 * Subclasses only supply the SshClient factory — `mjsPath` from ProviderConfig handles the
 * `node /app/openclaw.mjs` vs `node /app/hermes.mjs` difference automatically.
 */
abstract class BaseExecDeploymentCommandJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Pusher caps message payloads at 10KB. JSON encoding inflates raw text
     * (unicode escapes, newlines, quotes), so we chunk conservatively at 4KB
     * to leave room for the envelope.
     */
    private const int MAX_CHUNK_BYTES = 4096;

    public function __construct(
        protected AgentDeployment $deployment,
        protected string $command,
        protected string $sessionId,
    ) {
        $this->onQueue('agent-runtime');
    }

    abstract protected function createSshClient(AgentMachine $machine): SshClient;

    public function handle(): void
    {
        $client = $this->createSshClient($this->deployment->machine);

        try {
            $providerConfig = $client::makeProviderConfig();

            $output = $client->exec(
                'docker exec ' . escapeshellarg($this->deployment->container_name)
                . ' ' . $providerConfig->mjsPath . ' ' . $this->command
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
            report($e);
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
