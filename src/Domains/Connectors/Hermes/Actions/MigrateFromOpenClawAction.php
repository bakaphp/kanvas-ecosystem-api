<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Hermes\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\AgentRuntime\Enums\DeploymentStatusEnum;
use Kanvas\Connectors\AgentRuntime\SshClient as BaseClient;
use Kanvas\Connectors\Hermes\Enums\ConfigurationEnum;
use Kanvas\Connectors\Hermes\Enums\CustomFieldEnum;
use Kanvas\Connectors\Hermes\Services\DockerComposeBuilder;
use Kanvas\Connectors\Hermes\SshClient;
use Kanvas\Connectors\OpenClaw\SshClient as OpenClawSshClient;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use Kanvas\Intelligence\Agents\Models\AgentMachine;
use Throwable;

/**
 * Migrate an agent's workspace from an OpenClaw deployment to a Hermes deployment.
 *
 * The OpenClaw source deployment remains untouched. A new (or reused)
 * AgentDeployment record is created on the destination Hermes machine.
 *
 * Same-machine flow (source machine == destination machine):
 *  1. SSH once into the shared machine
 *  2. cp -r the .openclaw directory into a staging dir — no archive round trip
 *  3. Provision user, build image, run `hermes claw migrate`, start containers
 *
 * Cross-machine flow (different machines):
 *  1. SSH into source → tar .openclaw → download to local temp file
 *  2. SSH into destination → upload archive → extract into staging dir
 *  3. Provision user, build image, run `hermes claw migrate`, start containers
 */
