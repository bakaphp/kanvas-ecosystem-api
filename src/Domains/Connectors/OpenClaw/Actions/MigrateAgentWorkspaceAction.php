<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenClaw\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\OpenClaw\Enums\CustomFieldEnum;
use Kanvas\Connectors\OpenClaw\Enums\DeploymentStatusEnum;
use Kanvas\Connectors\OpenClaw\SshClient;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use Kanvas\Intelligence\Agents\Models\AgentMachine;
use Throwable;

/**
 * Migrate an agent's workspace from one machine to another by archiving
 * the source ~/.openclaw directory with tar, transferring via PHP memory,
 * extracting on the destination, and starting the Docker containers.
 *
 * The source deployment remains untouched. A new (or reused) AgentDeployment
 * record is created for the destination machine.
 */
class MigrateAgentWorkspaceAction
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

    public function execute(): AgentDeployment
    {
        $agent = $this->sourceDeployment->agent;
        $timestamp = date('Ymd_His');
        $archiveName = 'openclaw_agents_' . $timestamp . '.tar.gz';
        $remoteArchive = '/tmp/' . $archiveName;
        $localTempFile = sys_get_temp_dir() . '/' . $archiveName;

        $sourceClient = SshClient::fromMachine($this->sourceDeployment->machine);

        try {
            $this->packWorkspace($sourceClient, $remoteArchive, $localTempFile);
        } finally {
            $sourceClient->disconnect();
        }

        $systemUser = 'agent-' . $agent->slug;
        $ports = $this->destinationMachine->allocatePortPair();

        $destDeployment = AgentDeployment::where('agent_machine_id', $this->destinationMachine->getId())
            ->where('system_user', $systemUser)
            ->where('is_deleted', 0)
            ->first();

        if ($destDeployment) {
            $destDeployment->status = DeploymentStatusEnum::PROVISIONING->value;
            $destDeployment->error_message = null;
            $destDeployment->gateway_port = $ports['gateway_port'];
            $destDeployment->proxy_port = $ports['proxy_port'];
            $destDeployment->saveOrFail();
        } else {
            $destDeployment = new AgentDeployment();
            $destDeployment->apps_id = $this->app->getId();
            $destDeployment->companies_id = $this->company->getId();
            $destDeployment->agent_id = $agent->getId();
            $destDeployment->agent_machine_id = $this->destinationMachine->getId();
            $destDeployment->system_user = $systemUser;
            $destDeployment->home_directory = '/home/' . $systemUser;
            $destDeployment->gateway_port = $ports['gateway_port'];
            $destDeployment->proxy_port = $ports['proxy_port'];
            $destDeployment->container_name = 'openclaw-' . $agent->slug;
            $destDeployment->status = DeploymentStatusEnum::PROVISIONING->value;
            $destDeployment->saveOrFail();
        }

        $destClient = SshClient::fromMachine($this->destinationMachine);

        try {
            $this->provisionUser($destClient, $systemUser);
            $this->extractWorkspace($destClient, $localTempFile, $remoteArchive, $destDeployment);
            $this->startContainers($destClient, $destDeployment);

            $destDeployment->status = DeploymentStatusEnum::RUNNING->value;
            $destDeployment->launched_at = now();
            $destDeployment->saveOrFail();

            $agent->set(CustomFieldEnum::OPENCLAW_DEPLOYMENT_ID->value, $destDeployment->getId());
        } catch (Throwable $e) {
            $destDeployment->status = DeploymentStatusEnum::FAILED->value;
            $destDeployment->error_message = $e->getMessage();
            $destDeployment->saveOrFail();

            throw $e;
        } finally {
            $destClient->disconnect();

            if (file_exists($localTempFile)) {
                unlink($localTempFile);
            }
        }

        return $destDeployment;
    }

    /**
     * Create a tar.gz of the source workspace directory and stream it to a local temp file.
     *
     * Mirrors the bash script: splits the source path into parent (-C) and basename,
     * so the archive root contains only the workspace directory itself.
     * Streams via SFTP to avoid loading potentially large archives into PHP memory.
     */
    private function packWorkspace(SshClient $client, string $remoteArchive, string $localTempFile): void
    {
        $sourceDir = $this->sourcePath ?? ($this->sourceDeployment->home_directory . '/.openclaw');
        $parentDir = dirname($sourceDir);
        $dirName = basename($sourceDir);

        $result = $client->exec(
            'tar -czf ' . escapeshellarg($remoteArchive)
            . ' -C ' . escapeshellarg($parentDir)
            . ' ' . escapeshellarg($dirName) . ' 2>&1'
            . '; echo "EXIT_CODE:$?"',
            60
        );

        if (str_contains($result, 'EXIT_CODE:1')) {
            throw new ValidationException('Failed to create workspace archive on source: ' . $result);
        }

        if (! $client->downloadToFile($remoteArchive, $localTempFile)) {
            throw new ValidationException('Failed to download workspace archive from source');
        }

        $client->exec('rm -f ' . escapeshellarg($remoteArchive));
    }

    /**
     * Ensure the agent Linux user exists on the destination and is in the docker group.
     */
    private function provisionUser(SshClient $client, string $systemUser): void
    {
        $client->exec(
            'id ' . escapeshellarg($systemUser) . ' &>/dev/null'
            . ' || sudo useradd -m -s /bin/bash ' . escapeshellarg($systemUser)
        );
        $client->exec('sudo usermod -aG docker ' . escapeshellarg($systemUser));
    }

    /**
     * Stream the local archive to the destination via SFTP, extract it, and fix
     * ownership so Docker's node user (UID 1000) can read/write the files.
     *
     * Streams from $localTempFile to avoid loading the archive into PHP memory.
     * The extract root defaults to the deployment's home directory but can be
     * overridden via $destinationPath for servers with custom layouts.
     */
    private function extractWorkspace(
        SshClient $client,
        string $localTempFile,
        string $remoteArchive,
        AgentDeployment $deployment,
    ): void {
        $extractRoot = $this->destinationPath ?? $deployment->home_directory;

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
            'sudo chown -R 1000:1000 '
            . escapeshellarg($extractRoot . '/.openclaw')
        );

        $client->exec('rm -f ' . escapeshellarg($remoteArchive));
    }

    /**
     * Start the Docker containers from the migrated docker-compose.yml without rebuilding.
     */
    private function startContainers(SshClient $client, AgentDeployment $deployment): void
    {
        $openclawDir = $deployment->home_directory . '/.openclaw';

        $result = $client->exec(
            'sudo -u ' . escapeshellarg($deployment->system_user)
            . ' bash -c ' . escapeshellarg('cd ' . $openclawDir . ' && docker compose up -d 2>&1')
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
}
