<?php

declare(strict_types=1);

namespace Kanvas\ActionEngine\Actions\Actions;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Kanvas\ActionEngine\Actions\Models\Action;
use Kanvas\ActionEngine\Actions\Models\CompanyAction;
use Kanvas\ActionEngine\Pipelines\Models\Pipeline;
use Kanvas\Companies\Models\CompaniesBranches;

/**
 * Attaches an action to a company's branches as `companies_actions` rows.
 *
 * App, company and user come off the pipeline, which carries all three — passing them separately
 * would let a caller hand in a company that disagrees with the pipeline the row points at. The
 * action stays a parameter because it is a global catalog row (`companies_id = 0`) whose `apps_id`
 * is whichever app first seeded it, so it cannot name the tenant.
 *
 * Branches are handed in rather than queried: the caller already needs the list to report on, and
 * resolving it twice would let the two copies drift on which branches count as live.
 *
 * Writing the pipeline itself is the caller's job — Setup builds its own with per-action stage
 * templates and stage-message copy that CreatePipelineAction has no concept of.
 */
class AttachActionToCompanyBranchesAction
{
    /**
     * @param Collection<int, CompaniesBranches> $branches
     */
    public function __construct(
        protected readonly Action $action,
        protected readonly Pipeline $pipeline,
        protected readonly Collection $branches,
        protected readonly bool $isActive = true,
    ) {
    }

    /**
     * @return Collection<int, CompanyAction>
     */
    public function execute(): Collection
    {
        $app = $this->pipeline->app;
        $company = $this->pipeline->company;
        $user = $this->pipeline->user;

        return DB::connection('action_engine')->transaction(
            function () use ($app, $company, $user): Collection {
                $rows = new Collection();

                foreach ($this->branches as $branch) {
                    $companyAction = CompanyAction::firstOrCreate(
                        [
                            'actions_id' => $this->action->getId(),
                            'apps_id' => $app->getId(),
                            'companies_id' => $company->getId(),
                            'companies_branches_id' => $branch->getId(),
                            'is_deleted' => 0,
                        ],
                        [
                            'users_id' => $user->getId(),
                            'pipelines_id' => $this->pipeline->getId(),
                            'name' => $this->action->name,
                            'description' => $this->action->description,
                            'form_config' => $this->action->form_config,
                            'is_active' => $this->isActive ? 1 : 0,
                            'is_published' => $this->action->is_published,
                        ]
                    );

                    if ((int) $companyAction->pipelines_id === 0) {
                        $companyAction->pipelines_id = $this->pipeline->getId();
                        $companyAction->saveOrFail();
                    }

                    $rows->push($companyAction);
                }

                return $rows;
            }
        );
    }
}
