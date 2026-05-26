<?php

declare(strict_types=1);

namespace App\Console\Commands\NervousSystem;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\NervousSystem\DailyLearning\Actions\EnumerateAgentsForDailyLearningAction;
use Kanvas\NervousSystem\DailyLearning\Actions\SummarizeAgentDailyLearningAction;
use Kanvas\NervousSystem\DailyLearning\Jobs\SummarizeAgentDailyLearningJob;
use Kanvas\NervousSystem\DailyLearning\Services\CycleWindowResolverService;
use Throwable;

/**
 * Daily fan-out: for every (app, company) tuple with active agents, find the
 * agents that produced conversation activity on $date and dispatch a per-agent
 * SummarizeAgentDailyLearningJob onto the agent-runtime queue.
 *
 * Typically scheduled 06:30 daily — after RecordAgentDailyCyclesCommand at 06:04
 * has written the deterministic counters. The summarize action overwrites
 * morning_briefing on the same row.
 *
 * Stepwise rollout flags:
 *   --dry-run    runs the LLM call but skips DB writes, runtime push, ledger emit
 *   --skip-push  persists + emits ledger, but skips the runtime memory push
 *   --sync       runs the action inline instead of queueing (debug + smoke test)
 */
class SummarizeAgentDailyLearningCommand extends Command
{
    protected $signature = 'kanvas:nervous-system:summarize-agent-daily-learning
        {--agent= : Restrict to a single agent id}
        {--app= : Restrict to a single apps_id}
        {--company= : Restrict to a single companies_id}
        {--date= : ISO date (Y-m-d) for the cycle to summarize. Defaults to yesterday.}
        {--model=gemini-2.5-pro : LLM model id passed to Laravel AI}
        {--dry-run : Run LLM but skip DB write, runtime push, ledger emit}
        {--skip-push : Persist + emit ledger but skip runtime memory push}
        {--sync : Run inline instead of dispatching to queue}';

    protected $description = 'LLM-summarize yesterday\'s conversations per agent and push durable facts back into the agent\'s memory bank.';

    public function handle(): int
    {
        $dateOverride = $this->option('date');
        $dryRun = (bool) $this->option('dry-run');
        $skipPush = (bool) $this->option('skip-push');
        $sync = (bool) $this->option('sync');
        $model = (string) $this->option('model');

        $tuples = $this->resolveTenantTuples();
        if ($tuples === []) {
            $this->info('No active agents matched the filters.');
            return self::SUCCESS;
        }

        $dispatched = 0;
        $failed = 0;
        $lastCycleLabel = null;

        foreach ($tuples as [$appId, $companyId]) {
            try {
                /** @var Apps $app */
                $app = Apps::getById($appId);
                /** @var Companies $company */
                $company = Companies::getById($companyId);
            } catch (Throwable $e) {
                $this->warn(sprintf('apps_id=%d companies_id=%d → tenant resolution failed: %s', $appId, $companyId, $e->getMessage()));
                $failed++;
                continue;
            }

            // Compute cycle date PER tenant in the company's own TZ so the
            // cron's "yesterday" matches each agent's local calendar, not
            // the app TZ. A west-of-UTC tenant would otherwise be summarized
            // for the wrong day half (or skipped if cron fires before their
            // midnight).
            $tenantTz = CycleWindowResolverService::resolveTimezone($app, $company);
            $cycleDate = is_string($dateOverride) && $dateOverride !== ''
                ? Carbon::parse($dateOverride, $tenantTz)
                : Carbon::yesterday($tenantTz);
            $lastCycleLabel = $cycleDate->toDateString();

            $agents = new EnumerateAgentsForDailyLearningAction($app, $company, $cycleDate)->execute();

            if ($this->option('agent') !== null) {
                $agentId = (int) $this->option('agent');
                $agents = $agents->filter(fn (Agent $a) => $a->getId() === $agentId)->values();
            }

            if ($agents->isEmpty()) {
                continue;
            }

            foreach ($agents as $agent) {
                /** @var Agent $agent */
                try {
                    if ($sync) {
                        new SummarizeAgentDailyLearningAction(
                            $agent,
                            $app,
                            $company,
                            $cycleDate,
                            $dryRun,
                            $skipPush,
                            $model,
                        )->execute();
                    } else {
                        SummarizeAgentDailyLearningJob::dispatch(
                            $agent,
                            $app,
                            $company,
                            $cycleDate->toIso8601String(),
                            $dryRun,
                            $skipPush,
                            $model,
                        );
                    }

                    $dispatched++;
                    $this->line(sprintf(
                        '  app=%-3d company=%-4d agent=%-5d %s',
                        $app->getId(),
                        $company->getId(),
                        $agent->getId(),
                        substr((string) $agent->name, 0, 40),
                    ));
                } catch (Throwable $e) {
                    $failed++;
                    report($e);
                    $this->error(sprintf('  agent=%d failed: %s', $agent->getId(), $e->getMessage()));
                }
            }
        }

        // Cycle label is per-tenant now; show the last one processed (or
        // the explicit override) so the summary line stays informative
        // without claiming a single date for all tenants.
        $this->info(sprintf(
            'Done. %s %d agent(s) for %s. Failures: %d',
            $sync ? 'Processed' : 'Dispatched',
            $dispatched,
            $lastCycleLabel ?? 'n/a',
            $failed,
        ));

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Resolves unique (apps_id, companies_id) tuples that hold active agents,
     * optionally filtered by --app / --company. Cheaper than per-agent app
     * lookups and lets the per-tenant enumerate-then-fan-out path reuse the
     * same dayStart/dayEnd window once.
     *
     * @return list<array{0: int, 1: int}>
     */
    private function resolveTenantTuples(): array
    {
        $query = Agent::query()
            ->where('is_active', 1)
            ->where('is_deleted', 0);

        if ($this->option('app') !== null) {
            $query->where('apps_id', (int) $this->option('app'));
        }

        if ($this->option('company') !== null) {
            $query->where('companies_id', (int) $this->option('company'));
        }

        return $query
            ->select('apps_id', 'companies_id')
            ->distinct()
            ->get()
            ->map(fn ($row) => [(int) $row->apps_id, (int) $row->companies_id])
            ->all();
    }
}
