<?php

declare(strict_types=1);

namespace Kanvas\Connectors\AgentRuntime\Actions;

use Kanvas\Connectors\AgentRuntime\SshClient;
use Kanvas\Intelligence\Agents\Models\AgentMachine;
use Throwable;

/**
 * Verify that a machine is reachable via SSH by running a lightweight
 * echo command, then persist the result (is_connected + last_ping_at).
 */
class PingAgentMachineAction
{
    public function __construct(
        protected AgentMachine $machine,
    ) {
    }

    public function execute(): bool
    {
        $connected = false;

        try {
            $client = SshClient::fromMachine($this->machine);
            $result = $client->exec('echo pong', 15);
            $connected = str_contains($result, 'pong');
        } catch (Throwable) {
            $connected = false;
        } finally {
            if (isset($client)) {
                $client->disconnect();
            }
        }

        $this->machine->is_connected = $connected;
        $this->machine->last_ping_at = now();
        $this->machine->saveOrFail();

        return $connected;
    }
}
