<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenCode\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Kanvas\Approvals\Enums\ApprovalStatusEnum;
use Kanvas\Approvals\Models\ApprovalRequest;
use Kanvas\Intelligence\AgentRuntime\Harness\Actions\RequestPushApprovalAction;
use Kanvas\Intelligence\AgentRuntime\Harness\Enums\HarnessEnum;
use Kanvas\Intelligence\AgentRuntime\Harness\Models\AgentTaskSession;
use Kanvas\Intelligence\AgentRuntime\SshClient;
use Kanvas\Intelligence\Agents\Models\AgentMachine;

/**
 * The coding containers on a machine, and which of them are still in use.
 *
 * Containers are found by the `kanvas.agent` **label**, not by name or published port — neither of
 * which survives a partial launch. The label names an agent, not a runtime: nothing stops a future
 * runtime adopting the same label, and a stale row on a rebuilt box could carry any id. An agent with
 * an opencode session on THIS machine is the thing that can actually be verified, so anything else is
 * not ours and is left running. Forgetting one costs disk; touching someone else's costs their work.
 */
trait ListsAgentCodingContainers
{
    /**
     * `{{.Image}}` is the tag the container was started with — or, once that tag has been rebuilt and
     * points at a different image, the bare id. Either way it differs from the current tag, which is
     * exactly what "stale" means.
     *
     * @return array<int, array{name: string, image: string}> agent id => container
     */
    private function agentContainers(SshClient $client, AgentMachine $machine): array
    {
        $output = $client->exec(
            'docker ps -a --filter label=kanvas.agent --format '
            . escapeshellarg('{{.Names}}|{{.Label "kanvas.agent"}}|{{.Image}}'),
            60
        );

        $found = [];

        foreach (preg_split('/\r?\n/', trim((string) $output)) ?: [] as $line) {
            $parts = array_map('trim', explode('|', $line, 3));

            if (count($parts) < 2 || ! ctype_digit($parts[1])) {
                continue;
            }

            $found[(int) $parts[1]] = ['name' => $parts[0], 'image' => $parts[2] ?? ''];
        }

        if ($found === []) {
            return [];
        }

        return array_intersect_key($found, array_flip($this->codingAgentIds($machine, array_keys($found))));
    }

    /**
     * Of the agent ids found on containers, the ones this harness has actually run on this machine.
     *
     * @param list<int> $agentIds
     * @return list<int>
     */
    private function codingAgentIds(AgentMachine $machine, array $agentIds): array
    {
        return AgentTaskSession::query()
            ->onMachine($machine->getId(), HarnessEnum::OPENCODE)
            ->whereIn('agent_id', $agentIds)
            ->distinct()
            ->pluck('agent_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }

    /**
     * Agents whose container must be left alone. Two reasons always, and only the first is obvious:
     *   - a session is running in it;
     *   - a push is still waiting on a human. That one can sit for hours, and the container is where
     *     the diff lives — removing it turns "show me the change" into "No such container" at exactly
     *     the moment someone is deciding whether to approve it.
     * With a grace period, an agent that finished recently also counts, so the next task does not pay
     * a cold start.
     *
     * @param list<int> $agentIds
     * @return list<int>
     */
    private function busyAgentIds(array $agentIds, ?int $idleGraceMinutes = null): array
    {
        if ($agentIds === []) {
            return [];
        }

        return AgentTaskSession::query()
            ->whereIn('agent_id', $agentIds)
            ->where(function (Builder $query) use ($idleGraceMinutes): void {
                $query->live()->orWhereIn('task_id', $this->tasksAwaitingPush());

                if ($idleGraceMinutes !== null) {
                    $query->orWhere('heartbeat_at', '>', now()->subMinutes($idleGraceMinutes));
                }
            })
            ->distinct()
            ->pluck('agent_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }

    /**
     * @return list<int>
     */
    private function tasksAwaitingPush(): array
    {
        return ApprovalRequest::query()
            ->where('approval_type', RequestPushApprovalAction::APPROVAL_TYPE)
            ->where('status', ApprovalStatusEnum::PENDING->value)
            ->pluck('entity_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }
}