class MigrateFromOpenClawAction
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
        $sameMachine = $this->sourceDeployment->machine->getId() === $this->destinationMachine->getId();

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
            $destDeployment->container_name = 'hermes-' . $agent->slug;
            $destDeployment->status = DeploymentStatusEnum::PROVISIONING->value;
            $destDeployment->saveOrFail();
        }

        if ($sameMachine) {
            $this->executeOnSameMachine($destDeployment);
        } else {
            $this->executeAcrossMachines($destDeployment);
        }

        return $destDeployment;
    }

    /**
     * Same-machine path: open one SSH connection, cp -r the source .openclaw dir
     * into a staging directory, then migrate and start in place — no archive needed.
     */
    private function executeOnSameMachine(AgentDeployment $destDeployment): void
    {
        $agent = $destDeployment->agent;
        $client = SshClient::fromMachine($this->destinationMachine);

        try {
            $this->provisionUser($client, $destDeployment->system_user);
            $this->ensureSharedImage($client);

            $sourceDir = $this->sourcePath ?? ($this->sourceDeployment->home_directory . '/.openclaw');
            $stagingDir = $destDeployment->home_directory . '/.openclaw-import';

            $client->exec('sudo mkdir -p ' . escapeshellarg($stagingDir));
            $client->exec(
                'sudo cp -r ' . escapeshellarg($sourceDir) . '/. ' . escapeshellarg($stagingDir) . '/'
            );
            $client->exec(
                'sudo chown -R ' . escapeshellarg($destDeployment->system_user . ':' . $destDeployment->system_user)
                . ' ' . escapeshellarg($stagingDir)
            );

            $this->runMigrateCommand($client, $stagingDir, $destDeployment);
            $this->startContainers($client, $destDeployment);

            $destDeployment->status = DeploymentStatusEnum::RUNNING->value;
            $destDeployment->launched_at = now();
            $destDeployment->saveOrFail();

            $agent->set(CustomFieldEnum::HERMES_DEPLOYMENT_ID->value, $destDeployment->getId());

            // Stop OpenClaw containers — same machine, reuse the open connection.
            // We do NOT userdel since the user/home directory is shared with Hermes.
            $this->terminateSourceDeployment($client);
        } catch (Throwable $e) {
            $destDeployment->status = DeploymentStatusEnum::FAILED->value;
            $destDeployment->error_message = $e->getMessage();
            $destDeployment->saveOrFail();

            throw $e;
        } finally {
            $client->disconnect();
        }
    }

    /**
     * Cross-machine path: archive .openclaw on the source, stream it through local
     * temp storage, upload to the destination, extract, then migrate and start.
     */
    private function executeAcrossMachines(AgentDeployment $destDeployment): void
    {
        $agent = $destDeployment->agent;
        $timestamp = date('Ymd_His');
        $archiveName = 'openclaw_agents_' . $timestamp . '.tar.gz';
        $remoteArchive = '/tmp/' . $archiveName;
        $localTempFile = sys_get_temp_dir() . '/' . $archiveName;

        $sourceClient = OpenClawSshClient::fromMachine($this->sourceDeployment->machine);

        try {
            $this->packWorkspace($sourceClient, $remoteArchive, $localTempFile);
        } finally {
            $sourceClient->disconnect();
        }

        $destClient = SshClient::fromMachine($this->destinationMachine);

        try {
            $this->provisionUser($destClient, $destDeployment->system_user);
            $this->ensureSharedImage($destClient);
            $this->extractAndMigrate($destClient, $localTempFile, $remoteArchive, $destDeployment);
            $this->startContainers($destClient, $destDeployment);

            $destDeployment->status = DeploymentStatusEnum::RUNNING->value;
            $destDeployment->launched_at = now();
            $destDeployment->saveOrFail();

            $agent->set(CustomFieldEnum::HERMES_DEPLOYMENT_ID->value, $destDeployment->getId());

            // Stop OpenClaw containers on the source machine now that Hermes is running.
            $sourceClient = OpenClawSshClient::fromMachine($this->sourceDeployment->machine);
            try {
                $this->terminateSourceDeployment($sourceClient);
            } finally {
                $sourceClient->disconnect();
            }
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
    }

    /**
     * Stop the OpenClaw containers and mark the source deployment as terminated.
     *
     * We intentionally do NOT remove the Linux user or home directory — the workspace
     * files may still be needed (e.g. same-machine shared user) and a soft stop keeps
     * the migration reversible. The `docker compose down` without `--rmi local` leaves
     * the images intact so a restart is cheap if needed.
     */
    private function terminateSourceDeployment(BaseClient $client): void
    {
        $openclawDir = $this->sourceDeployment->home_directory . '/.openclaw';

        $client->exec(
            'sudo -u ' . escapeshellarg($this->sourceDeployment->system_user)
            . ' bash -c ' . escapeshellarg('cd ' . $openclawDir . ' && docker compose down 2>&1 || true'),
            60
        );

        $this->sourceDeployment->status = DeploymentStatusEnum::TERMINATED->value;
        $this->sourceDeployment->terminated_at = now();
        $this->sourceDeployment->saveOrFail();
    }

    /**
     * Archive the OpenClaw workspace on the source machine and stream it to a local temp file.
     */
    private function packWorkspace(OpenClawSshClient $client, string $remoteArchive, string $localTempFile): void
    {
        $sourceDir = $this->sourcePath ?? ($this->sourceDeployment->home_directory . '/.openclaw');
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
            throw new ValidationException('Failed to create OpenClaw workspace archive on source: ' . $result);
        }

        try {
            if (! $client->downloadToFile($remoteArchive, $localTempFile)) {
                throw new ValidationException('Failed to download OpenClaw workspace archive from source');
            }
        } finally {
            $client->exec('rm -f ' . escapeshellarg($remoteArchive));
        }
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
     * Upload the OpenClaw archive to the destination, extract it into a temporary location,
     * then run `hermes claw migrate` to convert the workspace to the Hermes format.
     *
     * The migrate command reads from the extracted .openclaw directory and writes
     * to the agent's ~/.hermes directory, preserving all compatible settings,
     * memory, skills, MCP servers, and messaging platform tokens.
     */
    private function extractAndMigrate(
        SshClient $client,
        string $localTempFile,
        string $remoteArchive,
        AgentDeployment $deployment,
    ): void {
        $homeDir = $this->destinationPath ?? $deployment->home_directory;
        $openclawExtractDir = $homeDir . '/.openclaw-import';

        if (! $client->uploadFromFile($remoteArchive, $localTempFile)) {
            throw new ValidationException('Failed to upload OpenClaw archive to destination');
        }

        // Extract the OpenClaw archive into a staging directory.
        $client->exec('sudo mkdir -p ' . escapeshellarg($openclawExtractDir));

        $result = $client->exec(
            'sudo tar -xzf ' . escapeshellarg($remoteArchive)
            . ' -C ' . escapeshellarg($openclawExtractDir)
            . ' --strip-components=1 2>&1'
            . '; echo "EXIT_CODE:$?"',
            60
        );

        if (str_contains($result, 'EXIT_CODE:1')) {
            throw new ValidationException('Failed to extract OpenClaw archive on destination: ' . $result);
        }

        // Remove the archive now that the files are extracted.
        $client->exec('rm -f ' . escapeshellarg($remoteArchive));

        $client->exec(
            'sudo chown -R ' . escapeshellarg($deployment->system_user . ':' . $deployment->system_user)
            . ' ' . escapeshellarg($openclawExtractDir)
        );

        $this->runMigrateCommand($client, $openclawExtractDir, $deployment);
    }

    /**
     * Run `hermes claw migrate` inside the shared container, converting the OpenClaw
     * staging directory into a Hermes workspace under the agent's home directory.
     * Cleans up the staging directory on success.
     */
    private function runMigrateCommand(SshClient $client, string $stagingDir, AgentDeployment $deployment): void
    {
        $homeDir = $this->destinationPath ?? $deployment->home_directory;
        $hermesDir = $homeDir . '/.hermes';

        $client->exec(
            'sudo -u ' . escapeshellarg($deployment->system_user)
            . ' mkdir -p ' . escapeshellarg($hermesDir)
        );

        $imageName = (new DockerComposeBuilder())->getSharedImageName($this->app);

        $result = $client->exec(
            'sudo -u ' . escapeshellarg($deployment->system_user)
            . ' bash -c ' . escapeshellarg(
                'docker run --rm'
                . ' -v ' . $stagingDir . ':' . $stagingDir
                . ' -v ' . $hermesDir . ':' . $hermesDir
                . ' ' . $imageName
                . ' hermes claw migrate'
                . ' --source ' . escapeshellarg($stagingDir)
                . ' --workspace-target ' . escapeshellarg($hermesDir)
                . ' --migrate-secrets --yes 2>&1'
            )
            . '; echo "EXIT_CODE:$?"',
            300
        );

        if (str_contains($result, 'EXIT_CODE:1')) {
            throw new ValidationException('hermes claw migrate failed: ' . $result);
        }

        // Fix ownership after the migration writes files as root inside the container.
        $client->exec(
            'sudo chown -R 1000:' . escapeshellarg($deployment->system_user)
            . ' ' . escapeshellarg($hermesDir)
        );
        $client->exec('sudo chmod -R g+rwx ' . escapeshellarg($hermesDir));

        // Clean up the staging directory.
        $client->exec('sudo rm -rf ' . escapeshellarg($stagingDir));
    }

    /**
     * Build the shared Hermes Docker image on the destination machine if it does not exist yet.
     * Must be called before any step that runs `docker run` or `docker compose` with the image.
     */
    private function ensureSharedImage(SshClient $client): void
    {
        $builder = new DockerComposeBuilder();
        $imageName = $builder->getSharedImageName($this->app);
        $imageDir = $builder->getSharedImageDir($this->app);

        $exists = $client->exec(
            'docker image inspect ' . escapeshellarg($imageName) . ' &>/dev/null && echo "EXISTS" || echo "MISSING"'
        );

        if (str_contains($exists, 'MISSING')) {
            $buildResult = $client->exec(
                'sudo docker build --no-cache -t ' . escapeshellarg($imageName)
                . ' ' . escapeshellarg($imageDir) . ' 2>&1; echo "EXIT_CODE:$?"',
                900
            );

            if (! str_contains($buildResult, 'EXIT_CODE:0')) {
                throw new ValidationException('Failed to build shared Hermes image on destination: ' . $buildResult);
            }
        }
    }

    /**
     * Rewrite docker-compose.yml with the destination ports, stop any existing
     * containers, then start fresh. Assumes the shared image is already present.
     */
    private function startContainers(SshClient $client, AgentDeployment $deployment): void
    {
        $hermesDir = $deployment->home_directory . '/.hermes';
        $agent = $deployment->agent;

        $builder = new DockerComposeBuilder();

        $gatewayToken = $this->company->get(ConfigurationEnum::GATEWAY_TOKEN->value) ?? bin2hex(random_bytes(32));
        $composeContent = $builder->buildDockerCompose($deployment, (string) $gatewayToken, $this->app, $agent);
        $client->writeFileAsUser($hermesDir . '/docker-compose.yml', $composeContent, $deployment->system_user);

        $client->exec(
            'sudo -u ' . escapeshellarg($deployment->system_user)
            . ' bash -c ' . escapeshellarg('cd ' . $hermesDir . ' && docker compose down 2>&1 || true'),
            60
        );

        $result = $client->exec(
            'sudo -u ' . escapeshellarg($deployment->system_user)
            . ' bash -c ' . escapeshellarg('cd ' . $hermesDir . ' && docker compose up -d 2>&1')
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
