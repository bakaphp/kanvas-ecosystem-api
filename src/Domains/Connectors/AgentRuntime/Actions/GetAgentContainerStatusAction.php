<?php

declare(strict_types=1);

namespace Kanvas\Connectors\AgentRuntime\Actions;

use Kanvas\Connectors\AgentRuntime\SshClient;
use Kanvas\Intelligence\AgentRuntime\Enums\DeploymentStatusEnum;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;

/**
 * Check the running state of an agent's Docker container via `docker compose ps --format json`.
 * Syncs the container state (running/exited) back to the AgentDeployment record.
 */
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

        // Docker Compose v2 outputs NDJSON — one JSON object per line.
        // The compose stack has multiple services (gateway + socat-proxy), so we must
        // find the line whose Name matches the deployment's container name rather than
        // blindly taking the first line.
        foreach (explode("\n", trim($output)) as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $decoded = json_decode($line, true);

            if (! is_array($decoded)) {
                continue;
            }

            // Skip lines that belong to other containers in the stack.
            $name = $decoded['Name'] ?? $decoded['name'] ?? '';

            if ($name !== '' && $name !== $this->deployment->container_name) {
                continue;
            }

            $state = $decoded['State'] ?? $decoded['state'] ?? '';

            $this->deployment->status = match (true) {
                $state === 'running' => DeploymentStatusEnum::RUNNING->value,
                $state === 'exited' => DeploymentStatusEnum::STOPPED->value,
                default => $this->deployment->status,
            };

            return;
        }

        // No line matched the deployment's container — mark as stopped.
        $this->deployment->status = DeploymentStatusEnum::STOPPED->value;
    }
}
