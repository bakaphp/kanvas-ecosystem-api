<?php

declare(strict_types=1);

namespace Tests\ActionEngine\Integration;

use Illuminate\Support\Facades\Queue;
use Kanvas\ActionEngine\Actions\Models\Action;
use Kanvas\ActionEngine\Actions\Models\CompanyAction;
use Kanvas\ActionEngine\Engagements\Models\Engagement;
use Kanvas\ActionEngine\Pipelines\Models\Pipeline;
use Kanvas\ActionEngine\Pipelines\Models\PipelineStage;
use Kanvas\ActionEngine\Tasks\Actions\UpdateTaskStatusFromEngagementAction;
use Kanvas\ActionEngine\Tasks\Models\TaskEngagementItem;
use Kanvas\ActionEngine\Tasks\Models\TaskList;
use Kanvas\ActionEngine\Tasks\Models\TaskListItem;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Models\Lead;
use Tests\TestCase;

final class UpdateTaskStatusFromEngagementActionTest extends TestCase
{
    private CompanyAction $companyAction;
    private TaskList $taskList;

    /**
     * Regression (Sentry KANVAS-ECOSYSTEM-5N6): with no task list items the action used to
     * answer with an assoc `['success' => false, ...]` array. OfacScreeningActivity treated the
     * result as a list, so `count() > 0` passed and `current()->getId()` fatalled on `false`.
     */
    public function testReturnsEmptyListWhenCompanyActionHasNoTaskListItems(): void
    {
        $engagement = $this->bootEngagement();

        $result = new UpdateTaskStatusFromEngagementAction(
            engagement: $engagement,
            status: 'completed',
        )->execute();

        $this->assertSame([], $result);
        $this->assertNull(($result[0] ?? null)?->getId());
    }

    public function testReturnsOneTaskEngagementItemPerTaskListItem(): void
    {
        $engagement = $this->bootEngagement();

        $taskListItem = TaskListItem::create([
            'task_list_id' => $this->taskList->getId(),
            'companies_action_id' => $this->companyAction->getId(),
            'name' => 'OFAC Screening',
            'config' => [],
            'weight' => 1,
        ]);

        $result = new UpdateTaskStatusFromEngagementAction(
            engagement: $engagement,
            status: 'completed',
        )->execute();

        $this->assertCount(1, $result);
        $this->assertInstanceOf(TaskEngagementItem::class, $result[0]);
        $this->assertSame($taskListItem->getId(), $result[0]->task_list_item_id);
        $this->assertSame('completed', $result[0]->status);
        $this->assertNotNull(($result[0] ?? null)?->getId());
    }

    private function bootEngagement(): Engagement
    {
        Queue::fake();

        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $app = app(Apps::class);

        $pipeline = Pipeline::create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'users_id' => $user->getId(),
            'name' => 'OFAC Pipeline ' . fake()->uuid(),
            'slug' => 'ofac-pipeline-' . fake()->uuid(),
            'weight' => 0,
        ]);

        $stage = PipelineStage::create([
            'pipelines_id' => $pipeline->getId(),
            'name' => 'Sent',
            'slug' => 'sent',
            'weight' => 1,
        ]);

        $action = Action::firstOrCreate(
            ['slug' => 'ofac-screening'],
            [
                'companies_id' => 0,
                'apps_id' => 0,
                'users_id' => 0,
                'pipelines_id' => $pipeline->getId(),
                'name' => 'OFAC Screening',
                'is_active' => 1,
                'is_published' => 1,
                'is_deleted' => 0,
            ],
        );

        $this->companyAction = CompanyAction::create([
            'companies_id' => $company->getId(),
            'companies_branches_id' => $company->branch()->firstOrFail()->getId(),
            'apps_id' => $app->getId(),
            'users_id' => $user->getId(),
            'actions_id' => $action->getId(),
            'name' => 'OFAC Screening',
            'pipelines_id' => $pipeline->getId(),
            'is_active' => 1,
            'is_published' => 1,
            'weight' => 1,
            'is_deleted' => 0,
        ]);

        $this->taskList = TaskList::create([
            'companies_id' => $company->getId(),
            'apps_id' => $app->getId(),
            'users_id' => $user->getId(),
            'name' => 'OFAC Checklist ' . fake()->uuid(),
            'config' => [],
        ]);

        $lead = Lead::factory()
            ->withAppAndCompany($app->getId(), $company->getId())
            ->create();

        return Engagement::create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'users_id' => $user->getId(),
            'companies_actions_id' => $this->companyAction->getId(),
            'message_id' => 0,
            'leads_id' => $lead->getId(),
            'people_id' => $lead->people_id,
            'pipelines_stages_id' => $stage->getId(),
            'entity_uuid' => fake()->uuid(),
            'slug' => 'ofac-engagement-' . fake()->uuid(),
        ]);
    }
}
