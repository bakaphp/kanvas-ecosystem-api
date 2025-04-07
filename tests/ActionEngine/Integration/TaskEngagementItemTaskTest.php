<?php

declare(strict_types=1);

namespace Tests\ActionEngine\Integration;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Kanvas\ActionEngine\Actions\Models\Action;
use Kanvas\ActionEngine\Actions\Models\CompanyAction;
use Kanvas\ActionEngine\Tasks\Models\TaskEngagementItem;
use Kanvas\ActionEngine\Tasks\Models\TaskList;
use Kanvas\ActionEngine\Tasks\Models\TaskListItem;
use Kanvas\ActionEngine\Tasks\Repositories\TaskEngagementItemRepository;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Tests\TestCase;

final class TaskEngagementItemTaskTest extends TestCase
{
    public function testGetLeadTaskItems(): void
    {
        $company = auth()->user()->getCurrentCompany();

        /**
         * @todo move to factory
         */
        $people = new People();
        $people->users_id = auth()->user()->getId();
        $people->companies_id = $company->getId();
        $people->name = 'Test People';
        $people->saveOrFail();

        $lead = new Lead();
        $lead->companies_id = $company->getId();
        $lead->companies_branches_id = $company->branch()->firstOrFail()->getId();
        $lead->users_id = auth()->user()->getId();
        $lead->people_id = $people->getId();
        $lead->title = 'Test Lead';
        $lead->leads_receivers_id = 0;
        $lead->leads_owner_id = $lead->users_id;
        $lead->saveOrFail();

        $leadTaskItems = TaskEngagementItemRepository::getLeadsTaskItems($lead);

        $this->assertInstanceOf(Builder::class, $leadTaskItems);
        $this->assertIsArray($leadTaskItems->get()->toArray());
    }

    private function createAction(array $attributes): Action
    {
        return Action::firstOrCreate(array_merge([
            'companies_id' => 0,
            'apps_id' => 0,
            'users_id' => 0,
            'pipelines_id' => 1,
            'is_active' => 1,
            'is_published' => 1,
            'is_deleted' => 0,
        ], $attributes));
    }

    private function createCompanyAction(array $attributes): CompanyAction
    {
        return CompanyAction::firstOrCreate(array_merge([
            'pipelines_id' => 1,
            'is_active' => 1,
            'is_published' => 1,
            'weight' => 1,
            'is_deleted' => 0,
        ], $attributes));
    }

    private function createTaskList(array $attributes): TaskList
    {
        return TaskList::firstOrCreate($attributes);
    }

    private function createTaskListItem(array $attributes): TaskListItem
    {
        return TaskListItem::firstOrCreate($attributes);
    }

    /**
     * @todo this test suck ass we need to use factory but for now we got test
     */
    public function testTaskListDisableRelatedItems(): void
    {
        $company = auth()->user()->getCurrentCompany();
        $app = app(Apps::class);

        $lead = Lead::factory()->create();
        $actions = $this->createAction([
            'name' => 'Test Action',
            'slug' => 'test-action',
        ]);
        $actionTwo = $this->createAction([
            'name' => 'Test Action2',
            'slug' => 'test-action2',
        ]);

        $companyAction = $this->createCompanyAction([
            'companies_id' => $company->getId(),
            'companies_branches_id' => $company->branch()->firstOrFail()->getId(),
            'apps_id' => $app->getId(),
            'users_id' => auth()->user()->getId(),
            'actions_id' => $actions->getId(),
            'name' => 'Test Company Action',
        ]);

        $companyActionTwo = $this->createCompanyAction([
            'companies_id' => $company->getId(),
            'companies_branches_id' => $company->branch()->firstOrFail()->getId(),
            'apps_id' => $app->getId(),
            'users_id' => auth()->user()->getId(),
            'actions_id' => $actionTwo->getId(),
            'name' => 'Test Company Action2',
        ]);

        $taskList = $this->createTaskList([
            'companies_id' => $company->getId(),
            'apps_id' => $app->getId(),
            'users_id' => auth()->user()->getId(),
            'name' => 'Test Task List',
        ]);

        $taskListItem = $this->createTaskListItem([
            'task_list_id' => $taskList->getId(),
            'companies_action_id' => $companyAction->getId(),
            'name' => 'Test Task List Item 1',
            'config' => [],
            'weight' => 1,
        ]);

        $taskListItemTwo = $this->createTaskListItem([
            'task_list_id' => $taskList->getId(),
            'companies_action_id' => $companyActionTwo->getId(),
            'name' => 'Test Task List Item 2',
            'config' => [
                'other_items_to_disable' => [$taskListItem->getId()],
            ],
            'weight' => 1,
        ]);

        $taskEngagementItem = new TaskEngagementItem();
        $taskEngagementItem->task_list_item_id = $taskListItemTwo->getId();
        $taskEngagementItem->lead_id = $lead->getId();
        $taskEngagementItem->companies_id = $company->getId();
        $taskEngagementItem->apps_id = $app->getId();
        $taskEngagementItem->users_id = 1;
        $taskEngagementItem->status = 'no_applicable';
        $taskEngagementItem->saveOrFail();

        $taskEngagementItem->disableRelatedItems();

        $this->assertCount(2, TaskEngagementItem::where('status', 'no_applicable')->fromCompany($company)->where('lead_id', $lead->getId())->get());
    }

