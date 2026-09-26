<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenCode\Actions;

use Illuminate\Database\Eloquent\Builder;
use Kanvas\Approvals\Enums\ApprovalStatusEnum;
use Kanvas\Approvals\Models\ApprovalRequest;
use Kanvas\Intelligence\AgentRuntime\Harness\Actions\RequestPushApprovalAction;
use Kanvas\Intelligence\AgentRuntime\Harness\Models\AgentTaskSession;
use Kanvas\Intelligence\Agents\Models\AgentMachine;
use Throwable;

/**
 * Retires idle coding containers on a machine, and the workspaces of sessions that ended long ago.
 *
 * **This is the normal way a container ends.** Finishing a job deliberately leaves the runtime up —
 * someone is usually about to read the diff or approve the push, and both need it. So the decision to
 * stop one belongs here, where the question is "is this agent still doing anything?" rather than "did
 * this task finish?".
 *
 * It also covers the disorderly paths: a launch that crashed between `docker run` and the row being
 * updated, a worker killed mid-flight, a database restored from backup. Containers are found by the
 * `kanvas.agent` **label**, not by name or published port — neither of which survives a partial launch.
 */
class ReapOrphanCodingContainersAction
{
    private const int WORKSPACE_RETENTION_HOURS = 24;
    private const int IDLE_GRACE_MINUTES = 60;

    public function __construct(
        private readonly AgentMachine $machine,
        private readonly bool $dryRun = false,
    ) {
    }

    /**
     * @return array{containers: list<string>, workspaces: list<string>}
     */
    public function execute(): array
    {
        $client = $this->machine->connectSsh();
        $removedContainers = [];
        $removedWorkspaces = [];

        try {
            foreach ($this->orphanContainers($client) as $containerName) {
                $removedContainers[] = $containerName;

                if (! $this->dryRun) {
                    $client->exec('docker rm -f ' . escapeshellarg($containerName) . ' 2>&1 || true', 120);
                }
            }

            foreach ($this->expiredWorkspaces() as $path) {
                $removedWorkspaces[] = $path;

                if (! $this->dryRun) {
                    // Scoped to the session directory we created; never a configured root.
                    $client->exec('rm -rf ' . escapeshellarg($path) . ' 2>&1 || true', 120);
                }
            }
        } catch (Throwable $e) {
            report($e);
        } finally {
            $client->disconnect();
        }

        return ['containers' => $removedContainers, 'workspaces' => $removedWorkspaces];
    }

    /**
     * Containers are labelled by AGENT, so "orphan" means the agent has nothing live in flight — not
     * that one task ended. A container whose agent still has a running session is in use by it.
     *
     * @return array<string, string> agent id => container name
     */
    private function orphanContainers(object $client): array
    {
        $output = $client->exec(
            'docker ps -a --filter label=kanvas.agent --format ' . escapeshellarg('{{.Names}}|{{.Label "kanvas.agent"}}'),
            60
        );

        $found = [];

        foreach (preg_split('/\r?\n/', trim((string) $output)) ?: [] as $line) {
            if (! str_contains($line, '|')) {
                continue;
            }

            $parts = explode('|', $line, 2);
            $agentId = trim($parts[1] ?? '');
            $name = trim($parts[0]);

            if ($agentId !== '') {
                $found[$agentId] = $name;
            }
        }

        if ($found === []) {
            return [];
        }

        // Three reasons to leave a container alone, and only the first is obvious:
        //   - a session is running in it;
        //   - the agent finished recently, and the next task would otherwise pay a cold start;
        //   - a push is still waiting on a human. That one can sit for hours, and the container is
        //     where the diff lives — reaping it turns "show me the change" into "No such container"
        //     at exactly the moment someone is deciding whether to approve it.
        $busy = AgentTaskSession::query()
            ->whereIn('agent_id', array_keys($found))
            ->where(
                fn (Builder $query): Builder => $query->live()
                    ->orWhere('heartbeat_at', '>', now()->subMinutes(self::IDLE_GRACE_MINUTES))
                    ->orWhereIn('task_id', $this->tasksAwaitingPush())
            )
            ->pluck('agent_id')
            ->all();

        return array_diff_key($found, array_flip(array_map('strval', $busy)));
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

    /**
     * @return list<string>
     */
    private function expiredWorkspaces(): array
    {
        $sessions = AgentTaskSession::query()
            ->where('agent_machine_id', $this->machine->getId())
            ->whereNotNull('completed_at')
            ->where('completed_at', '<', now()->subHours(self::WORKSPACE_RETENTION_HOURS))
            ->whereNotNull('workspace_path')
            ->limit(200)
            ->get();

        $paths = [];

        foreach ($sessions as $session) {
            foreach ([$session->workspace_path, $session->session_data_path] as $path) {
                if (is_string($path) && $path !== '') {
                    $paths[] = $path;
                }
            }
        }

        return $paths;
    }
}
