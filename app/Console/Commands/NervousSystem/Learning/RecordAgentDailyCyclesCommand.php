<?php

declare(strict_types=1);

namespace App\Console\Commands\NervousSystem\Learning;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Actions\RecordAgentDailyCycleAction;
use Kanvas\Intelligence\Agents\Models\Agent;
use Throwable;

/**
 * Run from a cron (typically at the agent's morning wake time, e.g. 06:00 daily).
 * For every active agent, compiles the previous day's ledger activity into a
 * single `agent_daily_cycles` row + its child `agent_sleep_phases`. Idempotent —
 * re-running on the same date overwrites the row in place.
 */
class RecordAgentDailyCyclesCommand extends Command
{
    use KanvasJobsTrait;
    protected $signature = 'kanvas:nervous-system:record-agent-cycles
        {--agent= : Restrict to a single agent id}
        {--date= : ISO date (Y-m-d) for the cycle to record. Defaults to today.}';

    protected $description = 'Compile yesterday\'s ledger activity into per-agent daily-cycle journals.';

    public function handle(): int
    {
        // Cycle row represents a fully-elapsed day's activity, never today.
        $cycleDate = $this->option('date') !== null
            ? Carbon::parse((string) $this->option('date'))
            : Carbon::yesterday();

        $query = Agent::query()
            ->where('is_active', 1)
            ->where('is_deleted', 0);

        if ($this->option('agent') !== null) {
            $query->where('id', (int) $this->option('agent'));
        }

        $processed = 0;
        $failed = 0;

        $query->chunkById(50, function ($agents) use ($cycleDate, &$processed, &$failed): void {
            foreach ($agents as $agent) {
                /** @var Apps $app */
                $app = Apps::getById($agent->apps_id);
                $this->overwriteAppService($app);

                try {
                    $cycle = new RecordAgentDailyCycleAction($agent, $cycleDate)->execute();
                    $processed++;
                    $this->line(sprintf(
                        '  agent=%-5d %-30s events=%-4d proactive=%-3d phases=%d',
                        $agent->getId(),
                        substr((string) $agent->name, 0, 30),
                        $cycle->events_processed_count,
                        $cycle->proactive_actions_count,
                        $cycle->phases->count(),
                    ));
                } catch (Throwable $e) {
                    $failed++;
                    report($e);
                    $this->error(sprintf('  agent=%d failed: %s', $agent->getId(), $e->getMessage()));
                }
            }
        });

        $this->info(sprintf(
            'Done. Recorded %d cycles for %s. Failures: %d',
            $processed,
            $cycleDate->toDateString(),
            $failed,
        ));

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
