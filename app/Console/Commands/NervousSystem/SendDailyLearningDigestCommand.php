<?php

declare(strict_types=1);

namespace App\Console\Commands\NervousSystem;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Models\AgentDailyCycle;
use Kanvas\NervousSystem\DailyLearning\Actions\SendDailyLearningDigestAction;
use Throwable;

/**
 * Per-company digest fan-out: for every (app, company) tuple that has at
 * least one AgentDailyCycle row for the date, dispatches the digest
 * notifications to AgentReport role members.
 *
 * Iterates the cycle table (not the agents table) because by the time we
 * run the digest the summarize step already wrote rows we want to email
 * out — we don't need to re-discover the tenant set from agents.
 *
 * Typically scheduled 07:30 daily — after the 06:30 summarize fan-out's
 * queue has drained.
 */
class SendDailyLearningDigestCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:nervous-system:send-daily-learning-digest
        {--app= : Restrict to a single apps_id}
        {--company= : Restrict to a single companies_id}
        {--date= : ISO date (Y-m-d) for the digest. Defaults to yesterday.}';

    protected $description = 'Email the daily agent-learning digest to AgentReport role members.';

    public function handle(): int
    {
        $cycleDate = $this->option('date') !== null
            ? Carbon::parse((string) $this->option('date'))
            : Carbon::yesterday();

        $cycleDateLabel = $cycleDate->toDateString();

        $tuplesQuery = AgentDailyCycle::query()
            ->where('cycle_date', $cycleDateLabel)
            ->where('is_deleted', 0)
            ->whereNotNull('morning_briefing');

        if ($this->option('app') !== null) {
            $tuplesQuery->where('apps_id', (int) $this->option('app'));
        }

        if ($this->option('company') !== null) {
            $tuplesQuery->where('companies_id', (int) $this->option('company'));
        }

        $tuples = $tuplesQuery
            ->select('apps_id', 'companies_id')
            ->distinct()
            ->get();

        if ($tuples->isEmpty()) {
            $this->info(sprintf('No AgentDailyCycle rows for %s — nothing to digest.', $cycleDateLabel));
            return self::SUCCESS;
        }

        $sentTotal = 0;
        $failed = 0;

        foreach ($tuples as $row) {
            $appId = (int) $row->apps_id;
            $companyId = (int) $row->companies_id;

            try {
                $app = Apps::getById($appId);
                $this->overwriteAppService($app);
                $company = Companies::getById($companyId);
            } catch (Throwable $e) {
                $this->warn(sprintf(
                    'apps_id=%d companies_id=%d → tenant resolution failed: %s',
                    $appId,
                    $companyId,
                    $e->getMessage(),
                ));
                $failed++;
                continue;
            }

            try {
                $sent = new SendDailyLearningDigestAction($app, $company, $cycleDate)->execute();
                $sentTotal += $sent;
                $this->line(sprintf(
                    '  app=%-3d company=%-4d recipients=%d',
                    $appId,
                    $companyId,
                    $sent,
                ));
            } catch (Throwable $e) {
                $failed++;
                report($e);
                $this->error(sprintf(
                    '  app=%d company=%d failed: %s',
                    $appId,
                    $companyId,
                    $e->getMessage(),
                ));
            }
        }

        $this->info(sprintf(
            'Done. Dispatched %d digest emails for %s across %d tenant(s). Failures: %d',
            $sentTotal,
            $cycleDateLabel,
            count($tuples),
            $failed,
        ));

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
