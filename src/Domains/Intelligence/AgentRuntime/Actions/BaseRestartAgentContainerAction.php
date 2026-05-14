<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Actions;

use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\AgentRuntime\Enums\DeploymentStatusEnum;
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

    public function execute(): AgentDeployment
    {
        if (! $this->deployment->isRunning()) {
            throw new ValidationException('Cannot restart a deployment that is not running');
        }

        $client = $this->createSshClient($this->deployment->machine);

        try {
            $providerDir = $this->deployment->home_directory . '/.' . $client::makeProviderConfig()->dotDir;

            $client->exec(
                'sudo -u ' . escapeshellarg($this->deployment->system_user)
                . ' bash -c ' . escapeshellarg('cd ' . $providerDir . ' && docker compose restart 2>&1'),
                120,
            );

            $this->deployment->status = DeploymentStatusEnum::RUNNING->value;
            $this->deployment->saveOrFail();
        } finally {
            $client->disconnect();
        }

        return $this->deployment;
    }
}
