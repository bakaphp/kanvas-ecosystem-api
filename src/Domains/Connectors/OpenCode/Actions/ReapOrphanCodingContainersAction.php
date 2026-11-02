<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenCode\Actions;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Kanvas\Connectors\OpenCode\Concerns\ListsAgentCodingContainers;
use Kanvas\Intelligence\AgentRuntime\Harness\Enums\HarnessEnum;
use Kanvas\Intelligence\AgentRuntime\Harness\Models\AgentTaskSession;
use Kanvas\Intelligence\AgentRuntime\SshClient;
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
 * updated, a worker killed mid-flight, a database restored from backup.
 *
 * Scoped to this harness throughout: every container (see `ListsAgentCodingContainers`) and every
 * workspace is checked against an opencode session on this machine before anything is removed.
 */
class ReapOrphanCodingContainersAction
{
    use ListsAgentCodingContainers;

    private const int WORKSPACE_RETENTION_HOURS = 24;
    private const int IDLE_GRACE_MINUTES = 60;

    /** Capped so one machine cannot hold the sweep open; the order is what makes the cap safe. */
    private const int BATCH = 200;

    /** @var array{home: int, total: int, available: int} */
    private const array UNMEASURED = ['home' => 0, 'total' => 0, 'available' => 0];

    public function __construct(
        private readonly AgentMachine $machine,
        private readonly bool $dryRun = false,
    ) {
    }

    /**
     * @return array{containers: list<string>, workspaces: list<string>, disk: array{home: int, total: int, available: int}}
     */
    public function execute(): array
    {
        $client = $this->machine->connectSsh();
        $removedContainers = [];
        $removedWorkspaces = [];
        $disk = self::UNMEASURED;

        try {
            foreach ($this->orphanContainers($client) as $containerName) {
                $removedContainers[] = $containerName;

                if (! $this->dryRun) {
                    $client->exec('docker rm -f ' . escapeshellarg($containerName) . ' 2>&1 || true', 120);
                }
            }

            foreach ($this->expiredSessions() as $session) {
                // Only `workspace_path` — a HOST path we created. `session_data_path` is the same
                // directory as the CONTAINER sees it (`/workspaces/<uuid>`), and running that through
                // `rm -rf` on the host aims at a path that is not ours to touch.
                $path = (string) $session->workspace_path;

                if (! $this->isReapablePath($path)) {
                    continue;
                }

                $removedWorkspaces[] = $path;

                if (! $this->dryRun) {
                    $client->exec('rm -rf ' . escapeshellarg($path) . ' 2>&1 || true', 120);
                    $session->workspace_reaped_at = Carbon::now();
                    $session->saveQuietly();
                }
            }
            $disk = $this->measureDisk($client, $this->agentDirectories());
        } catch (Throwable $e) {
            report($e);
        } finally {
            $client->disconnect();
        }

        return [
            'containers' => $removedContainers,
            'workspaces' => $removedWorkspaces,
            'disk' => $disk,
        ];
    }

    /**
     * Containers are labelled by AGENT, so "orphan" means the agent has nothing live in flight — not
     * that one task ended. A container whose agent finished recently is also kept, so the next task
     * does not pay a cold start.
     *
     * @return list<string> container names
     */
    private function orphanContainers(SshClient $client): array
    {
        $found = $this->agentContainers($client, $this->machine);
        $busy = $this->busyAgentIds(array_keys($found), self::IDLE_GRACE_MINUTES);

        return array_values(array_map(
            static fn (array $container): string => $container['name'],
            array_diff_key($found, array_flip($busy))
        ));
    }

    /**
     * What the coding runtime is holding on this machine, after the sweep.
     *
     * Measured every run so there is a series to look at rather than one anxious `du` during an
     * incident. `.home` is reported separately because it is opencode's own session store: nothing
     * reclaims it, it is the largest single item, and the decision about it is deliberately deferred
     * until a few weeks of these numbers exist.
     *
     * Best-effort. A failure to measure must never fail a sweep that already did its work.
     *
     * @param list<string> $agentDirs
     * @return array{home: int, total: int, available: int}
     */
    private function measureDisk(SshClient $client, array $agentDirs): array
    {
        if ($agentDirs === []) {
            return self::UNMEASURED;
        }

        $quoted = implode(' ', array_map('escapeshellarg', $agentDirs));
        $homes = implode(' ', array_map(
            static fn (string $dir): string => escapeshellarg($dir . '/worktrees/.home'),
            $agentDirs
        ));

        // Labelled, not positional. A `du` that prints nothing would shift every later reading up a
        // line, and this number is meant to be trusted across weeks of samples — a silently wrong
        // series is worse than a missing one.
        try {
            $output = (string) $client->exec(
                'echo "total:$(du -sbc ' . $quoted . ' 2>/dev/null | tail -1 | cut -f1)"; '
                . 'echo "home:$(du -sbc ' . $homes . ' 2>/dev/null | tail -1 | cut -f1)"; '
                . 'echo "available:$(df -B1 --output=avail ' . escapeshellarg($agentDirs[0])
                . ' 2>/dev/null | tail -1 | tr -d \' \')"',
                120
            );
        } catch (Throwable $e) {
            report($e);

            return self::UNMEASURED;
        }

        $measured = self::UNMEASURED;

        foreach (preg_split('/\r?\n/', trim($output)) ?: [] as $line) {
            [$key, $value] = array_pad(explode(':', trim($line), 2), 2, '');

            if (array_key_exists($key, $measured)) {
                $measured[$key] = (int) $value;
            }
        }

        return $measured;
    }

    /**
     * The agent directories this machine holds, derived from the checkouts rather than from config:
     * `<root>/agents/<id>/worktrees/<uuid>` — up two levels is the agent's own directory.
     *
     * @return list<string>
     */
    private function agentDirectories(): array
    {
        return $this->machineSessions()
            ->whereNotNull('workspace_path')
            ->distinct()
            ->pluck('workspace_path')
            ->map(static fn (mixed $path): string => dirname((string) $path, 2))
            ->filter(static fn (string $dir): bool => str_starts_with($dir, '/') && mb_strlen($dir) > 1)
            ->unique()
            ->values()
            ->all();
    }

    private function machineSessions(): Builder
    {
        return AgentTaskSession::query()->onMachine($this->machine->getId(), HarnessEnum::OPENCODE);
    }

    /**
     * A last check before `rm -rf`, because the argument comes from a database column.
     *
     * The provisioner only ever writes `<root>/agents/<id>/worktrees/<uuid>`, so anything else is a
     * corrupted row, a hand-edit or a column that has been repurposed — and none of those are worth
     * finding out about by deleting the wrong directory on a customer's server. A row that fails this
     * is skipped and stays unreaped, which is the recoverable mistake.
     */
    private function isReapablePath(string $path): bool
    {
        return str_starts_with($path, '/')
            && str_contains($path, '/worktrees/')
            && ! str_contains($path, '..')
            && mb_strlen(rtrim($path, '/')) > mb_strlen('/worktrees/');
    }

    /**
     * @return Collection<int, AgentTaskSession>
     */
    private function expiredSessions(): Collection
    {
        return AgentTaskSession::query()
            ->workspaceReapable(
                $this->machine->getId(),
                self::WORKSPACE_RETENTION_HOURS,
                HarnessEnum::OPENCODE
            )
            ->limit(self::BATCH)
            ->get();
    }
}
