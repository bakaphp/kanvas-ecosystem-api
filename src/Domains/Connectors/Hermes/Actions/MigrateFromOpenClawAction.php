<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Hermes\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Support\Facades\Log;
use Kanvas\Connectors\Hermes\Enums\ConfigurationEnum;
use Kanvas\Connectors\Hermes\Enums\CustomFieldEnum;
use Kanvas\Connectors\Hermes\Services\DockerComposeBuilder;
use Kanvas\Connectors\Hermes\SshClient;
use Kanvas\Connectors\OpenClaw\SshClient as OpenClawSshClient;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\AgentRuntime\Enums\DeploymentStatusEnum;
use Kanvas\Intelligence\AgentRuntime\SshClient as BaseClient;
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
            ->where('provider', 'hermes')
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
            $destDeployment->provider = 'hermes';
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
            // The hermes container runs as UID 10000 (confirmed: docker run image id → uid=10000(hermes)).
            // Chown the staging tree to 10000 so the in-container user can actually read workspace/.
            $client->exec('sudo chown -R 10000:10000 ' . escapeshellarg($stagingDir));
            $client->exec('sudo chmod -R 755 ' . escapeshellarg($stagingDir));

            $this->runMigrateCommand($client, $stagingDir, $destDeployment);

            // Stop OpenClaw containers before starting Hermes — on the same machine they
            // share the same container name prefix, so the old container must be removed first.
            $this->terminateSourceDeployment($client);

            $this->startContainers($client, $destDeployment);

            $destDeployment->status = DeploymentStatusEnum::RUNNING->value;
            $destDeployment->launched_at = now();
            $destDeployment->saveOrFail();

            $agent->set(CustomFieldEnum::HERMES_DEPLOYMENT_ID->value, $destDeployment->getId());
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

        if (! str_contains($result, 'EXIT_CODE:0')) {
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

        if (! str_contains($result, 'EXIT_CODE:0')) {
            throw new ValidationException('Failed to extract OpenClaw archive on destination: ' . $result);
        }

        // Remove the archive now that the files are extracted.
        $client->exec('rm -f ' . escapeshellarg($remoteArchive));

        // Chown to UID 10000 (confirmed in-container hermes user) so `claw migrate` can read
        // the workspace through the bind mount. Same fix as the same-machine path.
        $client->exec('sudo chown -R 10000:10000 ' . escapeshellarg($openclawExtractDir));
        $client->exec('sudo chmod -R 755 ' . escapeshellarg($openclawExtractDir));

        $this->runMigrateCommand($client, $openclawExtractDir, $deployment);
    }

    /**
     * Run `claw migrate` inside the shared container, converting the OpenClaw staging
     * directory into a Hermes workspace under the agent's home directory.
     * Cleans up the staging directory on success.
     *
     * Three constraints drive the invocation shape:
     *  1. The entrypoint (/opt/hermes/docker/entrypoint.sh) prepends the `hermes` binary, so
     *     we pass `claw migrate` — not `hermes claw migrate` — or the CLI sees a doubled prefix
     *     and exits with code 2 (silently treated as success by an EXIT_CODE:1-only check).
     *  2. The in-container hermes user is UID 10000. The staging dir must be chowned to 10000
     *     before the run; host-user ownership (~1000) causes permission denied on every read.
     *  3. The canonical target mount is /opt/data (per upstream docs). We also use an explicit
     *     /opt/openclaw-source mount + --source flag rather than relying on auto-detection,
     *     so the invocation is self-documenting and immune to default-path changes.
     */
    private function runMigrateCommand(SshClient $client, string $stagingDir, AgentDeployment $deployment): void
    {
        $homeDir = $this->destinationPath ?? $deployment->home_directory;
        $hermesDir = $homeDir . '/.hermes';

        $client->exec('sudo mkdir -p ' . escapeshellarg($hermesDir));
        // UID 10000 = hermes user inside the container. The target dir must be writable by it
        // before the run — sudo mkdir creates it root:root 755, which allows traversal but not
        // writes, so every file claw migrate tries to create silently fails or exits non-zero.
        $client->exec('sudo chown 10000:10000 ' . escapeshellarg($hermesDir));

        // The staging dir must also be readable by UID 10000.
        $client->exec('sudo chown -R 10000:10000 ' . escapeshellarg($stagingDir));
        $client->exec('sudo chmod -R 755 ' . escapeshellarg($stagingDir));

        $imageName = (new DockerComposeBuilder())->getSharedImageName($this->app);

        // The upstream nousresearch/hermes-agent entrypoint already invokes the `hermes`
        // binary — `docker run image gateway run` and `docker run image claw migrate` are
        // the documented forms. Passing `hermes claw migrate` here would double-prefix
        // (`hermes hermes claw migrate`), the CLI exits non-zero with "unknown command",
        // and the previous EXIT_CODE:1-only check silently swallowed that as success —
        // producing a Hermes container that looked freshly provisioned with no migrated
        // files. Mount the staging tree as the canonical source path and the target as
        // /opt/data (the volume the entrypoint expects) so we don't need any custom flags.
        $containerSourcePath = '/opt/openclaw-source';
        $containerTargetPath = '/opt/data';

        $result = $client->exec(
            'sudo docker run --rm'
            . ' -v ' . escapeshellarg($stagingDir) . ':' . $containerSourcePath
            . ' -v ' . escapeshellarg($hermesDir) . ':' . $containerTargetPath
            . ' ' . escapeshellarg($imageName)
            . ' claw migrate'
            . ' --source ' . escapeshellarg($containerSourcePath)
            . ' --workspace-target ' . escapeshellarg($containerTargetPath)
            . ' --migrate-secrets --yes 2>&1'
            . '; echo "EXIT_CODE:$?"',
            300
        );

        $context = [
            'agent' => $deployment->agent->slug,
            'deployment_id' => $deployment->getId(),
            'staging_dir' => $stagingDir,
            'hermes_dir' => $hermesDir,
            'image' => $imageName,
            'output' => $result,
        ];

        if (! str_contains($result, 'EXIT_CODE:0')) {
            Log::error('hermes claw migrate failed', $context);

            throw new ValidationException('hermes claw migrate failed: ' . $result);
        }

        Log::info('hermes claw migrate succeeded', $context);

        // Fix ownership so the host system user (agent-<slug>) can read the migrated files,
        // while preserving UID 10000 as the owner so the container can also write them.
        $client->exec(
            'sudo chown -R 10000:' . escapeshellarg($deployment->system_user)
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

        if (str_contains($exists, 'EXISTS')) {
            return;
        }

        // Stage the pinned Dockerfile + entrypoint before building. Without this, the build
        // would either fail (no Dockerfile on a fresh machine) or silently use a stale Dockerfile
        // — the exact bug pattern UpdateOpenClawForUserJob had to fix on the OpenClaw side.
        $mkdirResult = $client->exec(
            'sudo mkdir -p ' . escapeshellarg($imageDir) . ' 2>&1; echo "EXIT_CODE:$?"'
        );
        if (! str_contains($mkdirResult, 'EXIT_CODE:0')) {
            throw new ValidationException('Failed to create shared image directory ' . $imageDir . ': ' . $mkdirResult);
        }

        $client->writeFileAsUser($imageDir . '/Dockerfile', $builder->buildDockerfile($this->app), 'root');
        $client->writeFileAsUser($imageDir . '/entrypoint.sh', $builder->buildEntrypoint(), 'root');
        $client->exec('sudo chmod +x ' . escapeshellarg($imageDir . '/entrypoint.sh'));

        $buildResult = $client->exec(
            'cd ' . escapeshellarg($imageDir)
            . ' && sudo docker build --no-cache -t ' . escapeshellarg($imageName) . ' . 2>&1; echo "EXIT_CODE:$?"',
            900
        );

        if (! str_contains($buildResult, 'EXIT_CODE:0')) {
            throw new ValidationException('Failed to build shared Hermes image on destination: ' . $buildResult);
        }
    }

    /**
     * Rewrite docker-compose.yml and config.yaml with the destination ports and app model
     * settings, stop any existing containers, then start fresh.
     * Assumes the shared image is already present.
     *
     * Writing config.yaml here is important for migrations: `hermes claw migrate` copies the
     * model name from the OpenClaw workspace into config.yaml, which may be a non-existent or
     * stale model name (e.g. Hermes's own migration default is Claude). Overwriting it here
     * ensures the agent always starts with the model configured for this app (or gemini-2.0-flash
     * as the valid default).
     */
    private function startContainers(SshClient $client, AgentDeployment $deployment): void
    {
        $hermesDir = $deployment->home_directory . '/.hermes';
        $agent = $deployment->agent;

        $builder = new DockerComposeBuilder();

        $gatewayToken = $this->company->get(ConfigurationEnum::GATEWAY_TOKEN->value) ?? bin2hex(random_bytes(32));
        $composeContent = $builder->buildDockerCompose($deployment, (string) $gatewayToken, $this->app, $agent);
        $client->writeFileAsUser($hermesDir . '/docker-compose.yml', $composeContent, $deployment->system_user);

        $runtimeConfig = $builder->buildRuntimeConfig($agent, (string) $gatewayToken, $this->app);
        $client->writeFileAsUser($hermesDir . '/config.yaml', $runtimeConfig, $deployment->system_user);

        // Run down + port cleanup + up as a single exec to avoid phpseclib channel reuse errors.
        $downCmd = 'cd ' . escapeshellarg($hermesDir) . ' && docker compose down 2>&1 || true';
        $cleanupCmd = 'docker ps -q --filter publish=' . $deployment->gateway_port . ' | xargs -r docker rm -f 2>&1 || true'
            . ' ; docker ps -q --filter publish=' . $deployment->proxy_port . ' | xargs -r docker rm -f 2>&1 || true';
        $upCmd = 'cd ' . escapeshellarg($hermesDir) . ' && docker compose up -d 2>&1';

        $result = $client->exec(
            'sudo -u ' . escapeshellarg($deployment->system_user) . ' bash -c ' . escapeshellarg($downCmd)
            . ' ; ' . $cleanupCmd
            . ' ; sudo -u ' . escapeshellarg($deployment->system_user) . ' bash -c ' . escapeshellarg($upCmd)
            . ' ; echo "EXIT_CODE:$?"',
            180
        );

        if (
            str_contains($result, 'EXIT_CODE:1')
            || str_contains($result, 'Error response from daemon')
        ) {
            throw new ValidationException('Docker start failed on destination: ' . $result);
        }
    }
}
