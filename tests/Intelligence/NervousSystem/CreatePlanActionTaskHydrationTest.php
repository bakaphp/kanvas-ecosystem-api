<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\NervousSystem\Plan\Actions\CreateAgentRunPlanAction;
use Kanvas\NervousSystem\Plan\Actions\CreatePlanAction;
use Kanvas\NervousSystem\Plan\DataTransferObject\Plan as PlanData;
use Kanvas\NervousSystem\Plan\DataTransferObject\Task as TaskData;
use Kanvas\NervousSystem\Plan\Enums\TaskStatusEnum;
use Kanvas\NervousSystem\Plan\Models\Task;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

/**
 * The `intelligence` connection is not sticky, so a read issued right after the create transaction
 * commits goes to the replica. `CreateAgentRunPlanAction` used to re-read the task it had just
 * written that way and threw `No query results for model [Task]` whenever the replica lagged — text
 * that reached a customer's chat. The created rows now come back on the plan as the loaded relation.
 */
final class CreatePlanActionTaskHydrationTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'intelligence', 'social'];

    public function testTheCreatedTasksAreReturnedAsTheLoadedRelation(): void
    {
        [$agent, $user] = $this->agentAndUser();

        $plan = new CreatePlanAction(
            new PlanData(
                app: $agent->app,
                company: $agent->company,
                title: 'Hydration',
                planType: 'coding_job',
                agent: $agent,
                user: $user,
            ),
            tasks: [
                new TaskData(plan: null, title: 'first', status: TaskStatusEnum::IN_PROGRESS),
                new TaskData(plan: null, title: 'second', status: TaskStatusEnum::PENDING),
            ],
        )->execute();

        $this->assertTrue($plan->relationLoaded('tasks'));
        $this->assertSame(['first', 'second'], $plan->tasks->pluck('title')->all());
        $this->assertSame(
            Task::query()->where('plan_id', $plan->getId())->orderBy('id')->pluck('id')->all(),
            $plan->tasks->pluck('id')->all(),
        );
    }

    public function testARunPlanDoesNotReReadItsTaskAfterTheCreateCommits(): void
    {
        [$agent, $user] = $this->agentAndUser();

        $connection = DB::connection('intelligence');
        $connection->enableQueryLog();

        $task = new CreateAgentRunPlanAction(
            agent: $agent,
            brief: 'Add a health endpoint',
            planType: 'coding_job',
            requestedBy: $user,
        )->execute();

        $reads = array_filter(
            $connection->getQueryLog(),
            static fn (array $query): bool => str_starts_with($query['query'], 'select')
                && str_contains($query['query'], 'nervous_system_tasks')
                && str_contains($query['query'], 'plan_id')
                && ! str_contains($query['query'], 'count('),
        );
        $connection->disableQueryLog();

        $this->assertSame([], array_values($reads), 'The task must be hydrated from the create, never re-read');
        $this->assertSame($agent->getId(), $task->fresh()->agent_id);
        $this->assertNotNull($task->current_attempt_id);
    }

    /**
     * @return array{0: Agent, 1: Users}
     */
    private function agentAndUser(): array
    {
        $app = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();

        $agent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($user->getCurrentCompany()->getId())
            ->create(['user_id' => $user->getId()]);

        return [$agent, $user];
    }
}
