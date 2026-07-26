<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem\AddNervousSystemTaskTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem\UpdateNervousSystemTaskStatusTool;
use Kanvas\NervousSystem\Plan\Actions\CreatePlanAction;
use Kanvas\NervousSystem\Plan\DataTransferObject\Plan as PlanData;
use Kanvas\NervousSystem\Plan\DataTransferObject\Task as TaskData;
use Kanvas\NervousSystem\Plan\Enums\PlanStatusEnum;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\NervousSystem\Plan\Models\Task;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

class NervousSystemAgentToolsTest extends TestCase
{
    private function makePlanWithTask(Apps $app, $company, Users $user): Plan
    {
        return new CreatePlanAction(
            new PlanData(
                app: $app,
                company: $company,
                title: 'Tooling plan',
                planType: 'project_work',
                user: $user,
                status: PlanStatusEnum::ACTIVE,
            ),
            tasks: [new TaskData(plan: null, title: 'task one', sequence: 0)],
        )->execute();
    }

    public function testUpdateTaskStatusToolMovesTheTask(): void
    {
        $app = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $plan = $this->makePlanWithTask($app, $company, $user);
        /** @var Task $task */
        $task = $plan->tasks()->firstOrFail();

        $tool = new UpdateNervousSystemTaskStatusTool()->withContext($app, $company, $user);
        $result = $tool((int) $task->id, 'done', 'shipped it');

        $this->assertSame('done', $result['status']);
        $this->assertSame('done', Task::query()->where('id', $task->id)->value('status'));

        // The plan's completion rolled up (1/1 done → 100).
        $this->assertSame(100, (int) $plan->refresh()->completion_pct);
    }

    public function testAddTaskToolAddsATask(): void
    {
        $app = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $plan = $this->makePlanWithTask($app, $company, $user);

        $tool = new AddNervousSystemTaskTool()->withContext($app, $company, $user);
        $result = $tool((int) $plan->id, 'Design the landing page', 'above the fold first');

        $this->assertSame((int) $plan->id, (int) $result['plan_id']);
        $this->assertSame('Design the landing page', $result['title']);

        $this->assertGreaterThanOrEqual(
            2,
            Task::query()->where('plan_id', $plan->id)->notDeleted()->count(),
        );
    }
}
