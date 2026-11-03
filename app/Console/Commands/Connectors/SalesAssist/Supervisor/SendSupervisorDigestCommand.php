<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\SalesAssist\Supervisor;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\SalesAssist\Supervisor\Neuron\SupervisorSaAgent;
use Kanvas\Connectors\SalesAssist\Supervisor\Services\SupervisorFindingLedger;
use Kanvas\Connectors\SalesAssist\Supervisor\Services\SupervisorFindingNotifier;
use Kanvas\Intelligence\Agents\Models\Agent;

/**
 * Daily summary of what opened in the last day and is still open, per dealer with a supervisor. A
 * dealer with nothing new sends nothing.
 */
class SendSupervisorDigestCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:salesassist:send-supervisor-digest';

    protected $description = 'Email the daily supervisor findings to the AgentDeveloper role.';

    public function handle(): int
    {
        $agents = Agent::query()
            ->where('is_active', 1)
            ->where('is_deleted', 0)
            ->whereHas('type', fn ($query) => $query->where('handler', SupervisorSaAgent::class))
            ->get();

        foreach ($agents as $agent) {
            $app = Apps::getById($agent->apps_id);
            $this->overwriteAppService($app);

            $company = Companies::find($agent->companies_id);
            if ($company === null) {
                continue;
            }

            new SupervisorFindingNotifier($app, $company)->digest(
                new SupervisorFindingLedger($app, $company)->openNonCriticalSince(Carbon::now()->subDay())
            );
        }

        return self::SUCCESS;
    }
}
