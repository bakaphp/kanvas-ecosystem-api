<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenClaw\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
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
 *  1. Create AgentDeployment record (status: provisioning)
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
    ) {
    }

    public function execute(): AgentDeployment
    {
        if (! $this->machine->hasCapacity()) {
            throw new ValidationException('Machine ' . $this->machine->name . ' has reached maximum agent capacity');
        }

        $deployment = new CreateAgentDeploymentAction(
            $this->agent,
            $this->machine,
            $this->app,
            $this->company,
        )->execute();

        $client = SshClient::fromMachine($this->machine);

        try {
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
     * Write all config files into the agent's ~/.openclaw directory:
     *  - Dockerfile (built from template or app override)
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
            $openclawDir . '/Dockerfile',
            DockerComposeBuilder::buildDockerfile($this->app),
            $systemUser,
        );

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

        // Container runs as node (UID 1000) — volume-mounted files must be writable by that UID
        $client->exec('sudo chown -R 1000:1000 ' . escapeshellarg($openclawDir));
    }

    /**
     * Run `docker compose up -d --build` as the agent's Linux user.
     * Appends EXIT_CODE to detect failures even when Docker writes to stderr.
     */
    private function buildAndStart(SshClient $client, AgentDeployment $deployment): void
    {
        $openclawDir = $deployment->home_directory . '/.openclaw';
        $result = $client->exec(
            'sudo -u ' . escapeshellarg($deployment->system_user)
            . ' bash -c ' . escapeshellarg('cd ' . $openclawDir . ' && docker compose up -d --build 2>&1')
            . '; echo "EXIT_CODE:$?"'
        );

        $hasExitError = str_contains($result, 'EXIT_CODE:1')
            || str_contains($result, 'unknown user')
            || str_contains($result, 'Error response from daemon');

        if ($hasExitError) {
            throw new ValidationException('Docker build/start failed: ' . $result);
        }
    }
}
