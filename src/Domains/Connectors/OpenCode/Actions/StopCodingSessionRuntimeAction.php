<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenCode\Actions;

use Kanvas\Intelligence\AgentRuntime\Harness\Models\AgentTaskSession;
use Throwable;

/**
 * Turns the lights off when the last session on an agent's container ends.
 *
 * The container belongs to the AGENT, not to this task, and several tasks share it by working in
 * separate directories. So a finishing session must check whether anyone else is still inside before
 * removing anything — otherwise finishing task A kills the runtime task B is mid-turn in. Only the last
 * one out removes the container.
 *
 * Deliberately NOT `BaseTerminateAgentOnMachineAction`: that one does `compose down --rmi local`,
 * `image prune -f`, `userdel -r` and `rm -rf` on the home directory, which is machine-wide destruction
 * per task.
 *
 * The **workspace and session data are left alone**. The session volume is what makes a task resumable
 * (a new container on the same volume still remembers the conversation), and a failed run's worktree is
 * the only place to see what it did. The sweeper removes both once they age out.
 */
class StopCodingSessionRuntimeAction
{
    public function __construct(
        private readonly AgentTaskSession $session,
    ) {
    }

    public function execute(): bool
    {
        $containerName = $this->session->container_name;

        // Attach mode never created a container — the server was already there and outlives the task.
        if ($containerName === null || $containerName === '') {
            return false;
        }

        if ($this->otherLiveSessionsShareContainer($containerName)) {
            return false;
        }

        $machine = $this->session->machine;

        if ($machine === null) {
            return false;
        }

        $client = $machine->connectSsh();

        try {
            $client->exec('docker rm -f ' . escapeshellarg($containerName) . ' 2>&1 || true', 120);
        } catch (Throwable $e) {
            // An unreachable machine is the common case here — it is often why the session is ending.
            // The orphan sweep catches the container by its label later.
            report($e);

            return false;
        } finally {
            $client->disconnect();
        }

        return true;
    }

    private function otherLiveSessionsShareContainer(string $containerName): bool
    {
        return AgentTaskSession::query()
            ->where('container_name', $containerName)
            ->where('id', '!=', $this->session->getId())
            ->live()
            ->exists();
    }
}
