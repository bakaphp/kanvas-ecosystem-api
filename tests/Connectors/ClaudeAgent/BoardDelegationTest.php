<?php

declare(strict_types=1);

namespace Tests\Connectors\ClaudeAgent;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\ClaudeAgent\Actions\DispatchLongTaskAction;
use Kanvas\Connectors\ClaudeAgent\Enums\ConfigurationEnum;
use Kanvas\Connectors\ClaudeAgent\Enums\TaskCustomFieldEnum;
use Kanvas\Connectors\ClaudeAgent\Jobs\PollClaudeSessionJob;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\NervousSystem\Plan\Enums\PlanChangeTypeEnum;
use Kanvas\NervousSystem\Plan\Enums\TaskStatusEnum;
use Kanvas\NervousSystem\Plan\Events\PlanBroadcast;
use Kanvas\NervousSystem\Plan\Jobs\WakeAgentForPlanJob;
use Kanvas\NervousSystem\Plan\Listeners\WakeAgentOnPlanChangeListener;
use Kanvas\NervousSystem\Plan\Models\Task;
use Tests\Connectors\Traits\HasClaudeAgentConfiguration;
use Tests\TestCase;

/**
 * The delegation loop: a PM can assign to a hosted agent, the assignment does not read as a
 * completion, and the PM is woken when the work actually finishes.
 *
 * Every assertion here guards one variant of the same failure — plausible prose standing in for work
 * that did not happen.
 */
final class BoardDelegationTest extends TestCase
{
    use DatabaseTransactions;
    use HasClaudeAgentConfiguration;

    /** Settings live on mysql; agents, plans and tasks on intelligence. */
    protected array $connectionsToTransact = ['mysql', 'intelligence'];

    private Apps $currentApp;
    private Companies $currentCompany;

    protected function setUp(): void
    {
        parent::setUp();

        $this->currentApp = app(Apps::class);
        $this->currentCompany = static::$cachedUser->getCurrentCompany();
        $this->configureClaudeAgent($this->currentApp, $this->currentCompany);
        $this->currentCompany->set(ConfigurationEnum::ENVIRONMENT_ID->value, 'env_cached');
        Queue::fake();
    }

    private function hostedAgent(): Agent
    {
        $type = AgentType::where('name', 'Claude Task Agent')->firstOrFail();

        return $this->makeClaudeAgent(
            $this->currentApp,
            $this->currentCompany,
            ['agent_type_id' => $type->getId()],
        )->refresh();
    }

    private function dispatchedTask(): Task
    {
        return new DispatchLongTaskAction(
            agent: $this->hostedAgent(),
            brief: 'Scaffold the connector.',
            requestedBy: static::$cachedUser,
            client: $this->claudeAgentClientReturning($this->currentApp, $this->currentCompany, [
                $this->claudeAgentJsonResponse(200, ['id' => 'agent_01', 'version' => 1]),
                $this->claudeAgentJsonResponse(200, ['id' => 'sesn_01']),
            ]),
        )->execute();
    }

    /**
     * The gate that used to make this impossible. A hosted agent executes our PHP tools through the
     * bridge, so the test is capability, not transport.
     */
    public function testAHostedAgentCanExecuteBoardWork(): void
    {
        $agent = $this->hostedAgent();

        $this->assertTrue($agent->isHostedRuntime());
        $this->assertTrue($agent->canExecuteBoardWork());
    }

    /**
     * Machine runtimes stay excluded — they run their own kanban and cannot hold our tools, so
     * relaxing the gate for hosted agents must not relax it for them.
     */
    public function testMachineRuntimesAreStillExcluded(): void
    {
        $type = AgentType::factory()->create(['provider' => 'hermes', 'handler' => 'Some\\Hermes\\Handler']);
        $agent = $this->makeClaudeAgent(
            $this->currentApp,
            $this->currentCompany,
            ['agent_type_id' => $type->getId()],
        )->refresh();

        $this->assertFalse($agent->isHostedRuntime());
        $this->assertFalse($agent->canExecuteBoardWork());
    }

    /**
     * Dispatch is not delivery. A hosted assignment that landed `done` would tell the PM the work
     * finished the moment it was handed over.
     */
    public function testAssignmentLeavesTheTaskInProgressWithASession(): void
    {
        $task = $this->dispatchedTask();

        $this->assertSame(TaskStatusEnum::IN_PROGRESS->value, $task->status);
        $this->assertSame('sesn_01', $task->get(TaskCustomFieldEnum::CLAUDE_SESSION_ID->value));
        Queue::assertPushed(PollClaudeSessionJob::class);
    }

