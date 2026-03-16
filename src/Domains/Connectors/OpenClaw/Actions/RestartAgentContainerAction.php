<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenClaw\Actions;

use Kanvas\Connectors\OpenClaw\Enums\DeploymentStatusEnum;
use Kanvas\Connectors\OpenClaw\SshClient;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;

/**
 * Restart an agent's Docker containers via `docker compose restart`.
 * Only works on deployments that are currently running.
 */
class RestartAgentContainerAction
{
    public function __construct(
        protected AgentDeployment $deployment,
    ) {
    }

    public function execute(): AgentDeployment
    {
        if (! $this->deployment->isRunning()) {
            throw new ValidationException('Cannot restart a deployment that is not running');
        }

        $client = SshClient::fromMachine($this->deployment->machine);

        try {
            $openclawDir = $this->deployment->home_directory . '/.openclaw';

            $client->exec(
                'sudo -u ' . escapeshellarg($this->deployment->system_user)
                . ' bash -c ' . escapeshellarg('cd ' . $openclawDir . ' && docker compose restart 2>&1')
            );

            $this->deployment->status = DeploymentStatusEnum::RUNNING->value;
            $this->deployment->saveOrFail();
        } finally {
            $client->disconnect();
        }

        return $this->deployment;
    }
}
