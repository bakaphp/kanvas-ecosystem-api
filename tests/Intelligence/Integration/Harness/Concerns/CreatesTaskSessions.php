<?php

declare(strict_types=1);

namespace Tests\Intelligence\Integration\Harness\Concerns;

use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\AgentRuntime\Harness\DataTransferObject\HarnessDiff;
use Kanvas\Intelligence\AgentRuntime\Harness\DataTransferObject\HarnessTick;
use Kanvas\Intelligence\AgentRuntime\Harness\DataTransferObject\HarnessUsage;
use Kanvas\Intelligence\AgentRuntime\Harness\Enums\HarnessEnum;
use Kanvas\Intelligence\AgentRuntime\Harness\Enums\HarnessStatusEnum;
use Kanvas\Intelligence\AgentRuntime\Harness\Models\AgentTaskSession;
use Kanvas\NervousSystem\Plan\Actions\CreateAgentRunPlanAction;
use Kanvas\NervousSystem\Plan\Enums\PlanStatusEnum;
use Kanvas\NervousSystem\Plan\Enums\TaskStatusEnum;
use Kanvas\NervousSystem\Plan\Models\Task;

trait CreatesTaskSessions
{
    /**
     * A saved opencode session on the current app with no endpoint, so anything that reaches for the
     * runtime fails fast and is reported rather than opening a connection.
     *
     * @param array<string, mixed> $attributes
     */
    protected function createTaskSession(
        HarnessStatusEnum $status = HarnessStatusEnum::RUNNING,
        array $attributes = []
    ): AgentTaskSession {
        $session = new AgentTaskSession();
        $session->apps_id = app(Apps::class)->getId();
        $session->companies_id = 0;
        $session->task_id = random_int(900000, 999999);
        $session->harness = HarnessEnum::OPENCODE->value;
        $session->status = $status->value;
        $session->fill($attributes);
        $session->saveOrFail();

        return $session;
    }

    /**
     * A coding job delegated from a task that now waits on it. Needs `MakesPlans` on the test.
     *
     * @param array<string, mixed> $attributes
     * @return array{0: Task, 1: Task, 2: AgentTaskSession} the delegating task, the coding task, its session
     */
    protected function delegatedCodingJob(
        HarnessStatusEnum $status = HarnessStatusEnum::RUNNING,
        ?string $branch = 'kanvas/fix-login',
        array $attributes = [],
    ): array {
        $plan = $this->makePlan();
        $parent = $this->makeTask($plan, TaskStatusEnum::WAITING);

        $child = new CreateAgentRunPlanAction(
            agent: $plan->agent,
            brief: 'Fix the login redirect',
            planType: 'coding_job',
            parentTask: $parent,
        )->execute();
        $child->plan->status = PlanStatusEnum::ACTIVE->value;
        $child->plan->saveQuietly();

        $session = $this->createTaskSession($status, [
            'task_id' => $child->getId(),
            'plan_id' => $child->plan_id,
            'companies_id' => $child->companies_id,
            'agent_id' => $plan->agent_id,
            'branch' => $branch,
            ...$attributes,
        ]);

        // The token counters are database defaults; the poller always reads a session back from the
        // row, so the finalizer never sees them unset the way a freshly built fixture has them.
        return [$parent, $child, $session->refresh()];
    }

    protected function diff(): HarnessDiff
    {
        return new HarnessDiff([
            ['file' => 'app/Login.php', 'additions' => 12, 'deletions' => 3, 'status' => 'modified'],
            ['file' => 'tests/LoginTest.php', 'additions' => 20, 'deletions' => 0, 'status' => 'added'],
        ]);
    }

    protected function completedTick(): HarnessTick
    {
        return new HarnessTick(HarnessStatusEnum::COMPLETED, new HarnessUsage());
    }
}
