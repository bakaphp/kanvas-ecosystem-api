<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\SalesAssist\Supervisor;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\SalesAssist\Supervisor\Jobs\RunSupervisorReviewJob;
use Kanvas\Connectors\SalesAssist\Supervisor\Neuron\SupervisorSaAgent;

/**
 * Hourly entry point: one job per active Supervisor SA agent. The clock here is UTC; each job decides
 * in the dealer's own time, same as lead:dispatch-follow-ups.
 */
class DispatchSupervisorReviewsCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:salesassist:dispatch-supervisor-reviews';

    protected $description = 'Hourly tick: queue a review for every active Supervisor SA agent.';

    public function handle(): int
    {
        foreach (SupervisorSaAgent::activeAgents() as $agent) {
            $app = Apps::getById($agent->apps_id);
            $this->overwriteAppService($app);

            $company = Companies::find($agent->companies_id);
            if ($company === null) {
                continue;
            }

            RunSupervisorReviewJob::dispatch($app, $company, $agent);
        }

        return self::SUCCESS;
    }
}
