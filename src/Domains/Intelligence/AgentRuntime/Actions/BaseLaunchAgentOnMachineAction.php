<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\AgentRuntime\Contracts\ProviderConfig;
use Kanvas\Intelligence\AgentRuntime\Enums\DeploymentStatusEnum;
use Kanvas\Intelligence\AgentRuntime\Services\BaseDockerComposeBuilder;
use Kanvas\Intelligence\AgentRuntime\Services\WorkspaceFileBuilder;
use Kanvas\Intelligence\AgentRuntime\SshClient;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use Kanvas\Intelligence\Agents\Models\AgentMachine;
use Throwable;

/**
 * Abstract base for deploying an agent to a remote machine in full Docker isolation.
 *
 * Lifecycle:
 *  1. Validate machine capacity
 *  2. SSH into machine and create a dedicated Linux user (agent-{slug})
 *  3. Write deployment files: Dockerfile, docker-compose.yml, {provider}.json,
 *     auth-profiles.json, and workspace markdown files
 *  4. Build and start Docker containers via `docker compose up -d`
 *  5. Mark deployment as running (or failed on error)
 *
 * Concrete subclasses provide the provider-specific SSH client, builder, and
 * custom field key strings.
 */
abstract class BaseLaunchAgentOnMachineAction
{
    public function __construct(
        protected Agent $agent,
        protected AgentMachine $machine,
        protected AppInterface $app,
        protected CompanyInterface $company,
        protected AgentDeployment $deployment,
    ) {
    }

    abstract protected function getProviderConfig(): ProviderConfig;

    /** Returns a connected SSH client for this provider pointing at $this->machine. */
    abstract protected function createSshClient(): SshClient;

    /** Returns the concrete DockerComposeBuilder for this provider. */
    abstract protected function getDockerComposeBuilder(): BaseDockerComposeBuilder;

    public function execute(): AgentDeployment
    {
        if (! $this->machine->hasCapacity()) {
            throw new ValidationException('Machine ' . $this->machine->name . ' has reached maximum agent capacity');
        }

        $deployment = $this->deployment;
        $gatewayToken = $this->resolveGatewayToken();
        $client = $this->createSshClient();

        try {
            $this->ensureSharedImage($client);
            $this->provisionLinuxUser($client, $deployment);
            $this->writeDeploymentFiles($client, $deployment, $gatewayToken);
            $this->buildAndStart($client, $deployment, $gatewayToken);

            $deployment->status = DeploymentStatusEnum::RUNNING->value;
            $deployment->launched_at = now();
            $deployment->saveOrFail();
            $deployment->set($this->getProviderConfig()->gatewayTokenCustomFieldKey, $gatewayToken);

            $this->agent->update(['deployment_status' => 'deployed']);
            $this->agent->set($this->getProviderConfig()->deploymentIdCustomFieldKey, $deployment->getId());
        } catch (Throwable $e) {
            $deployment->status = DeploymentStatusEnum::FAILED->value;
            $deployment->error_message = $e->getMessage();
            $deployment->saveOrFail();

            $this->agent->update(['deployment_status' => 'failed']);

            throw $e;
        } finally {
            $client->disconnect();
        }

        return $deployment;
    }

    private function provisionLinuxUser(SshClient $client, AgentDeployment $deployment): void
    {
        $user = $deployment->system_user;
        $homeDir = $deployment->home_directory;
        $dotDir = $this->getProviderConfig()->dotDir;

        $client->exec('id ' . escapeshellarg($user) . ' &>/dev/null || sudo useradd -m -s /bin/bash ' . escapeshellarg($user));
        $client->exec('sudo usermod -aG docker ' . escapeshellarg($user));
        $client->exec('sudo mkdir -p ' . escapeshellarg($homeDir . '/.' . $dotDir . '/workspace'));
        $client->exec('sudo chown -R ' . escapeshellarg($user . ':' . $user) . ' ' . escapeshellarg($homeDir));
    }

