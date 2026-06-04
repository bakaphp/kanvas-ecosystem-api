<?php

declare(strict_types=1);

namespace App\Console\Commands\Lead;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\FollowUp\Actions\BuildLeadFollowUpDailySummaryAction;
use Throwable;

/**
 * Daily rollup per (app, company). 'Yesterday' is per-tenant timezone, so a
 * UTC fire still aggregates the correct local-day data.
 */
class LeadFollowUpDailySummaryCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'lead:follow-up-daily-summary';

    protected $description = 'Emit yesterday\'s follow-up activity rollup ledger event per (app, company).';

    public function handle(): int
    {
        $apps = Apps::query()->get();

        foreach ($apps as $app) {
            if (! (bool) $app->get('use_lead_follow_up_v2')) {
                continue;
            }

            $this->overwriteAppService($app);

            foreach ($app->companies()->cursor() as $company) {
                try {
                    new BuildLeadFollowUpDailySummaryAction(
                        app: $app,
                        company: $company,
                        forDate: Carbon::yesterday($company->timezone ?? 'UTC'),
                    )->execute();
                } catch (Throwable $e) {
                    // Don't let one tenant's failure block the rest of the batch.
                    report($e);
                }
            }
        }

        return self::SUCCESS;
    }
}
