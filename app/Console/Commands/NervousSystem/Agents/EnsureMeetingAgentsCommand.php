<?php

declare(strict_types=1);

namespace App\Console\Commands\NervousSystem\Agents;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\NervousSystem\Orchestrator\Routing\Actions\EnsureMeetingAgentSettingAction;
use Kanvas\NervousSystem\Orchestrator\Routing\Enums\MeetingAgentEnum;
use Kanvas\NervousSystem\Orchestrator\Routing\Services\MeetingAgentLocatorService;
use Throwable;

/**
 * Backfills meeting-agent settings without replacing a live manual choice.
 */
class EnsureMeetingAgentsCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:nervous-system:ensure-meeting-agents
        {--app= : Only this app id}
        {--company= : Only this company id}
        {--dry-run : Show what each company would get without writing}';

    protected $description = 'Point each company\'s meeting_lead_agent_id / meeting_organization_agent_id at its first Sales Manager / PM agent.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $locator = new MeetingAgentLocatorService();
        $failed = 0;

        foreach ($this->tenants() as $appId => $companyIds) {
            $app = Apps::getById($appId);
            $this->overwriteAppService($app);

            foreach (Companies::query()->whereIn('id', $companyIds)->orderBy('id')->get() as $company) {
                foreach (MeetingAgentEnum::cases() as $role) {
                    try {
                        $agent = $dryRun
                            ? $locator->resolve($role, $app, $company)
                            : new EnsureMeetingAgentSettingAction($app, $company, $role)->execute();

                        $this->line(sprintf(
                            '  %sapp %d company %d %s → %s',
                            $dryRun ? 'would set: ' : '',
                            $app->getId(),
                            $company->getId(),
                            $role->value,
                            $agent !== null ? 'agent ' . $agent->getId() : 'none',
                        ));
                    } catch (Throwable $e) {
                        $failed++;
                        $this->error(sprintf(
                            '  app %d company %d %s FAILED: %s',
                            $app->getId(),
                            $company->getId(),
                            $role->value,
                            $e->getMessage(),
                        ));
                    }
                }
            }
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * The (app, company) pairs that have an agent of either meeting role.
     *
     * @return array<int, list<int>> company ids keyed by app id
     */
    private function tenants(): array
    {
        $tenants = [];

        foreach (MeetingAgentEnum::cases() as $role) {
            $rows = Agent::query()
                ->notDeleted()
                ->where('companies_id', '>', 0)
                ->ofHandler($role->handler())
                ->when(
                    $this->option('app') !== null,
                    fn (Builder $query): Builder => $query->where('apps_id', (int) $this->option('app')),
                )
                ->when(
                    $this->option('company') !== null,
                    fn (Builder $query): Builder => $query->where('companies_id', (int) $this->option('company')),
                )
                ->distinct()
                ->toBase()
                ->get(['apps_id', 'companies_id']);

            foreach ($rows as $row) {
                $tenants[(int) $row->apps_id][(int) $row->companies_id] = (int) $row->companies_id;
            }
        }

        return array_map(array_values(...), $tenants);
    }
}
