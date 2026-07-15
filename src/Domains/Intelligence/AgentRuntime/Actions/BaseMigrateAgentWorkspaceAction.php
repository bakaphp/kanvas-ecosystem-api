<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\AgentRuntime\Contracts\ProviderConfig;
use Kanvas\Intelligence\AgentRuntime\Enums\DeploymentStatusEnum;
use Kanvas\Intelligence\AgentRuntime\Notifications\AgentMigrationNotification;
use Kanvas\Intelligence\AgentRuntime\Services\AgentChannelIntegrationReadinessService;
use Kanvas\Intelligence\AgentRuntime\Services\BaseDockerComposeBuilderService;
use Kanvas\Intelligence\AgentRuntime\SshClient;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use Kanvas\Intelligence\Agents\Models\AgentMachine;
use Kanvas\Users\Models\Users;
use Throwable;

// Same-runtime workspace move (e.g. OpenClaw on machine A → OpenClaw on machine B).
// Cross-runtime is a different operation — see AgentRuntimeProvider::dispatchAdoptForeignDeployment.
abstract class BaseMigrateAgentWorkspaceAction
{
    public function __construct(
        protected AgentDeployment $sourceDeployment,
        protected AgentMachine $destinationMachine,
        protected AppInterface $app,
        protected CompanyInterface $company,
        protected ?string $sourcePath = null,
        protected ?string $destinationPath = null,
    ) {
    }

    abstract protected function createSshClient(AgentMachine $machine): SshClient;

    abstract protected function getDockerComposeBuilder(): BaseDockerComposeBuilderService;

    public function execute(): AgentDeployment
    {
        $agent = $this->sourceDeployment->agent;
        $sourceClient = $this->createSshClient($this->sourceDeployment->machine);
        $providerConfig = $sourceClient::makeProviderConfig();

        new AgentChannelIntegrationReadinessService()
            ->assertReadyForDeployment($agent, $providerConfig->providerName);

        $timestamp = date('Ymd_His');
        $archiveName = $providerConfig->providerName . '_agents_' . $timestamp . '.tar.gz';
        $remoteArchive = '/tmp/' . $archiveName;
        $localTempFile = sys_get_temp_dir() . '/' . $archiveName;

        try {
            $this->packWorkspace($sourceClient, $providerConfig, $remoteArchive, $localTempFile);
        } finally {
            $sourceClient->disconnect();
        }

        $agentSlug = (string) $agent->slug;
        $systemUser = 'agent-' . $agentSlug;
        $ports = $this->destinationMachine->allocatePortPair();

        $destDeployment = $this->resolveDestinationDeployment(
            $providerConfig,
            $systemUser,
            $ports,
            $agentSlug,
            $agent->getId(),
        );

        $destClient = $this->createSshClient($this->destinationMachine);

        try {
            $this->provisionUser($destClient, $systemUser);
            $this->extractWorkspace($destClient, $providerConfig, $localTempFile, $remoteArchive, $destDeployment);
            $this->startContainers($destClient, $providerConfig, $destDeployment, $agent);

            $destDeployment->status = DeploymentStatusEnum::RUNNING->value;
            $destDeployment->launched_at = now();
            $destDeployment->saveOrFail();

            $agent->set($providerConfig->deploymentIdCustomFieldKey, $destDeployment->getId());

            $this->notifyOwner($destDeployment, success: true);
        } catch (Throwable $e) {
            $destDeployment->status = DeploymentStatusEnum::FAILED->value;
            $destDeployment->error_message = $e->getMessage();
            $destDeployment->saveOrFail();

            $this->notifyOwner($destDeployment, success: false, error: $e);

            throw $e;
        } finally {
            $destClient->disconnect();

            if (file_exists($localTempFile)) {
                unlink($localTempFile);
            }
        }

        return $destDeployment;
    }

    // Split into parent (-C) + basename so the archive root contains only the dir itself,
    // not the absolute path under it.
    private function packWorkspace(
        SshClient $client,
        ProviderConfig $providerConfig,
        string $remoteArchive,
        string $localTempFile,
    ): void {
        $sourceDir = $this->sourcePath ?? ($this->sourceDeployment->home_directory . '/.' . $providerConfig->dotDir);
        $parentDir = dirname($sourceDir);
        $dirName = basename($sourceDir);

        $result = $client->exec(
            'sudo tar -czf ' . escapeshellarg($remoteArchive)
            . ' -C ' . escapeshellarg($parentDir)
            . ' ' . escapeshellarg($dirName) . ' 2>&1'
            . '; echo "EXIT_CODE:$?"',
            300
        );

        if (str_contains($result, 'EXIT_CODE:1')) {
            throw new ValidationException('Failed to create workspace archive on source: ' . $result);
        }

        try {
            if (! $client->downloadToFile($remoteArchive, $localTempFile)) {
                throw new ValidationException('Failed to download workspace archive from source');
            }
        } finally {
            $client->exec('rm -f ' . escapeshellarg($remoteArchive));
        }
    }

