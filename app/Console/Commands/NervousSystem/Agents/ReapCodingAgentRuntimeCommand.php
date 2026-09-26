<?php

declare(strict_types=1);

namespace App\Console\Commands\NervousSystem\Agents;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Kanvas\Connectors\OpenCode\Actions\ReapOrphanCodingContainersAction;
use Kanvas\Intelligence\AgentRuntime\Harness\Enums\HarnessEnum;
use Kanvas\Intelligence\AgentRuntime\Harness\Models\AgentTaskSession;
use Kanvas\Intelligence\Agents\Models\AgentMachine;
use Throwable;

/**
 * Retires idle coding containers and the checkouts of long-finished sessions, machine by machine.
 *
 * Separate from `kanvas:coding:sweep-sessions` on purpose. That one runs every five minutes because a
 * wedged session burns money and holds a concurrency slot; this is housekeeping, and running it at the
 * same cadence would fire a couple of hundred `rm -rf` calls over SSH every five minutes to delete
 * things that were already gone.
 *
 * Nothing else reclaims this. Left alone, a machine accumulates a checkout per task until its disk
 * fills — which surfaces as unrelated services failing on a shared box, not as a coding error.
 */
class ReapCodingAgentRuntimeCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:coding:reap
        {--machine= : Only this machine id}
        {--dry-run : List what would be removed without touching anything}';

    protected $description = 'Remove idle coding containers and expired session workspaces.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $machines = $this->machines();

        if ($machines->isEmpty()) {
            $this->info('No machine has hosted a coding session; nothing to reap.');

            return self::SUCCESS;
        }

        $containers = 0;
        $workspaces = 0;

        foreach ($machines as $machine) {
            // Per machine, not once: the reaper reads sessions and approvals, and both are
            // Bouncer/app-scoped. A leaked scope from the previous iteration silently returns nothing,
            // so the sweep would report a clean machine it never actually looked at.
            $app = $machine->app;

            if ($app !== null) {
                $this->overwriteAppService($app);
            }

            try {
                $result = new ReapOrphanCodingContainersAction($machine, $dryRun)->execute();
            } catch (Throwable $e) {
                report($e);
                $this->error($machine->name . ': ' . $e->getMessage());

                continue;
            }

            $containers += count($result['containers']);
            $workspaces += count($result['workspaces']);

            foreach ($result['containers'] as $name) {
                $this->line($machine->name . '  container  ' . $name);
            }

            foreach ($result['workspaces'] as $path) {
                $this->line($machine->name . '  workspace  ' . $path);
            }

            $this->reportDisk($machine, $result['disk']);
        }

        $this->info(sprintf(
            '%s %d container(s) and %d workspace(s) across %d machine(s).',
            $dryRun ? 'Would remove' : 'Removed',
            $containers,
            $workspaces,
            $machines->count(),
        ));

        return self::SUCCESS;
    }

    /**
     * Printed and logged every run, so the question "is this growing?" has a series behind it rather
     * than one `du` taken during an incident.
     *
     * `.home` is called out separately because it is opencode's own session store: nothing reclaims
     * it, it is the largest single item, and the decision about what to do with it is deliberately
     * deferred until there are a few weeks of these numbers — see the Harness CLAUDE.md.
     *
     * @param array{home: int, total: int, available: int} $disk
     */
    private function reportDisk(AgentMachine $machine, array $disk): void
    {
        $line = sprintf(
            '%s  disk  total=%s  opencode_home=%s  free=%s',
            $machine->name,
            $this->humanBytes($disk['total']),
            $this->humanBytes($disk['home']),
            $this->humanBytes($disk['available']),
        );

        $this->line($line);

        Log::info('coding.runtime.disk', [
            'machine_id' => $machine->getId(),
            'machine' => $machine->name,
            'total_bytes' => $disk['total'],
            'home_bytes' => $disk['home'],
            'available_bytes' => $disk['available'],
        ]);
    }

    private function humanBytes(int $bytes): string
    {
        if ($bytes <= 0) {
            return '?';
        }

        $units = ['B', 'K', 'M', 'G', 'T'];
        $power = min((int) floor(log($bytes, 1024)), count($units) - 1);

        return round($bytes / 1024 ** $power, 1) . $units[$power];
    }

    /**
     * Only machines a coding session has actually run on.
     *
     * "Every active machine" looks like the safe reading and is not: this database holds 1434 of them
     * against a single machine that has ever hosted a session, because test fixtures commit on a
     * non-default connection and survive their run. Each one costs a full SSH timeout, serially — so
     * the sweep would never finish, and `withoutOverlapping()` would then block every later run
     * permanently. The session rows are the honest index of where there is anything to reap, and
     * `agent_machine_id` is written before the container is created so a crashed launch is still
     * covered.
     *
     * @return Collection<int, AgentMachine>
     */
    private function machines(): Collection
    {
        $machineIds = AgentTaskSession::query()
            ->where('harness', HarnessEnum::OPENCODE->value)
            ->whereNotNull('agent_machine_id')
            ->distinct()
            ->pluck('agent_machine_id');

        $query = AgentMachine::query()->notDeleted()->whereIn('id', $machineIds);

        if ($this->option('machine') !== null) {
            $query->where('id', (int) $this->option('machine'));
        }

        return $query->get();
    }
}
