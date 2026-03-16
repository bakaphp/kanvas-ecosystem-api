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

        $ports = $this->machine->allocatePortPair();
        $slug = $this->agent->slug;
        $systemUser = 'agent-' . $slug;
        $homeDir = '/home/' . $systemUser;
        $containerName = 'openclaw-' . $slug;

        $deployment = new AgentDeployment();
        $deployment->apps_id = $this->app->getId();
        $deployment->companies_id = $this->company->getId();
        $deployment->agent_id = $this->agent->getId();
        $deployment->agent_machine_id = $this->machine->getId();
        $deployment->system_user = $systemUser;
        $deployment->home_directory = $homeDir;
        $deployment->gateway_port = $ports['gateway_port'];
        $deployment->proxy_port = $ports['proxy_port'];
        $deployment->container_name = $containerName;
        $deployment->status = DeploymentStatusEnum::PROVISIONING->value;
        $deployment->saveOrFail();

        $client = SshClient::fromMachine($this->machine);

        try {
            $this->provisionLinuxUser($client, $systemUser, $homeDir);
            $this->writeDeploymentFiles($client, $deployment, $homeDir, $systemUser);
            $this->buildAndStart($client, $systemUser, $homeDir);

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

    private function provisionLinuxUser(SshClient $client, string $systemUser, string $homeDir): void
    {
        $client->exec('id ' . escapeshellarg($systemUser) . ' &>/dev/null || sudo useradd -m -s /bin/bash ' . escapeshellarg($systemUser));
        $client->exec('sudo usermod -aG docker ' . escapeshellarg($systemUser));
        $client->exec('sudo mkdir -p ' . escapeshellarg($homeDir . '/.openclaw/workspace'));
        $client->exec('sudo chown -R ' . escapeshellarg($systemUser . ':' . $systemUser) . ' ' . escapeshellarg($homeDir));
    }

    private function writeDeploymentFiles(SshClient $client, AgentDeployment $deployment, string $homeDir, string $systemUser): void
    {
        $openclawDir = $homeDir . '/.openclaw';

        $gatewayToken = $this->company->get(ConfigurationEnum::GATEWAY_TOKEN->value) ?? bin2hex(random_bytes(32));

        $dockerfile = DockerComposeBuilder::buildDockerfile($this->app);
        $this->writeFileAsUser($client, $openclawDir . '/Dockerfile', $dockerfile, $systemUser);

        $compose = DockerComposeBuilder::buildDockerCompose($deployment, (string) $gatewayToken, $this->app);
        $this->writeFileAsUser($client, $openclawDir . '/docker-compose.yml', $compose, $systemUser);

        $channelConfig = $this->buildChannelConfig();
        $openclawConfig = DockerComposeBuilder::buildOpenClawConfig(
            $this->agent,
            (string) $gatewayToken,
            $this->app,
            $channelConfig,
        );
        $this->writeFileAsUser($client, $openclawDir . '/openclaw.json', $openclawConfig, $systemUser);

        $agentDir = $openclawDir . '/agents/' . $this->agent->slug . '/agent';
        $client->exec('sudo mkdir -p ' . escapeshellarg($agentDir));

        $authProfiles = DockerComposeBuilder::buildAuthProfiles($this->app);
        $this->writeFileAsUser($client, $agentDir . '/auth-profiles.json', $authProfiles, $systemUser);

        $files = WorkspaceFileBuilder::buildAll($this->agent);
        foreach ($files as $filename => $content) {
            $this->writeFileAsUser($client, $openclawDir . '/workspace/' . $filename, $content, $systemUser);
        }

        // Container runs as node (UID 1000) — volume-mounted files must be writable by that UID
        $client->exec('sudo chown -R 1000:1000 ' . escapeshellarg($openclawDir));
    }

    private function writeFileAsUser(SshClient $client, string $remotePath, string $content, string $systemUser): void
    {
        $encoded = base64_encode($content);
        $client->exec(
            'echo ' . escapeshellarg($encoded)
            . ' | base64 -d | sudo tee ' . escapeshellarg($remotePath) . ' > /dev/null'
            . ' && sudo chown ' . escapeshellarg($systemUser . ':' . $systemUser) . ' ' . escapeshellarg($remotePath)
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function buildChannelConfig(): array
    {
        $slackBotToken = $this->app->get(ConfigurationEnum::SLACK_BOT_TOKEN->value);
        $slackAppToken = $this->app->get(ConfigurationEnum::SLACK_APP_TOKEN->value);

        if (empty($slackBotToken) || empty($slackAppToken)) {
            return [];
        }

        return [
            'slack' => [
                'botToken' => (string) $slackBotToken,
                'appToken' => (string) $slackAppToken,
            ],
        ];
    }

    private function buildAndStart(SshClient $client, string $systemUser, string $homeDir): void
    {
        $openclawDir = $homeDir . '/.openclaw';
        $result = $client->exec(
            'sudo -u ' . escapeshellarg($systemUser)
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