    private function ensureSharedImage(SshClient $client): void
    {
        $builder = $this->getDockerComposeBuilder();
        $imageName = $builder->getSharedImageName($this->app);
        $imageDir = $builder->getSharedImageDir($this->app);

        $exists = $client->exec('docker image inspect ' . escapeshellarg($imageName) . ' &>/dev/null && echo "EXISTS" || echo "MISSING"');

        if (str_contains($exists, 'EXISTS')) {
            return;
        }

        $mkdirResult = $client->exec('sudo mkdir -p ' . escapeshellarg($imageDir) . ' 2>&1; echo "EXIT_CODE:$?"');
        if (! str_contains($mkdirResult, 'EXIT_CODE:0')) {
            throw new ValidationException('Failed to create shared image directory ' . $imageDir . ': ' . $mkdirResult);
        }

        $client->writeFileAsUser($imageDir . '/Dockerfile', $builder->buildDockerfile($this->app), 'root');
        $client->writeFileAsUser($imageDir . '/entrypoint.sh', $builder->buildEntrypoint(), 'root');
        $client->exec('sudo chmod +x ' . escapeshellarg($imageDir . '/entrypoint.sh'));

        $result = $client->exec(
            'cd ' . escapeshellarg($imageDir) . ' && sudo docker build -t ' . escapeshellarg($imageName) . ' . 2>&1; echo "EXIT_CODE:$?"',
            900,
        );

        if (! str_contains($result, 'EXIT_CODE:0')) {
            throw new ValidationException('Failed to build shared ' . $this->getProviderConfig()->providerName . ' image: ' . $result);
        }
    }

    private function writeDeploymentFiles(SshClient $client, AgentDeployment $deployment, string $gatewayToken): void
    {
        $dotDir = $this->getProviderConfig()->dotDir;
        $providerDir = $deployment->home_directory . '/.' . $dotDir;
        $systemUser = $deployment->system_user;
        $builder = $this->getDockerComposeBuilder();

        $this->writeDockerComposeFile($client, $deployment, $gatewayToken);

        $client->writeFileAsUser(
            $providerDir . '/' . $this->getProviderConfig()->configFilename,
            $builder->buildRuntimeConfig(
                $this->agent,
                $gatewayToken,
                $this->app,
                $builder->buildChannelConfig($this->agent),
            ),
            $systemUser,
        );

        // auth-profiles.json is OpenClaw's auth shape — Hermes returns null here because
        // it sources API keys from env vars instead.
        $authPath = $builder->getAuthProfilesTargetPath($providerDir, $this->agent->slug);
        if ($authPath !== null) {
            $client->exec('sudo mkdir -p ' . escapeshellarg(dirname($authPath)));
            $client->writeFileAsUser(
                $authPath,
                $builder->buildAuthProfiles($this->app),
                $systemUser,
            );
        }

        // Workspace markdown files. The builder decides where each file goes (and may skip
        // some): OpenClaw puts them all in $providerDir/workspace/, Hermes only writes
        // SOUL.md at the root of $providerDir.
        $files = WorkspaceFileBuilder::buildAll($this->agent);
        foreach ($files as $filename => $content) {
            $target = $builder->getWorkspaceFileTargetPath($providerDir, $filename);
            if ($target === null) {
                continue;
            }
            $client->exec('sudo mkdir -p ' . escapeshellarg(dirname($target)));
            $client->writeFileAsUser($target, $content, $systemUser);
        }

        $client->exec('sudo chown -R 1000:' . escapeshellarg($systemUser) . ' ' . escapeshellarg($providerDir));
        $client->exec('sudo chmod -R g+rwx ' . escapeshellarg($providerDir));
    }

    private function writeDockerComposeFile(SshClient $client, AgentDeployment $deployment, string $gatewayToken): void
    {
        $dotDir = $this->getProviderConfig()->dotDir;
        $providerDir = $deployment->home_directory . '/.' . $dotDir;

        $client->writeFileAsUser(
            $providerDir . '/docker-compose.yml',
            $this->getDockerComposeBuilder()->buildDockerCompose($deployment, $gatewayToken, $this->app, $this->agent),
            $deployment->system_user,
        );
    }

