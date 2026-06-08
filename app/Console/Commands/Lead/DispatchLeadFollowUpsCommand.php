<?php

declare(strict_types=1);

namespace App\Console\Commands\Lead;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Apps\Repositories\AppsRepository;
use Kanvas\Companies\Models\Companies;
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
        $apps = Apps::disableCache()->notDeleted()->get();

        foreach ($apps as $app) {
            if (! $this->isFollowUpEnabled($app)) {
                //$this->info("Skipping app  {$app->id} - {$app->name} as follow-ups are disabled.");

                continue;
            }

            $this->info("Processing app {$app->id} - {$app->name}...");
            $this->overwriteAppService($app);

            $companies = AppsRepository::getActiveCompaniesForAppBuilder($app)->cursor();

            /** @var Companies $company */
            foreach ($companies as $company) {
                $this->info("Checking work hours for company {$company->id} - {$company->name}...");
                if (! $this->insideWorkHours($company)) {
                    $this->info("Company {$company->id} - {$company->name} is outside work hours. Skipping.");

                    continue;
                }

                $this->info("Dispatching follow-ups for app {$app->name} and company {$company->name}...");
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

    private function insideWorkHours(Companies $company): bool
    {
        try {
            $tool = new CompanyWorkHoursTool($company);
            $result = $tool->execute();
        } catch (Throwable $e) {
            report($e);

            return false;
        }

        return ($result['status'] ?? null) === 'work_hours';
    }
}
