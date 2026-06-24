<?php

declare(strict_types=1);

namespace Tests\GraphQL\ActionEngine;

use Kanvas\ActionEngine\Actions\Models\Action;
use Kanvas\ActionEngine\Actions\Models\CompanyAction;
use Kanvas\ActionEngine\Tasks\Models\TaskEngagementItem;
use Kanvas\ActionEngine\Tasks\Models\TaskList;
use Kanvas\ActionEngine\Tasks\Models\TaskListItem;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Messages\Models\Message;
use Tests\TestCase;

final class TaskEngagementMutationTest extends TestCase
{
    private const MUTATION = /** @lang GraphQL */ '
        mutation($id: ID!, $lead_id: ID!, $status: String!, $message_id: ID, $config: Mixed) {
            changeTaskEngagementItemStatus(
                id: $id
                lead_id: $lead_id
                status: $status
                message_id: $message_id
                config: $config
            )
        }
    ';

    private function createAction(): Action
    {
        return Action::firstOrCreate([
            'companies_id' => 0,
            'apps_id' => 0,
            'users_id' => 0,
            'pipelines_id' => 1,
            'is_active' => 1,
            'is_published' => 1,
            'is_deleted' => 0,
            'name' => 'Test Action ' . fake()->uuid(),
            'slug' => 'test-action-' . fake()->uuid(),
        ]);
    }

    private function createCompanyAction(Action $action): CompanyAction
    {
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $app = app(Apps::class);

        return CompanyAction::firstOrCreate([
            'companies_id' => $company->getId(),
            'companies_branches_id' => $company->branch()->firstOrFail()->getId(),
            'apps_id' => $app->getId(),
            'users_id' => $user->getId(),
            'actions_id' => $action->getId(),
            'name' => 'Test Company Action',
            'pipelines_id' => 1,
            'is_active' => 1,
            'is_published' => 1,
            'weight' => 1,
            'is_deleted' => 0,
        ]);
    }

    private function createTaskList(): TaskList
    {
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $app = app(Apps::class);

        return TaskList::firstOrCreate([
            'companies_id' => $company->getId(),
            'apps_id' => $app->getId(),
            'users_id' => $user->getId(),
            'name' => 'Test Task List ' . fake()->uuid(),
        ]);
    }

    private function createTaskListItem(TaskList $taskList, CompanyAction $companyAction, array $overrides = []): TaskListItem
    {
        return TaskListItem::firstOrCreate(array_merge([
            'task_list_id' => $taskList->getId(),
            'companies_action_id' => $companyAction->getId(),
            'name' => 'Test Item ' . fake()->uuid(),
            'config' => [],
            'weight' => 1,
        ], $overrides));
    }

    public function testChangeTaskEngagementItemStatusMarksCompleted(): void
    {
        $company = auth()->user()->getCurrentCompany();
        $app = app(Apps::class);

        $lead = Lead::factory()
            ->withAppAndCompany($app->getId(), $company->getId())
            ->create();
        $action = $this->createAction();
        $companyAction = $this->createCompanyAction($action);
        $taskList = $this->createTaskList();
        $taskListItem = $this->createTaskListItem($taskList, $companyAction);

        $response = $this->graphQL(self::MUTATION, [
            'id' => (string) $taskListItem->getId(),
            'lead_id' => (string) $lead->getId(),
            'status' => 'completed',
        ])->assertJson([
            'data' => [
                'changeTaskEngagementItemStatus' => true,
            ],
        ]);

        $this->assertTrue($response->json('data.changeTaskEngagementItemStatus'));

        $taskEngagementItem = TaskEngagementItem::fromCompany($company)
            ->fromApp($app)
            ->where('task_list_item_id', $taskListItem->getId())
            ->where('lead_id', $lead->getId())
            ->first();

        $this->assertNotNull($taskEngagementItem);
        $this->assertEquals('completed', $taskEngagementItem->status);
    }

