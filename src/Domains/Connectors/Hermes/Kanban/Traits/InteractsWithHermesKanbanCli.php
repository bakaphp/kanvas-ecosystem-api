<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Hermes\Kanban\Traits;

use Closure;
use Kanvas\Connectors\Hermes\Services\HermesContainerCliService;
use Kanvas\Connectors\Hermes\Traits\OpensHermesSshClient;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Models\AgentMachine;

/**
 * Shared CLI plumbing for the Hermes kanban actions. The consuming action must declare
 * `private readonly AgentDeployment $deployment` and `private readonly ?string $board`.
 * SSH opening comes from OpensHermesSshClient (a protected seam tests can override).
 */
trait InteractsWithHermesKanbanCli
{
    use OpensHermesSshClient;

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
}
