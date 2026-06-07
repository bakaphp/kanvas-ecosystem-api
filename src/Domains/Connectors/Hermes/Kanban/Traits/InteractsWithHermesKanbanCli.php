<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Hermes\Kanban\Traits;

use Kanvas\Connectors\Hermes\Services\HermesContainerCliService;
use Kanvas\Connectors\Hermes\SshClient;
use Kanvas\Intelligence\AgentRuntime\SshClient as BaseSshClient;
use Kanvas\Intelligence\Agents\Models\AgentMachine;

/**
 * Shared CLI plumbing for the Hermes kanban actions. The consuming action must declare
 * `private readonly AgentDeployment $deployment` and `private readonly ?string $board`.
 * `openSshClient()` is a protected seam so tests can swap in an in-memory SSH stub.
 */
trait InteractsWithHermesKanbanCli
{
    protected function openSshClient(AgentMachine $machine): BaseSshClient
    {
        return SshClient::fromMachine($machine);
    }

    protected function cli(BaseSshClient $ssh): HermesContainerCliService
    {
        return new HermesContainerCliService($ssh, $this->deployment->container_name);
    }

    /**
     * Prefix raw kanban args with the `kanban` subcommand and the optional board scope.
     *
     * @param list<string> $args
     * @return list<string>
     */
    protected function kanbanArgs(array $args): array
    {
        $prefix = ['kanban'];

        if ($this->board !== null && $this->board !== '') {
            $prefix[] = '--board';
            $prefix[] = $this->board;
        }

        return [...$prefix, ...$args];
    }
}