    /**
     * The loop-closing wake. Without it the PM sits idle on finished work — the human still gets a
     * notification, but nobody reports, assigns the review, or closes the plan.
     */
    public function testATerminalTaskWakesThePlanOwnerWithTheFact(): void
    {
        $task = $this->dispatchedTask();
        $plan = $task->plan;

        // A different agent owns the plan — the PM that delegated.
        $pm = $this->makeClaudeAgent($this->currentApp, $this->currentCompany, ['name' => 'PM']);
        $plan->agent_id = $pm->getId();
        $plan->saveQuietly();

        $task->status = TaskStatusEnum::DONE->value;
        $task->result = ['summary' => 'Connector scaffolded, PR #4821 opened.'];
        $task->saveQuietly();

        new WakeAgentOnPlanChangeListener()->handle(new PlanBroadcast(
            plan: $plan->refresh(),
            changeType: PlanChangeTypeEnum::TASK_STATUS_CHANGED,
            task: $task->refresh(),
            previousStatus: TaskStatusEnum::IN_PROGRESS->value,
        ));

        Queue::assertPushed(
            WakeAgentForPlanJob::class,
            function (WakeAgentForPlanJob $job): bool {
                return $job->reason === WakeAgentForPlanJob::REASON_TASK_COMPLETED
                    && str_contains((string) $job->userMessage, 'PR #4821');
            },
        );
    }

    /**
     * An agent that finished its own task already knows. Waking it there bounces its own work back
     * at it and burns a turn on every status write.
     */
    public function testAnAgentIsNotWokenForItsOwnTask(): void
    {
        $task = $this->dispatchedTask();
        $plan = $task->plan;

        $plan->agent_id = $task->agent_id;
        $plan->saveQuietly();

        $task->status = TaskStatusEnum::DONE->value;
        $task->saveQuietly();

        new WakeAgentOnPlanChangeListener()->handle(new PlanBroadcast(
            plan: $plan->refresh(),
            changeType: PlanChangeTypeEnum::TASK_STATUS_CHANGED,
            task: $task->refresh(),
            previousStatus: TaskStatusEnum::IN_PROGRESS->value,
        ));

        Queue::assertNotPushed(WakeAgentForPlanJob::class);
    }

    public function testANonTerminalStatusChangeDoesNotWakeAnyone(): void
    {
        $task = $this->dispatchedTask();
        $plan = $task->plan;

        $pm = $this->makeClaudeAgent($this->currentApp, $this->currentCompany, ['name' => 'PM']);
        $plan->agent_id = $pm->getId();
        $plan->saveQuietly();

        new WakeAgentOnPlanChangeListener()->handle(new PlanBroadcast(
            plan: $plan->refresh(),
            changeType: PlanChangeTypeEnum::TASK_STATUS_CHANGED,
            task: $task->refresh(),
            previousStatus: TaskStatusEnum::PENDING->value,
        ));

        Queue::assertNotPushed(WakeAgentForPlanJob::class);
    }

    /**
     * A blocked task must wake the PM too, carrying the reason — silence on failure is how a stuck
     * task sits unnoticed.
     */
    public function testABlockedTaskWakesWithItsReason(): void
    {
        $task = $this->dispatchedTask();
        $plan = $task->plan;

        $pm = $this->makeClaudeAgent($this->currentApp, $this->currentCompany, ['name' => 'PM']);
        $plan->agent_id = $pm->getId();
        $plan->saveQuietly();

        $task->status = TaskStatusEnum::BLOCKED->value;
        $task->blocked_reason = 'The session reached its spend limit.';
        $task->saveQuietly();

        new WakeAgentOnPlanChangeListener()->handle(new PlanBroadcast(
            plan: $plan->refresh(),
            changeType: PlanChangeTypeEnum::TASK_STATUS_CHANGED,
            task: $task->refresh(),
            previousStatus: TaskStatusEnum::IN_PROGRESS->value,
        ));

        Queue::assertPushed(
            WakeAgentForPlanJob::class,
            fn (WakeAgentForPlanJob $job): bool => str_contains((string) $job->userMessage, 'spend limit'),
        );
    }
}
