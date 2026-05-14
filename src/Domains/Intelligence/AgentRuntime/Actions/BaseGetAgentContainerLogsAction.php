<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Actions;

use Kanvas\Intelligence\AgentRuntime\SshClient;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use Kanvas\Intelligence\Agents\Models\AgentMachine;

/**
 * Fetch the tail of an agent's Docker container logs via `docker compose logs`.
 *
 * Provider-agnostic — the deployment directory is computed from the SshClient's
 * {@see \Kanvas\Intelligence\AgentRuntime\Contracts\ProviderConfig::dotDir} so
 * OpenClaw resolves to `~/.openclaw`, Hermes to `~/.hermes`, etc.
 */
abstract class BaseGetAgentContainerLogsAction
{
    public function __construct(
        protected AgentDeployment $deployment,
        protected int $lines = 100,
    ) {
    }

    abstract protected function createSshClient(AgentMachine $machine): SshClient;

    public function execute(): string
    {
        $client = $this->createSshClient($this->deployment->machine);

        try {
            $providerDir = $this->deployment->home_directory . '/.' . $client::makeProviderConfig()->dotDir;

            return trim($client->exec(
                'sudo -u ' . escapeshellarg($this->deployment->system_user)
                . ' bash -c ' . escapeshellarg('cd ' . $providerDir . ' && docker compose logs --tail ' . $this->lines . ' 2>&1')
            ));
        } finally {
            $client->disconnect();
        }
    }
}
