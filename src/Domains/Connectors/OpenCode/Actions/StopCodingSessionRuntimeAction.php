<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenCode\Actions;

use Kanvas\Connectors\OpenCode\Runtime\CodingRuntimeDriverFactory;
use Kanvas\Connectors\OpenCode\SshClient as CodingSshClient;
use Kanvas\Intelligence\AgentRuntime\Harness\Models\AgentTaskSession;
use Kanvas\Intelligence\AgentRuntime\SshClient;
use Kanvas\Intelligence\Agents\Models\AgentMachine;
use Throwable;

/**
 * Turns the lights off when the last session on an agent's runtime ends.
 *
 * The runtime belongs to the AGENT, not to this task, and several tasks share it by working in
 * separate directories. So a finishing session must check whether anyone else is still inside before
 * removing anything — otherwise finishing task A kills the runtime task B is mid-turn in. Only the last
 * one out removes the runtime.
 *
 * Deliberately NOT `BaseTerminateAgentOnMachineAction`: that one does `compose down --rmi local`,
 * `image prune -f`, `userdel -r` and `rm -rf` on the home directory, which is machine-wide destruction
 * per task.
 *
 * The **workspace and session data are left alone**. The session volume is what makes a task resumable
 * (a new runtime on the same volume still remembers the conversation), and a failed run's worktree is
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
        $handle = $this->session->container_name;

        // Attach mode never created a runtime — the server was already there and outlives the task.
        if ($handle === null || $handle === '') {
            return false;
        }

        if ($this->otherLiveSessionsShareHandle($handle)) {
            return false;
        }

        $machine = $this->session->machine;

        if ($machine === null) {
            return false;
        }

        $client = null;

        try {
            $client = $this->openSshClient($machine);

            return CodingRuntimeDriverFactory::forMachine($machine)->remove($handle, $client);
        } catch (Throwable $e) {
            // An unreachable machine is the common case here — it is often why the session is ending.
            // The orphan sweep catches the runtime by its label later.
            report($e);

            return false;
        } finally {
            $client?->disconnect();
        }
    }

    protected function openSshClient(AgentMachine $machine): SshClient
    {
        return CodingSshClient::fromMachine($machine);
    }

    /**
     * Scoped to this machine: the handle is derived from the agent id, so the same name on another
     * machine is another runtime entirely.
     */
    private function otherLiveSessionsShareHandle(string $handle): bool
    {
        return AgentTaskSession::query()
            ->where('container_name', $handle)
            ->where('agent_machine_id', $this->session->agent_machine_id)
            ->where('id', '!=', $this->session->getId())
            ->live()
            ->exists();
    }
}
