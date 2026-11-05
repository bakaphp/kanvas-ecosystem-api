<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenCode\Concerns;

use Kanvas\Intelligence\AgentRuntime\Harness\Actions\FinalizeHarnessSessionAction;
use Kanvas\Intelligence\AgentRuntime\Harness\Enums\HarnessStatusEnum;
use Kanvas\Intelligence\AgentRuntime\Harness\Models\AgentTaskSession;
use Throwable;

/**
 * Sessions in a runtime that has just been removed are closed, not abandoned.
 *
 * Removing the runtime under a running session would leave a row that says "running" forever,
 * holding a concurrency slot nothing will ever release and telling everyone the work is still in
 * progress. Each one is finalised with a reason instead.
 *
 * Scoped to the machine: the handle is derived from the agent id, so the same agent on another
 * machine carries the same name for a runtime that is still very much alive.
 */
trait ClosesSessionsInContainer
{
    private function closeSessionsIn(
        string $handle,
        int $agentId,
        int $machineId,
        string $reason
    ): int {
        $sessions = AgentTaskSession::query()
            ->where('container_name', $handle)
            ->where('agent_id', $agentId)
            ->where('agent_machine_id', $machineId)
            ->live()
            ->get();

        foreach ($sessions as $session) {
            try {
                new FinalizeHarnessSessionAction($session, failureReason: $reason)->execute();
            } catch (Throwable $e) {
                // The runtime is already gone, so the finalizer cannot read a diff or a handoff out of
                // it and may well throw on the way past. The row must still stop saying "running".
                report($e);

                $session->status = HarnessStatusEnum::FAILED->value;
                $session->error_message = $reason;
                $session->saveOrFail();
            }
        }

        return $sessions->count();
    }
}
