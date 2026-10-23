<?php

declare(strict_types=1);

namespace App\Console\Commands\Lead;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\FollowUp\Actions\BuildLeadFollowUpDailySummaryAction;
use Kanvas\Intelligence\FollowUp\Actions\SendLeadFollowUpDailySummaryAction;
use Throwable;

/**
 * Daily rollup per (app, company). 'Yesterday' is per-tenant timezone, so a
 * UTC fire still aggregates the correct local-day data. The rollup is emailed
 * to the company's `lead_follow_up_summary_recipients` when it has any.
 */
class LeadFollowUpDailySummaryCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'lead:follow-up-daily-summary {--app_id=} {--company_id=}';

    protected $description = 'Emit yesterday\'s follow-up activity rollup ledger event per (app, company) and email it.';

    public function handle(): int
    {
        $appId = $this->option('app_id');
        $companyId = $this->option('company_id');

        $apps = Apps::query()
            ->when(filled($appId), fn ($query) => $query->where('id', (int) $appId))
            ->get();

        foreach ($apps as $app) {
            if (! (bool) $app->get('use_lead_follow_up_v2')) {
                continue;
            }

            $this->overwriteAppService($app);

            $companies = $app->companies()
                ->when(filled($companyId), fn ($query) => $query->where('companies.id', (int) $companyId))
                ->cursor();

            foreach ($companies as $company) {
                try {
                    $summary = new BuildLeadFollowUpDailySummaryAction(
                        app: $app,
                        company: $company,
                        forDate: Carbon::yesterday($company->timezone ?? 'UTC'),
                    )->execute();

                    $recipients = new SendLeadFollowUpDailySummaryAction($app, $company, $summary)->execute();
                    if ($recipients !== []) {
                        $this->info(sprintf(
                            'Sent app=%d company=%d to %s',
                            $app->getId(),
                            $company->getId(),
                            implode(', ', $recipients)
                        ));
                    }
                } catch (Throwable $e) {
                    // Don't let one tenant's failure block the rest of the batch.
                    report($e);
                }
            }
        }

        return self::SUCCESS;
    }
}
