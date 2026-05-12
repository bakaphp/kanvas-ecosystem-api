<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\AgentRuntime\Contracts\ProviderConfig;
use Kanvas\Intelligence\AgentRuntime\Enums\DeploymentStatusEnum;
use Kanvas\Intelligence\AgentRuntime\Events\AgentDeploymentStatusChanged;
use Kanvas\Intelligence\AgentRuntime\Services\BaseDockerComposeBuilder;
use Kanvas\Intelligence\AgentRuntime\SshClient;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use Kanvas\Intelligence\Agents\Models\AgentMachine;
use Throwable;

/**
 * Run the full provider update sequence for a single agent user on a machine:
 *  1. Rewrite the on-disk Dockerfile from the (now-pinned) template so `docker build`
 *     uses the current FROM, not whatever stale `:latest` line was deployed originally.
 *  2. Pull the pinned base image.
 *  3. Build the local image with a version-tagged ref so the tag itself records which
 *     upstream the local image was built from.
 *  4. Patch the agent's compose file `image:` line to the new versioned ref, then
 *     force-recreate. Other agents on the same machine keep their old compose +
 *     keep running their old image — old ones stay put until you update them.
 *
 * Triggered manually via the providerUpdateMachineContainers GraphQL mutation —
 * running it is an explicit operator decision to bring this machine to the
 * currently pinned version. Running one job per user means each installation gets
 * its own timeout and retry budget — a slow image pull on one agent won't block
 * or fail others.
 *
 * Concrete subclasses (UpdateOpenClawForUserJob, UpdateHermesForUserJob) supply
 * the provider-specific builder, SSH client, and ProviderConfig via the abstracts
 * below. All other behaviour is identical.
 */
abstract class BaseUpdateAgentForUserJob implements ShouldQueue
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

    abstract protected function getProviderConfig(): ProviderConfig;

    abstract protected function createBuilder(): BaseDockerComposeBuilder;

    abstract protected function createSshClient(): SshClient;

    public function handle(): void
    {
        $config = $this->getProviderConfig();
        $composeFile = '/home/' . $this->systemUser . '/.' . $config->dotDir . '/docker-compose.yml';
        $client = $this->createSshClient();

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
        $config = $this->getProviderConfig();
        $app = $this->machine->app;
        $builder = $this->createBuilder();
        $baseImage = $builder->getBaseImage($app);
        $sharedImageRef = $builder->getSharedImageName($app);
        $imageDir = $builder->getSharedImageDir($app);
        $localPrefix = $builder->getLocalImageNamePrefix();

        $client->writeFileAsUser(
            $imageDir . '/Dockerfile',
            $builder->buildDockerfile($app),
            'root',
        );

        $script = implode(' && ', [
            'docker pull alpine/socat 2>&1',
            'docker pull ' . escapeshellarg($baseImage) . ' 2>&1',
            'cd ' . escapeshellarg($imageDir)
                . ' && docker build --no-cache -t ' . escapeshellarg($sharedImageRef) . ' . 2>&1',
            // Point this agent's compose at the freshly-built versioned tag. Match any
            // existing `image: <prefix>:<anything>` line so we tolerate upgrades from
            // both legacy `:latest` and previous pinned versions. The prefix is
            // provider-specific, so we won't accidentally rewrite the cli profile's
            // upstream image line or the socat sidecar.
            "sed -i 's|image: " . $localPrefix . ':[^[:space:]]*|image: ' . $sharedImageRef . "|g' " . escapeshellarg($composeFile),
            'docker compose -f ' . escapeshellarg($composeFile)
                . ' up -d --force-recreate 2>&1',
        ]);

        $output = $client->exec('sudo bash -c ' . escapeshellarg($script) . '; echo "EXIT_CODE:$?"', 1800);

        if (str_contains($output, 'EXIT_CODE:1') || str_contains($output, 'Error response from daemon')) {
            throw new ValidationException(
                ucfirst($config->providerName) . ' update failed for user ' . $this->systemUser . ': ' . $output
            );
        }
    }

    private function setDeploymentStatus(DeploymentStatusEnum $status, ?string $errorMessage = null): void
    {
        if ($this->deploymentId === null) {
            return;
        }

        /** @var AgentDeployment|null $deployment */
        $deployment = AgentDeployment::find($this->deploymentId);
        if ($deployment === null) {
            return;
        }

        $previousStatus = $deployment->status;
        $deployment->status = $status->value;
        $deployment->error_message = $errorMessage;
        $deployment->saveOrFail();

        AgentDeploymentStatusChanged::dispatch($deployment, $previousStatus);
    }
}
