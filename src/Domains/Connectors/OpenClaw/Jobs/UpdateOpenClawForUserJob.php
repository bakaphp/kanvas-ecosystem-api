<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenClaw\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kanvas\Connectors\AgentRuntime\Enums\DeploymentStatusEnum;
use Kanvas\Connectors\AgentRuntime\Events\AgentDeploymentStatusChanged;
use Kanvas\Connectors\OpenClaw\SshClient;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use Kanvas\Intelligence\Agents\Models\AgentMachine;
use Throwable;

/**
 * Run the full OpenClaw update sequence for a single agent user on a machine:
 *  1. Pull latest images
 *  2. Rebuild the gateway container (no cache)
 *  3. Recreate all containers
 *  4. Install the auto-updater skill inside the CLI container
 *
 * Running one job per user means each installation gets its own timeout and
 * retry budget — a slow image pull on one agent won't block or fail others.
 */
class UpdateOpenClawForUserJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 1800;

    public int $tries = 2;

    public function __construct(
        protected AgentMachine $machine,
        protected string $systemUser,
        protected ?int $deploymentId = null,
    ) {
    }

    public function handle(): void
    {
        $composeFile = '/home/' . $this->systemUser . '/.openclaw/docker-compose.yml';
        $client = SshClient::fromMachine($this->machine);

        try {
            $this->runUpdate($client, $composeFile);
            $this->setDeploymentStatus(DeploymentStatusEnum::RUNNING);
        } catch (Throwable $e) {
            $this->setDeploymentStatus(DeploymentStatusEnum::FAILED, $e->getMessage());
            throw $e;
        } finally {
            $client->disconnect();
        }
    }

    private function runUpdate(SshClient $client, string $composeFile): void
    {
        $script = implode(' && ', [
            'docker pull alpine/socat 2>&1',
            'docker pull ghcr.io/phioranex/openclaw-docker:latest 2>&1',
            'docker build --no-cache -t openclaw-kanvas:latest /opt/openclaw-image 2>&1',
            'docker compose -f ' . escapeshellarg($composeFile)
                . ' up -d --force-recreate 2>&1',
        ]);

        $output = $client->exec('sudo bash -c ' . escapeshellarg($script) . '; echo "EXIT_CODE:$?"', 1800);

        if (str_contains($output, 'EXIT_CODE:1') || str_contains($output, 'Error response from daemon')) {
            throw new ValidationException(
                'OpenClaw update failed for user ' . $this->systemUser . ': ' . $output
            );
        }
    }

    private function setDeploymentStatus(DeploymentStatusEnum $status, ?string $errorMessage = null): void
    {
        if (! $this->deploymentId) {
            return;
        }

        $deployment = AgentDeployment::find($this->deploymentId);
        if (! $deployment) {
            return;
        }

        $previousStatus = $deployment->status;
        $deployment->status = $status->value;
        $deployment->error_message = $errorMessage;
        $deployment->saveOrFail();

        AgentDeploymentStatusChanged::dispatch($deployment, $previousStatus);
    }
}
