<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenCode\Actions;

use Kanvas\Connectors\OpenCode\Concerns\ClosesSessionsInContainer;
use Kanvas\Connectors\OpenCode\Concerns\ResolvesAgentMachine;
use Kanvas\Connectors\OpenCode\SshClient;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Models\Agent;

/**
 * Destroys an agent's coding container, whatever is running in it.
 *
 * The blunt instrument, for a runtime that has stopped answering — the ordinary paths all decline to do
 * this: a finished job leaves the container up, and `StopCodingSessionRuntimeAction` will not remove one
 * that another live session is sharing. Sometimes the container itself is the problem, and those
 * protections are what stand in the way.
 *
 * **Sessions in it are closed, not abandoned** — see `ClosesSessionsInContainer`.
 *
 * Nothing on disk is touched: worktrees, branches and commits are on the host, so a killed container
 * costs only the container. The next task recreates one.
 */
class KillAgentCodingContainerAction
{
    use ClosesSessionsInContainer;
    use ResolvesAgentMachine;

    public function __construct(
        private readonly Agent $agent,
    ) {
    }

    /**
     * @return array{container: string, removed: bool, sessions_closed: int}
     */
    public function execute(string $reason): array
    {
        $container = EnsureAgentCodingContainerAction::containerNameFor($this->agent);
        $machine = $this->configuredMachine($this->agent);

        if ($machine === null) {
            throw new ValidationException(
                'Agent ' . $this->agent->name . ' has no coding machine, so it has no container to kill.'
            );
        }

        $client = SshClient::fromMachine($machine);

        try {
            $client->exec('docker rm -f ' . escapeshellarg($container) . ' 2>&1 || true', 120);
            $removed = trim($client->exec(
                'docker ps -aq --filter ' . escapeshellarg('name=^' . $container . '$'),
                30
            )) === '';
        } finally {
            $client->disconnect();
        }

        return [
            'container' => $container,
            'removed' => $removed,
            // After the container is gone, so a session cannot be finalised and then keep running.
            'sessions_closed' => $this->closeSessionsIn(
                $container,
                $this->agent->getId(),
                'The coding runtime was killed while this job was running: ' . $reason
            ),
        ];
    }
}