    /**
     * Agent-level gateway token: the canonical source is the agent's custom field. Generated
     * once on first launch and reused for every subsequent re-launch / migration. We persist
     * before the deployment-level write on line 74 of execute() so that the agent's token
     * outlives any single deployment row — useful for cross-runtime migration where the
     * deployment is replaced but the agent identity (and its API_SERVER_KEY) stays the same.
     *
     * Company-level config (legacy `hermes_gateway_token`) is intentionally ignored: per-agent
     * tokens are the security model going forward, and a stable agent-scoped key is what
     * downstream Nervous System integrations (health checks, /v1/runs callers) authenticate with.
     */
    private function resolveGatewayToken(): string
    {
        $customFieldKey = $this->getProviderConfig()->gatewayTokenCustomFieldKey;
        $existing = $this->agent->get($customFieldKey);

        if (is_string($existing) && $existing !== '') {
            return $existing;
        }

        $token = bin2hex(random_bytes(32));
        $this->agent->set($customFieldKey, $token);

        return $token;
    }

    private function buildAndStart(SshClient $client, AgentDeployment $deployment, string $gatewayToken): void
    {
        $dotDir = $this->getProviderConfig()->dotDir;
        $providerDir = $deployment->home_directory . '/.' . $dotDir;
        $providerName = $this->getProviderConfig()->providerName;
        $maxAttempts = 10;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $listeningPorts = $this->getListeningPortsOnMachine($client);

            if (! $this->portsAreAvailableOnMachine($deployment, $listeningPorts)) {
                $this->reassignDeploymentPorts($client, $deployment, $listeningPorts, $attempt, 'preflight conflict', $gatewayToken);

                continue;
            }

            $result = $client->exec(
                'sudo -u ' . escapeshellarg($deployment->system_user)
                . ' bash -c ' . escapeshellarg('cd ' . $providerDir . ' && docker compose up -d 2>&1')
                . '; echo "EXIT_CODE:$?"',
                900,
            );

            $hasExitError = str_contains($result, 'EXIT_CODE:1')
                || str_contains($result, 'unknown user')
                || str_contains($result, 'Error response from daemon')
                || ! str_contains($result, 'EXIT_CODE:0');

            if (! $hasExitError) {
                return;
            }

            if ($this->hasPortBindError($result) && $attempt < $maxAttempts) {
                Log::warning(ucfirst($providerName) . ' deployment hit Docker port bind conflict during launch', [
                    'deployment_id' => $deployment->getId(),
                    'machine_id' => $this->machine->getId(),
                    'machine_name' => $this->machine->name,
                    'attempt' => $attempt,
                    'gateway_port' => $deployment->gateway_port,
                    'proxy_port' => $deployment->proxy_port,
                ]);

                $this->stopPartialDeployment($client, $deployment, $providerDir);
                $this->reassignDeploymentPorts(
                    $client,
                    $deployment,
                    $this->getListeningPortsOnMachine($client),
                    $attempt,
                    'docker bind conflict',
                    $gatewayToken,
                );

                continue;
            }

            throw new ValidationException('Docker build/start failed: ' . $result);
        }

