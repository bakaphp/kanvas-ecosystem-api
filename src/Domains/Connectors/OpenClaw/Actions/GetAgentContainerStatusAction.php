<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenClaw\Actions;

use Kanvas\Connectors\OpenClaw\Enums\DeploymentStatusEnum;
use Kanvas\Connectors\OpenClaw\SshClient;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;

class GetAgentContainerStatusAction
{
    public function __construct(
        protected AgentDeployment $deployment,
    ) {
    }

    public function execute(): AgentDeployment
    {
        $client = SshClient::fromMachine($this->deployment->machine);

        try {
            $openclawDir = $this->deployment->home_directory . '/.openclaw';
            $result = $client->exec(
                'sudo -u ' . escapeshellarg($this->deployment->system_user)
                . ' bash -c ' . escapeshellarg('cd ' . $openclawDir . ' && docker compose ps --format json 2>&1')
            );

            $this->syncStatusFromOutput($result);
            $this->deployment->last_health_check = now();
            $this->deployment->saveOrFail();
        } finally {
            $client->disconnect();
        }

        return $this->deployment;
    }

    private function syncStatusFromOutput(string $output): void
    {
        if (empty(trim($output))) {
            $this->deployment->status = DeploymentStatusEnum::STOPPED->value;

            return;
        }

        $decoded = json_decode($output, true);

        if (is_array($decoded)) {
            $state = $decoded['State'] ?? $decoded['state'] ?? '';

            $this->deployment->status = match (true) {
                $state === 'running' => DeploymentStatusEnum::RUNNING->value,
                $state === 'exited' => DeploymentStatusEnum::STOPPED->value,
                default => $this->deployment->status,
            };
        }
    }
}