    // Reuse the same destination row across re-migrations so the AgentDeployment id stays stable.
    /** @param  array{gateway_port:int,proxy_port:int}  $ports */
    private function resolveDestinationDeployment(
        ProviderConfig $providerConfig,
        string $systemUser,
        array $ports,
        string $agentSlug,
        int $agentId,
    ): AgentDeployment {
        /** @var AgentDeployment|null $deployment */
        $deployment = AgentDeployment::where('agent_machine_id', $this->destinationMachine->getId())
            ->where('system_user', $systemUser)
            ->where('is_deleted', 0)
            ->first();

        if ($deployment !== null) {
            $deployment->status = DeploymentStatusEnum::PROVISIONING->value;
            $deployment->error_message = null;
            $deployment->gateway_port = $ports['gateway_port'];
            $deployment->proxy_port = $ports['proxy_port'];
            $deployment->saveOrFail();

            return $deployment;
        }

        $deployment = new AgentDeployment();
        $deployment->apps_id = $this->app->getId();
        $deployment->companies_id = $this->company->getId();
        $deployment->agent_id = $agentId;
        $deployment->agent_machine_id = $this->destinationMachine->getId();
        $deployment->system_user = $systemUser;
        $deployment->home_directory = '/home/' . $systemUser;
        $deployment->gateway_port = $ports['gateway_port'];
        $deployment->proxy_port = $ports['proxy_port'];
        $deployment->container_name = $providerConfig->containerPrefix . $agentSlug;
        $deployment->provider = $providerConfig->providerName;
        $deployment->status = DeploymentStatusEnum::PROVISIONING->value;
        $deployment->saveOrFail();

        return $deployment;
    }

    private function provisionUser(SshClient $client, string $systemUser): void
    {
        $client->exec(
            'id ' . escapeshellarg($systemUser) . ' &>/dev/null'
            . ' || sudo useradd -m -s /bin/bash ' . escapeshellarg($systemUser)
        );
        $client->exec('sudo usermod -aG docker ' . escapeshellarg($systemUser));
    }

    // Container runs as `node` (UID 1000) — match host ownership so it can read/write the
    // mounted workspace. Group stays as the agent's system user so SSH-side commands can
    // still inspect the directory.
    private function extractWorkspace(
        SshClient $client,
        ProviderConfig $providerConfig,
        string $localTempFile,
        string $remoteArchive,
        AgentDeployment $deployment,
    ): void {
        $extractRoot = $this->destinationPath ?? $deployment->home_directory;
        $providerDir = $extractRoot . '/.' . $providerConfig->dotDir;

        if (! $client->uploadFromFile($remoteArchive, $localTempFile)) {
            throw new ValidationException('Failed to upload workspace archive to destination');
        }

        $result = $client->exec(
            'sudo tar -xzf ' . escapeshellarg($remoteArchive)
            . ' -C ' . escapeshellarg($extractRoot) . ' 2>&1'
            . '; echo "EXIT_CODE:$?"',
            60
        );

        if (str_contains($result, 'EXIT_CODE:1')) {
            throw new ValidationException('Failed to extract workspace archive on destination: ' . $result);
        }

        $client->exec(
            'sudo chown -R ' . escapeshellarg($deployment->system_user . ':' . $deployment->system_user)
            . ' ' . escapeshellarg($extractRoot)
        );
        $client->exec(
            'sudo chown -R 1000:' . escapeshellarg($deployment->system_user)
            . ' ' . escapeshellarg($providerDir)
        );
        $client->exec(
            'sudo chmod -R g+rwx ' . escapeshellarg($providerDir)
        );

        $client->exec('rm -f ' . escapeshellarg($remoteArchive));
    }

    private function startContainers(
        SshClient $client,
        ProviderConfig $providerConfig,
        AgentDeployment $deployment,
        Agent $agent,
    ): void {
        $providerDir = $deployment->home_directory . '/.' . $providerConfig->dotDir;

        $builder = $this->getDockerComposeBuilder();
        $imageName = $builder->getSharedImageName($this->app);
        $imageDir = $builder->getSharedImageDir($this->app);

        $exists = $client->exec('docker image inspect ' . escapeshellarg($imageName) . ' &>/dev/null && echo "EXISTS" || echo "MISSING"');
        if (str_contains($exists, 'MISSING')) {
            // --pull checks the registry for a newer base image before building, same
            // reasoning as BaseLaunchAgentOnMachineAction::ensureSharedImage().
            $buildResult = $client->exec(
                'sudo docker build --no-cache --pull -t ' . escapeshellarg($imageName) . ' ' . escapeshellarg($imageDir) . ' 2>&1; echo "EXIT_CODE:$?"',
                900
            );
            if (! str_contains($buildResult, 'EXIT_CODE:0')) {
                throw new ValidationException('Failed to build shared image on destination: ' . $buildResult);
            }
        }

        $storedToken = $this->company->get($providerConfig->gatewayTokenConfigKey);
        $gatewayToken = is_string($storedToken) && $storedToken !== '' ? $storedToken : bin2hex(random_bytes(32));
        $composeContent = $builder->buildDockerCompose($deployment, $gatewayToken, $this->app, $agent);
        $client->writeFileAsUser($providerDir . '/docker-compose.yml', $composeContent, $deployment->system_user);

        $client->exec(
            'sudo -u ' . escapeshellarg($deployment->system_user)
            . ' bash -c ' . escapeshellarg('cd ' . $providerDir . ' && docker compose down 2>&1 || true'),
            60
        );

        $result = $client->exec(
            'sudo -u ' . escapeshellarg($deployment->system_user)
            . ' bash -c ' . escapeshellarg('cd ' . $providerDir . ' && docker compose up -d 2>&1')
            . '; echo "EXIT_CODE:$?"',
            120
        );

        if (
            str_contains($result, 'EXIT_CODE:1')
            || str_contains($result, 'Error response from daemon')
        ) {
            throw new ValidationException('Docker start failed on destination: ' . $result);
        }
    }

    private function notifyOwner(?AgentDeployment $destDeployment, bool $success, ?Throwable $error = null): void
    {
        $recipient = $this->sourceDeployment->agent?->user;
        if (! $recipient instanceof Users) {
            return;
        }

        $recipient->notify(new AgentMigrationNotification($this->sourceDeployment, $destDeployment, $success, $error));
    }
}
