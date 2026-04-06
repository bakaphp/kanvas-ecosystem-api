<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenClaw\Actions;

use Kanvas\Connectors\OpenClaw\SshClient;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;

/**
 * Fetch Docker container logs via `docker compose logs --tail {lines}`.
 */
class GetAgentContainerLogsAction
{
    public function __construct(
        protected AgentDeployment $deployment,
        protected int $lines = 100,
    ) {
    }

    public function execute(): string
    {
        $client = SshClient::fromMachine($this->deployment->machine);

        try {
            $openclawDir = $this->deployment->home_directory . '/.openclaw';

            return trim($client->exec(
                'sudo -u ' . escapeshellarg($this->deployment->system_user)
                . ' bash -c ' . escapeshellarg('cd ' . $openclawDir . ' && docker compose logs --tail ' . $this->lines . ' 2>&1')
            ));
        } finally {
            $client->disconnect();
        }
    }
}
