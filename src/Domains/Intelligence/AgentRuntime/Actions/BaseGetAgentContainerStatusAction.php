<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Actions;

use Kanvas\Intelligence\AgentRuntime\Enums\DeploymentStatusEnum;
use Kanvas\Intelligence\AgentRuntime\Events\AgentDeploymentStatusChanged;
use Kanvas\Intelligence\AgentRuntime\SshClient;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use Kanvas\Intelligence\Agents\Models\AgentMachine;

abstract class BaseGetAgentContainerStatusAction
{
    public function __construct(
        protected AgentDeployment $deployment,
    ) {
    }

    abstract protected function createSshClient(AgentMachine $machine): SshClient;

    public function execute(): AgentDeployment
    {
        $client = $this->createSshClient($this->deployment->machine);
        $previousStatus = $this->deployment->status;

        try {
            $providerDir = $this->deployment->home_directory . '/.' . $client::makeProviderConfig()->dotDir;
            $result = $client->exec(
                'sudo -u ' . escapeshellarg($this->deployment->system_user)
                . ' bash -c ' . escapeshellarg('cd ' . $providerDir . ' && docker compose ps --format json 2>&1')
            );

            $this->syncStatusFromOutput($result);
            $this->deployment->last_health_check = now();
            $this->deployment->saveOrFail();

            // A poll-detected transition (e.g. an operator restarting a crashed container
            // by hand, outside Kanvas) still needs to reach every open admin tab, not just
            // whichever one happened to poll — so broadcast the same event the lifecycle
            // jobs (launch/restart/terminate) fire. Guarded on an actual change since this
            // runs on a recurring poll, unlike those one-shot jobs.
            if ($this->deployment->status !== $previousStatus) {
                AgentDeploymentStatusChanged::dispatch($this->deployment, $previousStatus);
            }
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
        foreach (explode("\n", trim($output)) as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $decoded = json_decode($line, true);

            if (! is_array($decoded)) {
                continue;
            }

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