    public function testChangeTaskEngagementItemStatusFansOutToSiblingsViaGetDocsMessage(): void
    {
        $company = auth()->user()->getCurrentCompany();
        $app = app(Apps::class);

        $company->set('checklist_auto_complete_siblings', true);

        $lead = Lead::factory()
            ->withAppAndCompany($app->getId(), $company->getId())
            ->create();
        $action = $this->createAction();
        $companyAction = $this->createCompanyAction($action);

        $taskListA = $this->createTaskList();
        $taskListB = $this->createTaskList();
        $taskListC = $this->createTaskList();

        $itemInA = $this->createTaskListItem($taskListA, $companyAction, [
            'name' => 'Bank Statements',
            'config' => ['id' => 9],
        ]);
        $siblingMatching = $this->createTaskListItem($taskListB, $companyAction, [
            'name' => 'Bank Statements',
            'config' => ['id' => 9],
        ]);
        $siblingDifferentId = $this->createTaskListItem($taskListC, $companyAction, [
            'name' => 'Bank Statements',
            'config' => ['id' => 10],
        ]);

        $message = Message::factory()->create([
            'message' => [
                'verb' => 'get-docs',
                'status' => 'submitted',
                'data' => [
                    '9' => ['type' => ['id' => 9, 'name' => 'Bank Statements']],
                ],
            ],
        ]);

        $this->graphQL(self::MUTATION, [
            'id' => (string) $itemInA->getId(),
            'lead_id' => (string) $lead->getId(),
            'status' => 'completed',
            'message_id' => (string) $message->getId(),
        ])->assertJson([
            'data' => [
                'changeTaskEngagementItemStatus' => true,
            ],
        ]);

        $matched = TaskEngagementItem::fromCompany($company)
            ->fromApp($app)
            ->where('task_list_item_id', $siblingMatching->getId())
            ->where('lead_id', $lead->getId())
            ->first();
        $this->assertNotNull($matched, 'Sibling with matching config.id should be auto-completed via the GraphQL path');
        $this->assertEquals('completed', $matched->status);

        $unmatched = TaskEngagementItem::fromCompany($company)
            ->fromApp($app)
            ->where('task_list_item_id', $siblingDifferentId->getId())
            ->where('lead_id', $lead->getId())
            ->first();
        $this->assertNull($unmatched, 'Sibling whose config.id is not in the message must not be touched');

        $company->del('checklist_auto_complete_siblings');
    }

    public function testChangeTaskEngagementItemStatusNoApplicableDisablesRelatedItems(): void
    {
        $company = auth()->user()->getCurrentCompany();
        $app = app(Apps::class);

        $lead = Lead::factory()
            ->withAppAndCompany($app->getId(), $company->getId())
            ->create();

        $action = $this->createAction();
        $companyAction = $this->createCompanyAction($action);
        $taskList = $this->createTaskList();

        // Item that will be auto-disabled when the parent goes no_applicable.
        $targetItem = $this->createTaskListItem($taskList, $companyAction, [
            'name' => 'Target Item ' . fake()->uuid(),
        ]);

        // Parent item declares that going no_applicable should disable the target.
        $parentItem = $this->createTaskListItem($taskList, $companyAction, [
            'name' => 'Parent Item ' . fake()->uuid(),
            'config' => ['other_items_to_disable' => [$targetItem->getId()]],
        ]);

        $this->graphQL(self::MUTATION, [
            'id' => (string) $parentItem->getId(),
            'lead_id' => (string) $lead->getId(),
            'status' => 'no_applicable',
        ])->assertJson([
            'data' => [
                'changeTaskEngagementItemStatus' => true,
            ],
        ]);

        $targetEngagement = TaskEngagementItem::fromCompany($company)
            ->fromApp($app)
            ->where('task_list_item_id', $targetItem->getId())
            ->where('lead_id', $lead->getId())
            ->first();
        $this->assertNotNull($targetEngagement, 'Target item should have an engagement created by disableRelatedItems');
        $this->assertEquals('no_applicable', $targetEngagement->status);
    }
}