        throw new ValidationException(
            'Docker build/start failed: unable to find an open port pair on machine ' . $this->machine->name
        );
    }

    /**
     * @param array<int, int> $listeningPorts
     */
    private function portsAreAvailableOnMachine(AgentDeployment $deployment, array $listeningPorts): bool
    {
        return ! in_array($deployment->gateway_port, $listeningPorts, true)
            && ! in_array($deployment->proxy_port, $listeningPorts, true);
    }

    /**
     * @return array<int, int>
     */
    private function getListeningPortsOnMachine(SshClient $client): array
    {
        $result = $client->exec("sudo ss -ltnH | awk '{print \$4}' | grep -oE '[0-9]+$' | sort -un");

        return collect(preg_split('/\r?\n/', trim($result)) ?: [])
            ->filter(fn (string $port) => $port !== '')
            ->map(fn (string $port) => (int) $port)
            ->values()
            ->all();
    }

    private function hasPortBindError(string $result): bool
    {
        return str_contains($result, 'port is already allocated')
            || str_contains($result, 'Bind for 0.0.0.0:')
            || str_contains($result, 'driver failed programming external connectivity');
    }

    private function stopPartialDeployment(SshClient $client, AgentDeployment $deployment, string $providerDir): void
    {
        $client->exec(
            'sudo -u ' . escapeshellarg($deployment->system_user)
            . ' bash -c ' . escapeshellarg('cd ' . $providerDir . ' && docker compose down 2>&1 || true'),
            120,
        );
    }

    /**
     * @param array<int, int> $listeningPorts
     */
    private function reassignDeploymentPorts(
        SshClient $client,
        AgentDeployment $deployment,
        array $listeningPorts,
        int $attempt,
        string $reason,
        string $gatewayToken,
    ): void {
        $previousGatewayPort = $deployment->gateway_port;
        $previousProxyPort = $deployment->proxy_port;
        $ports = $this->findAvailablePortPair($deployment, $listeningPorts);

        $deployment->gateway_port = $ports['gateway_port'];
        $deployment->proxy_port = $ports['proxy_port'];
        $deployment->saveOrFail();

        $this->writeDockerComposeFile($client, $deployment, $gatewayToken);

        Log::info('Reassigned ' . $this->getProviderConfig()->providerName . ' deployment ports after conflict detection', [
            'deployment_id' => $deployment->getId(),
            'machine_id' => $this->machine->getId(),
            'machine_name' => $this->machine->name,
            'attempt' => $attempt,
            'reason' => $reason,
            'previous_gateway_port' => $previousGatewayPort,
            'previous_proxy_port' => $previousProxyPort,
            'gateway_port' => $deployment->gateway_port,
            'proxy_port' => $deployment->proxy_port,
        ]);
    }

    /**
     * @param array<int, int> $listeningPorts
     *
     * @return array{gateway_port: int, proxy_port: int}
     */
    private function findAvailablePortPair(AgentDeployment $deployment, array $listeningPorts): array
    {
        $usedPorts = $this->getReservedPortsForMachine($deployment);

        for ($port = $this->machine->port_range_start; $port < $this->machine->port_range_end; $port += 2) {
            $proxyPort = $port + 1;

            if (in_array($port, $usedPorts, true) || in_array($proxyPort, $usedPorts, true)) {
                continue;
            }

            if (in_array($port, $listeningPorts, true) || in_array($proxyPort, $listeningPorts, true)) {
                continue;
            }

            return ['gateway_port' => $port, 'proxy_port' => $proxyPort];
        }

        throw new ValidationException('No available ports on machine: ' . $this->machine->name);
    }

    /**
     * @return array<int, int>
     */
    private function getReservedPortsForMachine(AgentDeployment $deployment): array
    {
        $deployments = AgentDeployment::where('agent_machine_id', $this->machine->getId())
            ->where('id', '!=', $deployment->getId())
            ->whereNotIn('status', [DeploymentStatusEnum::FAILED->value, DeploymentStatusEnum::TERMINATED->value])
            ->where('is_deleted', 0)
            ->get(['gateway_port', 'proxy_port']);

        return $this->flattenDeploymentPorts($deployments);
    }

    /**
     * @return array<int, int>
     */
    private function flattenDeploymentPorts(Collection $deployments): array
    {
        return $deployments
            ->flatMap(fn (AgentDeployment $d) => [$d->gateway_port, $d->proxy_port])
            ->filter(fn (?int $port) => $port !== null)
            ->values()
            ->all();
    }
}
