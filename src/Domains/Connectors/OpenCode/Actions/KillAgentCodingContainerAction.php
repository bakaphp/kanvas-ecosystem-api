<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenCode\Actions;

use Kanvas\Connectors\OpenCode\Concerns\ClosesSessionsInContainer;
use Kanvas\Connectors\OpenCode\Concerns\ResolvesAgentMachine;
use Kanvas\Connectors\OpenCode\Runtime\CodingRuntimeDriverFactory;
use Kanvas\Connectors\OpenCode\SshClient;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\AgentRuntime\Harness\Contracts\CodingRuntimeDriver;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentMachine;

/**
 * Destroys an agent's coding runtime, whatever is running in it.
 *
 * The blunt instrument, for a runtime that has stopped answering — the ordinary paths all decline to do
 * this: a finished job leaves the runtime up, and `StopCodingSessionRuntimeAction` will not remove one
 * that another live session is sharing. Sometimes the runtime itself is the problem, and those
 * protections are what stand in the way.
 *
 * **Sessions in it are closed, not abandoned** — see `ClosesSessionsInContainer`.
 *
 * Nothing on disk is touched: worktrees, branches and commits are on the host, so a killed runtime
 * costs only the runtime. The next task recreates one.
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
     * @return array{runtime: string, sessions_closed: int}
     */
    public function execute(string $reason): array
    {
        $machine = $this->configuredMachine($this->agent);

        if ($machine === null) {
            throw new ValidationException(
                'Agent ' . $this->agent->name . ' has no coding machine, so it has no runtime to kill.'
            );
        }

        $driver = $this->driver($machine);
        $handle = $driver->handleFor($this->agent);
        $client = $this->openSshClient($machine);

        try {
            $removed = $driver->remove($handle, $client);
        } finally {
            $client->disconnect();
        }

        if (! $removed) {
            throw new ValidationException(
                'The coding runtime ' . $handle . ' could not be removed. Its sessions remain open.'
            );
        }

        return [
            'runtime' => $handle,
            // After the runtime is gone, so a session cannot be finalised and then keep running.
            'sessions_closed' => $this->closeSessionsIn(
                $handle,
                $this->agent->getId(),
                $machine->getId(),
                'The coding runtime was killed while this job was running: ' . $reason
            ),
        ];
    }

    protected function driver(AgentMachine $machine): CodingRuntimeDriver
    {
        return CodingRuntimeDriverFactory::forMachine($machine);
    }

    protected function openSshClient(AgentMachine $machine): SshClient
    {
        return SshClient::fromMachine($machine);
    }
}
