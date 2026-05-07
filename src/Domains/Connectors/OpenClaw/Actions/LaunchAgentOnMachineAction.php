<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenClaw\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Kanvas\Connectors\OpenClaw\Enums\ConfigurationEnum;
use Kanvas\Connectors\OpenClaw\Enums\CustomFieldEnum;
use Kanvas\Connectors\OpenClaw\Enums\DeploymentStatusEnum;
use Kanvas\Connectors\OpenClaw\Services\DockerComposeBuilder;
use Kanvas\Connectors\OpenClaw\Services\WorkspaceFileBuilder;
use Kanvas\Connectors\OpenClaw\SshClient;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use Kanvas\Intelligence\Agents\Models\AgentMachine;
use Throwable;

/**
 * Deploy an OpenClaw agent to a remote machine in full Docker isolation.
 *
 * Lifecycle:
 *  1. Create or reuse AgentDeployment record (status: provisioning)
 *  2. SSH into machine and create a dedicated Linux user (agent-{slug})
 *  3. Write deployment files: Dockerfile, docker-compose.yml, openclaw.json,
 *     auth-profiles.json, and workspace files (soul, instructions, etc.)
 *  4. Build and start Docker containers via `docker compose up -d --build`
 *  5. Mark deployment as running (or failed on error)
 *
 * Each agent gets its own Linux user, home directory, Docker containers,
 * and port pair — providing full isolation of config, credentials, and workspaces.
 *
 * Files are written via base64+sudo tee (see SshClient::writeFileAsUser) then
 * chowned to UID 1000 (node user inside the container) so the OpenClaw process
 * can read/write its config.
 */
class LaunchAgentOnMachineAction
{
    public function __construct(
        protected Agent $agent,
        protected AgentMachine $machine,
        protected AppInterface $app,
        protected CompanyInterface $company,
        protected AgentDeployment $deployment,
    ) {
    }