    /**
     * @todo this test suck ass we need to use factory but for now we got test
     */
    public function testTaskListEnableRelatedTaskItems(): void
    {
        $company = auth()->user()->getCurrentCompany();
        $app = app(Apps::class);

        $lead = Lead::factory()->create();
        $actions = $this->createAction([
            'name' => 'Test Action',
            'slug' => 'test-action',
        ]);
        $actionTwo = $this->createAction([
            'name' => 'Test Action2',
            'slug' => 'test-action2',
        ]);

        $companyAction = $this->createCompanyAction([
            'companies_id' => $company->getId(),
            'companies_branches_id' => $company->branch()->firstOrFail()->getId(),
            'apps_id' => $app->getId(),
            'users_id' => auth()->user()->getId(),
            'actions_id' => $actions->getId(),
            'name' => 'Test Company Action',
        ]);

        $companyActionTwo = $this->createCompanyAction([
            'companies_id' => $company->getId(),
            'companies_branches_id' => $company->branch()->firstOrFail()->getId(),
            'apps_id' => $app->getId(),
            'users_id' => auth()->user()->getId(),
            'actions_id' => $actionTwo->getId(),
            'name' => 'Test Company Action2',
        ]);

        $taskList = $this->createTaskList([
            'companies_id' => $company->getId(),
            'apps_id' => $app->getId(),
            'users_id' => auth()->user()->getId(),
            'name' => 'Test Task List',
            'config' => [],
        ]);

        $taskListItem = $this->createTaskListItem([
            'task_list_id' => $taskList->getId(),
            'companies_action_id' => $companyAction->getId(),
            'name' => 'Test Task List Item 1',
            'config' => [],
            'weight' => 1,
        ]);

        $taskListItemTwo = $this->createTaskListItem([
            'task_list_id' => $taskList->getId(),
            'companies_action_id' => $companyActionTwo->getId(),
            'name' => 'Test Task List Item 2',
            'config' => [
                'disabled' => true,
            ],
            'weight' => 1,
        ]);

        $taskList->config = [
            'task_item_to_enable' => [
                $taskListItemTwo->getId() => [
                    $taskListItem->getId(),
                ],
            ],
        ];
        $taskList->saveOrFail();

        $taskEngagementItem = new TaskEngagementItem();
        $taskEngagementItem->task_list_item_id = $taskListItem->getId();
        $taskEngagementItem->lead_id = $lead->getId();
        $taskEngagementItem->companies_id = $company->getId();
        $taskEngagementItem->apps_id = $app->getId();
        $taskEngagementItem->users_id = 1;
        $taskEngagementItem->status = 'completed';
        $taskEngagementItem->saveOrFail();

        $taskEngagementItem->enableRelatedTasks();

        $this->assertFalse(
            TaskEngagementItem::fromApp($app)
            ->fromCompany($company)
            ->where('lead_id', $lead->getId())
            ->where('task_list_item_id', $taskListItemTwo->getId())
            ->first()
            ->config['disabled']
        );
    }

    /**
     * @todo this test suck ass we need to use factory but for now we got test
     */
    public function testItemCompleteRelatedItems(): void
    {
        $company = auth()->user()->getCurrentCompany();
        $app = app(Apps::class);

        $lead = Lead::factory()->create();
        $actions = $this->createAction([
            'name' => 'Test Action',
            'slug' => 'test-action',
        ]);
        $actionTwo = $this->createAction([
            'name' => 'Test Action2',
            'slug' => 'test-action2',
        ]);

        $companyAction = $this->createCompanyAction([
            'companies_id' => $company->getId(),
            'companies_branches_id' => $company->branch()->firstOrFail()->getId(),
            'apps_id' => $app->getId(),
            'users_id' => auth()->user()->getId(),
            'actions_id' => $actions->getId(),
            'name' => 'Test Company Action',
        ]);

        $companyActionTwo = $this->createCompanyAction([
            'companies_id' => $company->getId(),
            'companies_branches_id' => $company->branch()->firstOrFail()->getId(),
            'apps_id' => $app->getId(),
            'users_id' => auth()->user()->getId(),
            'actions_id' => $actionTwo->getId(),
            'name' => 'Test Company Action2',
        ]);

        $taskList = $this->createTaskList([
            'companies_id' => $company->getId(),
            'apps_id' => $app->getId(),
            'users_id' => auth()->user()->getId(),
            'name' => 'Test Task List',
            'config' => [],
        ]);

        $taskListItem = $this->createTaskListItem([
            'task_list_id' => $taskList->getId(),
            'companies_action_id' => $companyAction->getId(),
            'name' => 'Test Task List Item 1',
            'config' => [],
            'weight' => 1,
        ]);

        $taskListItemTwo = $this->createTaskListItem([
            'task_list_id' => $taskList->getId(),
            'companies_action_id' => $companyActionTwo->getId(),
            'name' => 'Test Task List Item 2',
            'config' => [
                'complete_other_task_items' => [$taskListItem->getId()],
            ],
            'weight' => 1,
        ]);

        $taskEngagementItem = new TaskEngagementItem();
        $taskEngagementItem->task_list_item_id = $taskListItemTwo->getId();
        $taskEngagementItem->lead_id = $lead->getId();
        $taskEngagementItem->companies_id = $company->getId();
        $taskEngagementItem->apps_id = $app->getId();
        $taskEngagementItem->users_id = 1;
        $taskEngagementItem->status = 'completed';
        $taskEngagementItem->saveOrFail();

        $taskEngagementItem->completeRelatedItems();

        $this->assertCount(2, TaskEngagementItem::where('status', 'completed')->fromCompany($company)->where('lead_id', $lead->getId())->get());
    }
}
