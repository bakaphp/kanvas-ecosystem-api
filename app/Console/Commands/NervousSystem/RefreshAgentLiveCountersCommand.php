<?php

declare(strict_types=1);

namespace App\Console\Commands\NervousSystem;

use Illuminate\Console\Command;
use Kanvas\Intelligence\Agents\Actions\RefreshAgentLiveCountersAction;
use Kanvas\Intelligence\Agents\Models\Agent;
use Throwable;

/**
 * Hourly refresh for the volatile counters on today's agent_daily_cycles row:
 * events_processed_count, proactive_actions_count, awake_duration_minutes.
 *
 * Complements the daily 06:04 RecordAgentDailyCyclesCommand which writes the
 * full row (narrative, skills, sleep phases). This one only touches numbers
 * that change as the day progresses, so the dashboard HEARTBEAT card and the
 * INITIATED TODAY card don't go stale for 24 hours.
 */
class RefreshAgentLiveCountersCommand extends Command
{
    protected $signature = 'kanvas:nervous-system:refresh-agent-counters
        {--agent= : Restrict to a single agent id}';

    protected $description = 'Refresh today\'s volatile counters on agent_daily_cycles for awake agents.';

    public function handle(): int
    {
        $query = Agent::query()
            ->where('is_active', 1)
            ->where('is_deleted', 0)
            ->where('awake_state', 'awake');

        if ($this->option('agent') !== null) {
            $query->where('id', (int) $this->option('agent'));
        }

        $refreshed = 0;
        $skipped = 0;
        $failed = 0;

        $query->chunkById(100, function ($agents) use (&$refreshed, &$skipped, &$failed): void {
            foreach ($agents as $agent) {
                try {
                    $result = new RefreshAgentLiveCountersAction($agent)->execute();
                    if ($result === null) {
                        $skipped++;
                    } else {
                        $refreshed++;
                    }
                } catch (Throwable $e) {
                    $failed++;
                    report($e);
                    $this->error(sprintf('  agent=%d failed: %s', $agent->getId(), $e->getMessage()));
                }
            }
        });

        $this->info(sprintf(
            'Refreshed %d · skipped %d (no cycle row yet) · failed %d',
            $refreshed,
            $skipped,
            $failed,
        ));

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
