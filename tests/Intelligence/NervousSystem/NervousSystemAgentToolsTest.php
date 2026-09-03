<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem\AddNervousSystemTaskTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem\AssignNervousSystemPlanTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem\AssignNervousSystemTaskTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem\CommentOnNervousSystemPlanTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem\CreateNervousSystemPlanTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem\DeleteNervousSystemPlanTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem\DeleteNervousSystemTaskTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem\FindAndAddNervousSystemMemberTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem\MoveNervousSystemPlanTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem\UpdateNervousSystemPlanTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem\UpdateNervousSystemProjectTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem\UpdateNervousSystemTaskStatusTool;
use Kanvas\NervousSystem\Plan\Actions\CreatePlanAction;
use Kanvas\NervousSystem\Plan\DataTransferObject\Plan as PlanData;
use Kanvas\NervousSystem\Plan\DataTransferObject\Task as TaskData;
use Kanvas\NervousSystem\Plan\Enums\PlanStatusEnum;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\NervousSystem\Plan\Models\Task;
use Kanvas\Users\Models\Users;
use NeuronAI\Tools\HasRunKey;
use Tests\TestCase;

class NervousSystemAgentToolsTest extends TestCase
{
    /**
     * Sentry KANVAS-ECOSYSTEM-621 hit add_nervous_system_task mid-plan-build: an agent adding many
     * tasks to a plan in one turn trips NeuronAI's per-tool-name run cap. Every per-item NS tool must
     * key its run budget by inputs so distinct items each get their own budget (identical calls still
     * collapse so a loop is capped).
     */
    public function testEveryPerItemNervousSystemToolKeysItsRunBudgetByInputs(): void
    {
        $tools = [
            new AddNervousSystemTaskTool(),
            new AssignNervousSystemPlanTool(),
            new AssignNervousSystemTaskTool(),
            new CommentOnNervousSystemPlanTool(),
            new CreateNervousSystemPlanTool(),
            new DeleteNervousSystemPlanTool(),
            new DeleteNervousSystemTaskTool(),
            new FindAndAddNervousSystemMemberTool(),
            new MoveNervousSystemPlanTool(),
            new UpdateNervousSystemPlanTool(),
            new UpdateNervousSystemProjectTool(),
            new UpdateNervousSystemTaskStatusTool(),
        ];

        foreach ($tools as $tool) {
            $this->assertInstanceOf(HasRunKey::class, $tool, $tool::class . ' must key runs by inputs.');

            $keyA = $tool->setInputs(['x' => 'a'])->getRunKey();
            $keyB = $tool->setInputs(['x' => 'b'])->getRunKey();
            $keyARepeat = $tool->setInputs(['x' => 'a'])->getRunKey();
            $this->assertNotEquals($keyA, $keyB, $tool::class . ': distinct inputs need distinct budgets.');
            $this->assertEquals($keyA, $keyARepeat, $tool::class . ': identical inputs must collapse to cap loops.');
        }
    }

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
