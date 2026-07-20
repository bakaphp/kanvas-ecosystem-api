<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Actions;

use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\AgentRuntime\Enums\DeploymentStatusEnum;
use Kanvas\Intelligence\AgentRuntime\Services\BaseDockerComposeBuilderService;
use Kanvas\Intelligence\AgentRuntime\SshClient;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use Kanvas\Intelligence\Agents\Models\AgentMachine;

abstract class BaseRestartAgentContainerAction
{
    public function __construct(
        protected AgentDeployment $deployment,
    ) {
    }

    abstract protected function createSshClient(AgentMachine $machine): SshClient;

    abstract protected function getDockerComposeBuilder(): BaseDockerComposeBuilderService;

    public function execute(): AgentDeployment
    {
        if (! $this->deployment->isRunning()) {
            throw new ValidationException('Cannot restart a deployment that is not running');
        }

        $client = $this->createSshClient($this->deployment->machine);
        $config = $client::makeProviderConfig();

        try {
            $providerDir = $this->deployment->home_directory . '/.' . $config->dotDir;

            // Regenerate the compose file from the agent's current custom fields before
            // recreating the container. Channel tokens (Slack/Telegram) and other env values
            // are baked into the compose `environment:` block only at launch time — a plain
            // `docker compose restart` reuses the existing container definition, so tokens saved
            // after launch never reach the runtime. Rewriting here + `up -d --force-recreate`
            // is what makes the UI's "save tokens → restart" flow actually apply the change.
            $this->rewriteComposeFile($client, $config->gatewayTokenCustomFieldKey, $providerDir);

            $client->exec(
                'sudo -u ' . escapeshellarg($this->deployment->system_user)
                . ' bash -c ' . escapeshellarg('cd ' . $providerDir . ' && docker compose up -d --force-recreate 2>&1'),
                300,
            );

            $this->deployment->status = DeploymentStatusEnum::RUNNING->value;
            $this->deployment->saveOrFail();
        } finally {
            $client->disconnect();
        }

        return $this->deployment;
    }

    private function rewriteComposeFile(
        SshClient $client,
        string $gatewayTokenCustomFieldKey,
        string $providerDir,
    ): void {
        $agent = $this->deployment->agent;
        $app = $this->deployment->machine?->app;

        // Without agent + app context we can't regenerate the compose; recreate the existing
        // on-disk definition instead of failing the restart outright.
        if ($agent === null || $app === null) {
            return;
        }

        $gatewayToken = (string) $agent->get($gatewayTokenCustomFieldKey);

        $client->writeFileAsUser(
            $providerDir . '/docker-compose.yml',
            $this->getDockerComposeBuilder()->buildDockerCompose($this->deployment, $gatewayToken, $app, $agent),
            $this->deployment->system_user,
        );
    }
}
