<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Hermes\Traits;

use Kanvas\Connectors\Hermes\SshClient;
use Kanvas\Intelligence\Agents\Models\AgentMachine;

/**
 * Opens a Hermes SSH client for a machine. A protected seam so tests can swap an in-memory stub.
 */
trait OpensHermesSshClient
{
    protected function openSshClient(AgentMachine $machine): SshClient
    {
        return SshClient::fromMachine($machine);
    }
}
