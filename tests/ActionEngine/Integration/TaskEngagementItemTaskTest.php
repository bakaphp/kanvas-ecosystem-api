<?php

declare(strict_types=1);

namespace Tests\ActionEngine\Integration;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Kanvas\ActionEngine\Actions\Models\Action;
use Kanvas\ActionEngine\Actions\Models\CompanyAction;
use Kanvas\ActionEngine\Tasks\Actions\ChangeTaskEngagementItemStatusAction;
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

    public function testGetOrCreateTaskEngagementItemRecoversFromCrossScopeOrphan(): void
    {
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $app = app(Apps::class);

        $lead = Lead::factory()->create();

        $action = $this->createAction([
            'name' => 'Test Action ' . fake()->uuid(),
            'slug' => 'test-action-' . fake()->uuid(),
        ]);

        $companyAction = $this->createCompanyAction([
            'companies_id' => $company->getId(),
            'companies_branches_id' => $company->branch()->firstOrFail()->getId(),
            'apps_id' => $app->getId(),
            'users_id' => $user->getId(),
            'actions_id' => $action->getId(),
            'name' => 'Test Company Action',
        ]);

        $taskList = $this->createTaskList([
            'companies_id' => $company->getId(),
            'apps_id' => $app->getId(),
            'users_id' => $user->getId(),
            'name' => 'Test Task List ' . fake()->uuid(),
        ]);

        $taskListItem = $this->createTaskListItem([
            'task_list_id' => $taskList->getId(),
            'companies_action_id' => $companyAction->getId(),
            'name' => 'Test Item ' . fake()->uuid(),
            'config' => [],
            'weight' => 1,
        ]);

        // Pre-existing row with the same (task_list_item_id, lead_id) but a
        // different scope — invisible to the company/app-scoped SELECT, but
        // still trips the global unique key on INSERT.
        $orphan = new TaskEngagementItem();
        $orphan->task_list_item_id = $taskListItem->getId();
        $orphan->lead_id = $lead->getId();
        $orphan->companies_id = $company->getId() + 1;
        $orphan->apps_id = $app->getId();
        $orphan->users_id = $user->getId();
        $orphan->status = 'pending';
        $orphan->saveOrFail();

        $result = new ChangeTaskEngagementItemStatusAction(
            taskListItem: $taskListItem,
            lead: $lead,
            status: 'completed',
            user: $user,
            app: $app,
            company: $company,
        )->execute();

        $this->assertEquals('completed', $result->status);
        $this->assertSame(
            1,
            TaskEngagementItem::where('task_list_item_id', $taskListItem->getId())
                ->where('lead_id', $lead->getId())
                ->count(),
        );
    }

    public function testCompleteSiblingChecklistItemsRecoversFromCrossScopeOrphan(): void
    {
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $app = app(Apps::class);

        $company->set('checklist_auto_complete_siblings', true);

        $lead = Lead::factory()->create();

        $action = $this->createAction([
            'name' => 'Trade In Action ' . fake()->uuid(),
            'slug' => 'trade-in-action-' . fake()->uuid(),
        ]);

        $companyAction = $this->createCompanyAction([
            'companies_id' => $company->getId(),
            'companies_branches_id' => $company->branch()->firstOrFail()->getId(),
            'apps_id' => $app->getId(),
            'users_id' => $user->getId(),
            'actions_id' => $action->getId(),
            'name' => 'Trade In',
        ]);

        $taskListA = $this->createTaskList([
            'companies_id' => $company->getId(),
            'apps_id' => $app->getId(),
            'users_id' => $user->getId(),
            'name' => 'Checklist A ' . fake()->uuid(),
        ]);

        $taskListB = $this->createTaskList([
            'companies_id' => $company->getId(),
            'apps_id' => $app->getId(),
            'users_id' => $user->getId(),
            'name' => 'Checklist B ' . fake()->uuid(),
        ]);

        $sharedName = 'Trade In ' . fake()->uuid();

        $itemInA = $this->createTaskListItem([
            'task_list_id' => $taskListA->getId(),
            'companies_action_id' => $companyAction->getId(),
            'name' => $sharedName,
            'config' => [],
            'weight' => 1,
        ]);

        $itemInB = $this->createTaskListItem([
            'task_list_id' => $taskListB->getId(),
            'companies_action_id' => $companyAction->getId(),
            'name' => $sharedName,
            'config' => [],
            'weight' => 1,
        ]);

        // Sibling already has an engagement row from a different scope, which
        // is what produced the production duplicate-key violation.
        $orphanSibling = new TaskEngagementItem();
        $orphanSibling->task_list_item_id = $itemInB->getId();
        $orphanSibling->lead_id = $lead->getId();
        $orphanSibling->companies_id = $company->getId() + 1;
        $orphanSibling->apps_id = $app->getId();
        $orphanSibling->users_id = $user->getId();
        $orphanSibling->status = 'pending';
        $orphanSibling->saveOrFail();

        $result = new ChangeTaskEngagementItemStatusAction(
            taskListItem: $itemInA,
            lead: $lead,
            status: 'completed',
            user: $user,
            app: $app,
            company: $company,
        )->execute();

        $this->assertEquals('completed', $result->status);
        $this->assertSame(
            1,
            TaskEngagementItem::where('task_list_item_id', $itemInB->getId())
                ->where('lead_id', $lead->getId())
                ->count(),
        );

        $sibling = TaskEngagementItem::where('task_list_item_id', $itemInB->getId())
            ->where('lead_id', $lead->getId())
            ->first();
        $this->assertEquals('completed', $sibling->status);

        $company->del('checklist_auto_complete_siblings');
    }

    public function testCompleteSiblingChecklistItems(): void
    {
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $app = app(Apps::class);

        // Enable the feature flag
        $company->set('checklist_auto_complete_siblings', true);

        $lead = Lead::factory()->create();

        $action = $this->createAction([
            'name' => 'Trade In Action',
            'slug' => 'trade-in-action',
        ]);

        $companyAction = $this->createCompanyAction([
            'companies_id' => $company->getId(),
            'companies_branches_id' => $company->branch()->firstOrFail()->getId(),
            'apps_id' => $app->getId(),
            'users_id' => $user->getId(),
            'actions_id' => $action->getId(),
            'name' => 'Trade In',
        ]);

        // Create two separate checklists
        $taskListA = $this->createTaskList([
            'companies_id' => $company->getId(),
            'apps_id' => $app->getId(),
            'users_id' => $user->getId(),
            'name' => 'Checklist A ' . fake()->uuid(),
        ]);

        $taskListB = $this->createTaskList([
            'companies_id' => $company->getId(),
            'apps_id' => $app->getId(),
            'users_id' => $user->getId(),
            'name' => 'Checklist B ' . fake()->uuid(),
        ]);

        // Same name + same companies_action_id in both checklists
        $itemInA = $this->createTaskListItem([
            'task_list_id' => $taskListA->getId(),
            'companies_action_id' => $companyAction->getId(),
            'name' => 'Trade In',
            'config' => [],
            'weight' => 1,
        ]);

        $itemInB = $this->createTaskListItem([
            'task_list_id' => $taskListB->getId(),
            'companies_action_id' => $companyAction->getId(),
            'name' => 'Trade In',
            'config' => [],
            'weight' => 1,
        ]);

        // Complete item in checklist A via the action
        $result = new ChangeTaskEngagementItemStatusAction(
            taskListItem: $itemInA,
            lead: $lead,
            status: 'completed',
            user: $user,
            app: $app,
            company: $company,
        )->execute();

        $this->assertEquals('completed', $result->status);

        // Item in checklist B should also be completed
        $siblingEngagement = TaskEngagementItem::fromCompany($company)
            ->fromApp($app)
            ->where('task_list_item_id', $itemInB->getId())
            ->where('lead_id', $lead->getId())
            ->first();

        $this->assertNotNull($siblingEngagement);
        $this->assertEquals('completed', $siblingEngagement->status);

        // Clean up
        $company->del('checklist_auto_complete_siblings');
    }
}
