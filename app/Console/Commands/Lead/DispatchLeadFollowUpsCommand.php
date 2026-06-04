<?php

declare(strict_types=1);

namespace App\Console\Commands\Lead;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\FollowUp\Jobs\DispatchAppLeadFollowUpsJob;
use Kanvas\Intelligence\Tools\CompanyWorkHoursTool;
use Throwable;

/**
 * Hourly entry point. Walks `use_lead_follow_up_v2` apps; per active company,
 * checks CompanyWorkHoursTool; if open, fans out via DispatchAppLeadFollowUpsJob.
 *
 * overwriteAppService() is required when iterating apps — Bouncer + container
 * Apps leak across tenants without it (see feedback_overwrite_app_service_when_iterating_apps).
 */
class DispatchLeadFollowUpsCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'lead:dispatch-follow-ups';

    protected $description = 'Hourly tick: fan out follow-up jobs to every app+company inside its work-hours window.';

    public function handle(): int
    {
        $apps = Apps::query()->get();

        foreach ($apps as $app) {
            if (! $this->isFollowUpEnabled($app)) {
                continue;
            }

            $this->overwriteAppService($app);

            foreach ($app->companies()->cursor() as $company) {
                if (! $this->insideWorkHours($company)) {
                    continue;
                }

                DispatchAppLeadFollowUpsJob::dispatch($app, $company)
                    ->onQueue('lead_follow_ups');
            }
        }

        return self::SUCCESS;
    }

    private function isFollowUpEnabled(Apps $app): bool
    {
        return (bool) $app->get('use_lead_follow_up_v2');
    }

    private function insideWorkHours(object $company): bool
    {
        try {
            $tool = new CompanyWorkHoursTool($company);
            $result = $tool->execute();
        } catch (Throwable $e) {
            report($e);

            return false;
        }

        return ($result['status'] ?? null) === 'open';
    }
}
