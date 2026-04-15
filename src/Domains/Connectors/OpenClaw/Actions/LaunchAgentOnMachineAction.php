<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenClaw\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Database\Eloquent\Collection;
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

        $client = SshClient::fromMachine($this->machine);

        try {
            $this->ensureSharedImage($client);
            $this->provisionLinuxUser($client, $deployment);
            $this->writeDeploymentFiles($client, $deployment);
            $this->buildAndStart($client, $deployment);

            $deployment->status = DeploymentStatusEnum::RUNNING->value;
            $deployment->launched_at = now();
            $deployment->saveOrFail();

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

        $client->exec('sudo mkdir -p ' . escapeshellarg($imageDir));

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
    private function writeDeploymentFiles(SshClient $client, AgentDeployment $deployment): void
    {
        $openclawDir = $deployment->home_directory . '/.openclaw';
        $systemUser = $deployment->system_user;
        $gatewayToken = $this->company->get(ConfigurationEnum::GATEWAY_TOKEN->value) ?? bin2hex(random_bytes(32));

        $client->writeFileAsUser(
            $openclawDir . '/docker-compose.yml',
            DockerComposeBuilder::buildDockerCompose($deployment, (string) $gatewayToken, $this->app, $this->agent),
            $systemUser,
        );

        $client->writeFileAsUser(
            $openclawDir . '/openclaw.json',
            DockerComposeBuilder::buildOpenClawConfig(
                $this->agent,
                (string) $gatewayToken,
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

    /**
     * Run `docker compose up -d` as the agent's Linux user.
     * Uses the pre-built shared image — no per-agent build needed.
     * Appends EXIT_CODE to detect failures even when Docker writes to stderr.
     */
    private function buildAndStart(SshClient $client, AgentDeployment $deployment): void
    {
        $openclawDir = $deployment->home_directory . '/.openclaw';
        $maxAttempts = 10;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            if (! $this->portsAreAvailableOnMachine($client, $deployment)) {
                $this->reassignDeploymentPorts($client, $deployment);
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
                $this->stopPartialDeployment($client, $openclawDir);
                $this->reassignDeploymentPorts($client, $deployment);

                continue;
            }

            throw new ValidationException('Docker build/start failed: ' . $result);
        }

        throw new ValidationException(
            'Docker build/start failed: unable to find an open port pair on machine ' . $this->machine->name
        );
    }

    private function portsAreAvailableOnMachine(SshClient $client, AgentDeployment $deployment): bool
    {
        return $this->isPortAvailableOnMachine($client, $deployment->gateway_port)
            && $this->isPortAvailableOnMachine($client, $deployment->proxy_port);
    }

    private function isPortAvailableOnMachine(SshClient $client, int $port): bool
    {
        $result = $client->exec(
            'sudo ss -ltnH ' . escapeshellarg('( sport = :' . $port . ' )')
            . ' | grep -q . && echo "USED" || echo "FREE"',
        );

        return trim($result) === 'FREE';
    }

    private function hasPortBindError(string $result): bool
    {
        return str_contains($result, 'port is already allocated')
            || str_contains($result, 'Bind for 0.0.0.0:')
            || str_contains($result, 'driver failed programming external connectivity');
    }

    private function stopPartialDeployment(SshClient $client, string $openclawDir): void
    {
        $client->exec(
            'sudo bash -c ' . escapeshellarg('cd ' . $openclawDir . ' && docker compose down 2>&1 || true'),
            120,
        );
    }

    private function reassignDeploymentPorts(SshClient $client, AgentDeployment $deployment): void
    {
        $ports = $this->findAvailablePortPair($client, $deployment);

        $deployment->gateway_port = $ports['gateway_port'];
        $deployment->proxy_port = $ports['proxy_port'];
        $deployment->saveOrFail();

        $this->writeDeploymentFiles($client, $deployment);
    }

    /**
     * @return array{gateway_port: int, proxy_port: int}
     */
    private function findAvailablePortPair(SshClient $client, AgentDeployment $deployment): array
    {
        $usedPorts = $this->getReservedPortsForMachine($deployment);

        for ($port = $this->machine->port_range_start; $port < $this->machine->port_range_end; $port += 2) {
            $proxyPort = $port + 1;

            if (in_array($port, $usedPorts, true) || in_array($proxyPort, $usedPorts, true)) {
                continue;
            }

            if (! $this->isPortAvailableOnMachine($client, $port) || ! $this->isPortAvailableOnMachine($client, $proxyPort)) {
                continue;
            }

            return [
                'gateway_port' => $port,
                'proxy_port' => $proxyPort,
            ];
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
            ->whereNotIn('status', [DeploymentStatusEnum::TERMINATED->value])
            ->where('is_deleted', 0)
            ->get(['gateway_port', 'proxy_port']);

        return $this->flattenDeploymentPorts($deployments);
    }

    /**
     * @param Collection<int, AgentDeployment> $deployments
     *
     * @return array<int, int>
     */
    private function flattenDeploymentPorts(Collection $deployments): array
    {
        return $deployments
            ->flatMap(fn (AgentDeployment $deployment) => [$deployment->gateway_port, $deployment->proxy_port])
            ->filter(fn (?int $port) => $port !== null)
            ->map(fn (int $port) => (int) $port)
            ->values()
            ->all();
    }
}