    public function execute(): AgentDeployment
    {
        if (! $this->machine->hasCapacity()) {
            throw new ValidationException('Machine ' . $this->machine->name . ' has reached maximum agent capacity');
        }

        $deployment = $this->deployment;
        $gatewayToken = $this->resolveGatewayToken();

        $client = SshClient::fromMachine($this->machine);

        try {
            $this->ensureSharedImage($client);
            $this->provisionLinuxUser($client, $deployment);
            $this->writeDeploymentFiles($client, $deployment, $gatewayToken);
            $this->buildAndStart($client, $deployment, $gatewayToken);

            $deployment->status = DeploymentStatusEnum::RUNNING->value;
            $deployment->launched_at = now();
            $deployment->saveOrFail();
            $deployment->set(CustomFieldEnum::OPENCLAW_GATEWAY_TOKEN->value, $gatewayToken);

            $this->agent->update(['deployment_status' => 'deployed']);
            $this->agent->set(CustomFieldEnum::OPENCLAW_DEPLOYMENT_ID->value, $deployment->getId());
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

    /**
     * Create a dedicated Linux user for the agent and add it to the docker group.
     * The user's home directory contains all OpenClaw config and workspace files.
     */
    private function provisionLinuxUser(SshClient $client, AgentDeployment $deployment): void
    {
        $user = $deployment->system_user;
        $homeDir = $deployment->home_directory;

        $client->exec('id ' . escapeshellarg($user) . ' &>/dev/null || sudo useradd -m -s /bin/bash ' . escapeshellarg($user));
        $client->exec('sudo usermod -aG docker ' . escapeshellarg($user));
        $client->exec('sudo mkdir -p ' . escapeshellarg($homeDir . '/.openclaw/workspace'));
        $client->exec('sudo chown -R ' . escapeshellarg($user . ':' . $user) . ' ' . escapeshellarg($homeDir));
    }

    /**
     * Build the shared OpenClaw Docker image once per machine.
     * Writes Dockerfile + entrypoint to a shared directory and builds if the image doesn't exist.
     */
    private function ensureSharedImage(SshClient $client): void
    {
        $imageName = DockerComposeBuilder::getSharedImageName($this->app);
        $imageDir = DockerComposeBuilder::getSharedImageDir($this->app);

        $exists = $client->exec('docker image inspect ' . escapeshellarg($imageName) . ' &>/dev/null && echo "EXISTS" || echo "MISSING"');

        if (str_contains($exists, 'EXISTS')) {
            return;
        }

        $mkdirResult = $client->exec('sudo mkdir -p ' . escapeshellarg($imageDir) . ' 2>&1; echo "EXIT_CODE:$?"');
        if (! str_contains($mkdirResult, 'EXIT_CODE:0')) {
            throw new ValidationException('Failed to create shared image directory ' . $imageDir . ': ' . $mkdirResult);
        }

        $client->writeFileAsUser(
            $imageDir . '/Dockerfile',
            DockerComposeBuilder::buildDockerfile($this->app),
            'root',
        );

        $client->writeFileAsUser(
            $imageDir . '/entrypoint.sh',
            DockerComposeBuilder::buildEntrypoint(),
            'root',
        );
        $client->exec('sudo chmod +x ' . escapeshellarg($imageDir . '/entrypoint.sh'));

        $result = $client->exec(
            'cd ' . escapeshellarg($imageDir) . ' && sudo docker build -t ' . escapeshellarg($imageName) . ' . 2>&1; echo "EXIT_CODE:$?"',
            900,
        );

        if (! str_contains($result, 'EXIT_CODE:0')) {
            throw new ValidationException('Failed to build shared OpenClaw image: ' . $result);
        }
    }

    /**
     * Write all config files into the agent's ~/.openclaw directory:
     *  - docker-compose.yml (gateway + socat proxy + CLI containers)
     *  - openclaw.json (agent config, models, channels, gateway auth)
     *  - auth-profiles.json (API keys for LLM providers: Google, Anthropic)
     *  - workspace files (soul.md, instructions.md, output-format.md, identity.json)
     */
    private function writeDeploymentFiles(SshClient $client, AgentDeployment $deployment, string $gatewayToken): void
    {
        $openclawDir = $deployment->home_directory . '/.openclaw';
        $systemUser = $deployment->system_user;

        $this->writeDockerComposeFile($client, $deployment, $gatewayToken);

        $client->writeFileAsUser(
            $openclawDir . '/openclaw.json',
            DockerComposeBuilder::buildOpenClawConfig(
                $this->agent,
                $gatewayToken,
                $this->app,
                DockerComposeBuilder::buildChannelConfig($this->agent),
            ),
            $systemUser,
        );

        $agentDir = $openclawDir . '/agents/' . $this->agent->slug . '/agent';
        $client->exec('sudo mkdir -p ' . escapeshellarg($agentDir));

        $client->writeFileAsUser(
            $agentDir . '/auth-profiles.json',
            DockerComposeBuilder::buildAuthProfiles($this->app),
            $systemUser,
        );

        $files = WorkspaceFileBuilder::buildAll($this->agent);
        foreach ($files as $filename => $content) {
            $client->writeFileAsUser($openclawDir . '/workspace/' . $filename, $content, $systemUser);
        }

        // Container runs as node (UID 1000) — volume-mounted files must be writable by that UID.
        // Keep the agent's Linux group so the agent user retains access via group permissions.
        $client->exec('sudo chown -R 1000:' . escapeshellarg($systemUser) . ' ' . escapeshellarg($openclawDir));
        $client->exec('sudo chmod -R g+rwx ' . escapeshellarg($openclawDir));
    }

    private function writeDockerComposeFile(SshClient $client, AgentDeployment $deployment, string $gatewayToken): void
    {
        $openclawDir = $deployment->home_directory . '/.openclaw';

        $client->writeFileAsUser(
            $openclawDir . '/docker-compose.yml',
            DockerComposeBuilder::buildDockerCompose($deployment, $gatewayToken, $this->app, $this->agent),
            $deployment->system_user,
        );
    }

    /**
     * Resolve the gateway token once per deploy — prefer the company-configured
     * token, fall back to a freshly generated one. Used consistently across
     * openclaw.json and docker-compose.yml so the gateway config and container
     * env stay in lockstep.
     */
    private function resolveGatewayToken(): string
    {
        $configured = $this->company->get(ConfigurationEnum::GATEWAY_TOKEN->value);

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        return bin2hex(random_bytes(32));
    }

    /**
     * Run `docker compose up -d` as the agent's Linux user.
     * Uses the pre-built shared image — no per-agent build needed.
     * Appends EXIT_CODE to detect failures even when Docker writes to stderr.
     */
    private function buildAndStart(SshClient $client, AgentDeployment $deployment, string $gatewayToken): void
    {
        $openclawDir = $deployment->home_directory . '/.openclaw';
        $maxAttempts = 10;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $listeningPorts = $this->getListeningPortsOnMachine($client);

            // Best-effort preflight only. Another deployment may still bind the port before compose up,
            // so we also keep the retry path for Docker bind failures below.
            if (! $this->portsAreAvailableOnMachine($deployment, $listeningPorts)) {
                $this->reassignDeploymentPorts($client, $deployment, $listeningPorts, $attempt, 'preflight conflict', $gatewayToken);

                continue;
            }

            $result = $client->exec(
                'sudo -u ' . escapeshellarg($deployment->system_user)
                . ' bash -c ' . escapeshellarg('cd ' . $openclawDir . ' && docker compose up -d 2>&1')
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
                Log::warning('OpenClaw deployment hit Docker port bind conflict during launch', [
                    'deployment_id' => $deployment->getId(),
                    'machine_id' => $this->machine->getId(),
                    'machine_name' => $this->machine->name,
                    'attempt' => $attempt,
                    'gateway_port' => $deployment->gateway_port,
                    'proxy_port' => $deployment->proxy_port,
                ]);

                $this->stopPartialDeployment($client, $deployment, $openclawDir);
                $this->reassignDeploymentPorts($client, $deployment, $this->getListeningPortsOnMachine($client), $attempt, 'docker bind conflict', $gatewayToken);

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

    private function stopPartialDeployment(SshClient $client, AgentDeployment $deployment, string $openclawDir): void
    {
        $client->exec(
            'sudo -u ' . escapeshellarg($deployment->system_user)
            . ' bash -c ' . escapeshellarg('cd ' . $openclawDir . ' && docker compose down 2>&1 || true'),
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

        Log::info('Reassigned OpenClaw deployment ports after conflict detection', [
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

            return [
                'gateway_port' => $port,
                'proxy_port' => $proxyPort,
            ];
        }

        throw new ValidationException('No available ports on machine: ' . $this->machine->name);
    }

    private function getReservedPortsForMachine(AgentDeployment $deployment): array
    {
        $deployments = AgentDeployment::where('agent_machine_id', $this->machine->getId())
            ->where('id', '!=', $deployment->getId())
            ->whereNotIn('status', [DeploymentStatusEnum::FAILED->value, DeploymentStatusEnum::TERMINATED->value])
            ->where('is_deleted', 0)
            ->get(['gateway_port', 'proxy_port']);

        return $this->flattenDeploymentPorts($deployments);
    }

    private function flattenDeploymentPorts(Collection $deployments): array
    {
        return $deployments
            ->flatMap(fn (AgentDeployment $deployment) => [$deployment->gateway_port, $deployment->proxy_port])
            ->filter(fn (?int $port) => $port !== null)
            ->values()
            ->all();
    }
}
