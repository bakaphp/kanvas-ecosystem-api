<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Hermes\Kanban\Traits;

use Closure;
use Kanvas\Connectors\Hermes\Services\HermesContainerCliService;
use Kanvas\Connectors\Hermes\SshClient;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\AgentRuntime\SshClient as BaseSshClient;
use Kanvas\Intelligence\Agents\Models\AgentMachine;

/**
 * Shared CLI plumbing for the Hermes kanban actions. The consuming action must declare
 * `private readonly AgentDeployment $deployment` and `private readonly ?string $board`.
 * `openSshClient()` is a protected seam so tests can swap in an in-memory SSH stub.
 */
trait InteractsWithHermesKanbanCli
{
    /**
     * Open the deployment's container CLI, run $run, and always disconnect.
     *
     * @template T
     * @param Closure(HermesContainerCliService): T $run
     * @return T
     */
    protected function withCli(Closure $run): mixed
    {
        $machine = $this->deployment->machine;

        if (! $machine instanceof AgentMachine) {
            throw new ValidationException('Agent deployment has no machine for the kanban CLI');
        }

        $ssh = $this->openSshClient($machine);

        try {
            return $run(new HermesContainerCliService($ssh, $this->deployment->container_name));
        } finally {
            $ssh->disconnect();
        }
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

    protected function openSshClient(AgentMachine $machine): BaseSshClient
    {
        return SshClient::fromMachine($machine);
    }
}
