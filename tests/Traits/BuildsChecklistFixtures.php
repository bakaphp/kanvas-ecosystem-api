<?php

declare(strict_types=1);

namespace Tests\Traits;

use Illuminate\Support\Str;
use Kanvas\ActionEngine\Actions\Models\Action;
use Kanvas\ActionEngine\Actions\Models\CompanyAction;
use Kanvas\ActionEngine\Engagements\Models\Engagement;
use Kanvas\ActionEngine\Tasks\Models\TaskList;
use Kanvas\ActionEngine\Tasks\Models\TaskListItem;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Models\Lead;

/**
 * @todo replace with factories once the ActionEngine models have them
 */
trait BuildsChecklistFixtures
{
    /**
     * @return array{action: Action, companyAction: CompanyAction, taskList: TaskList, taskListItem: TaskListItem}
     */
    protected function makeChecklistWiring(Lead $lead, string $slug): array
    {
        $app = app(Apps::class);
        $company = $lead->company;

        $action = Action::firstOrCreate([
            'companies_id' => 0,
            'apps_id' => 0,
            'users_id' => 0,
            'pipelines_id' => 1,
            'slug' => $slug,
            'name' => 'Checklist Fixture ' . $slug,
            'is_active' => 1,
            'is_published' => 1,
            'is_deleted' => 0,
        ]);

        $companyAction = CompanyAction::firstOrCreate([
            'companies_id' => $company->getId(),
            'companies_branches_id' => $company->branch()->firstOrFail()->getId(),
            'apps_id' => $app->getId(),
            'users_id' => auth()->user()->getId(),
            'actions_id' => $action->getId(),
            'pipelines_id' => 1,
            'name' => 'Checklist Fixture ' . $slug,
            'is_active' => 1,
            'is_published' => 1,
            'weight' => 1,
            'is_deleted' => 0,
        ]);

        $taskList = TaskList::firstOrCreate([
            'companies_id' => $company->getId(),
            'apps_id' => $app->getId(),
            'users_id' => auth()->user()->getId(),
            'name' => 'Checklist Fixture List',
        ]);

        $taskListItem = TaskListItem::firstOrCreate([
            'task_list_id' => $taskList->getId(),
            'companies_action_id' => $companyAction->getId(),
            'name' => 'Checklist Fixture Item ' . $slug,
            'config' => [],
            'weight' => 1,
        ]);

        return [
            'action' => $action,
            'companyAction' => $companyAction,
            'taskList' => $taskList,
            'taskListItem' => $taskListItem,
        ];
    }

    protected function makeChecklistEngagement(
        Lead $lead,
        CompanyAction $companyAction,
        string $slug,
        int $messageId = 0
    ): Engagement {
        return Engagement::firstOrCreate([
            'companies_id' => $lead->company->getId(),
            'apps_id' => app(Apps::class)->getId(),
            'users_id' => auth()->user()->getId(),
            'leads_id' => $lead->getId(),
            'people_id' => $lead->people_id,
            'companies_actions_id' => $companyAction->getId(),
            'message_id' => $messageId,
            'slug' => $slug,
            'entity_uuid' => Str::uuid()->toString(),
            'pipelines_stages_id' => 0,
        ]);
    }
}
