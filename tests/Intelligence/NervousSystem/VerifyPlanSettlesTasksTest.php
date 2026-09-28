<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Kanvas\Apps\Models\Apps;
use Kanvas\NervousSystem\Plan\Actions\CreatePlanAction;
use Kanvas\NervousSystem\Plan\Actions\VerifyPlanAction;
use Kanvas\NervousSystem\Plan\DataTransferObject\Plan as PlanData;
use Kanvas\NervousSystem\Plan\DataTransferObject\Task as TaskData;
use Kanvas\NervousSystem\Plan\Enums\PlanStatusEnum;
use Kanvas\NervousSystem\Plan\Enums\TaskStatusEnum;
use Kanvas\NervousSystem\Plan\Jobs\NotifyPlanOwnerOfCompletedPlanJob;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\Users\Models\Users;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Verification is the path that finishes most plans, and it settles the plan with `saveQuietly()` —
 * no observer, no UpdatePlanAction. So the cascade has to be wired into `settle()` itself, and the
 * only way to reach that branch without standing up an LLM turn is to call it directly.
 */
final class VerifyPlanSettlesTasksTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'intelligence', 'social'];

    public function testAPassingVerificationClosesTheTasksTheWorkerNeverMoved(): void
    {
        Queue::fake();

        $plan = $this->planWithTasks([
            new TaskData(
                plan: null,
                title: 'Moved by the worker',
                sequence: 1,
                status: TaskStatusEnum::DONE,
            ),
            new TaskData(plan: null, title: 'Never moved', sequence: 2),
            new TaskData(
                plan: null,
                title: 'Left in flight',
                sequence: 3,
                status: TaskStatusEnum::IN_PROGRESS,
            ),
        ]);

        $this->settle($plan, passed: true);

        $plan->refresh();

        $this->assertSame(PlanStatusEnum::DONE->value, $plan->status);
        $this->assertSame(
            0,
            $plan->tasks()->whereNotIn('status', ['done', 'skipped'])->count(),
            'A verified plan cannot still own open tasks.'
        );
        $this->assertSame(100, $plan->completion_pct);

        Queue::assertPushed(NotifyPlanOwnerOfCompletedPlanJob::class);
    }

    public function testAFailedVerificationBlocksThePlanAndLeavesEveryTaskAlone(): void
    {
        Queue::fake();

        $plan = $this->planWithTasks([
            new TaskData(plan: null, title: 'Never moved', sequence: 1),
            new TaskData(
                plan: null,
                title: 'Left in flight',
                sequence: 2,
                status: TaskStatusEnum::IN_PROGRESS,
            ),
        ]);

        $this->settle($plan, passed: false);

        $plan->refresh();

        $this->assertSame(PlanStatusEnum::BLOCKED->value, $plan->status);
        $this->assertSame(
            'pending',
            $plan->tasks()->where('sequence', 1)->firstOrFail()->status,
            'A blocked plan is not finished — closing its tasks would erase the work still outstanding.'
        );
        $this->assertSame('in_progress', $plan->tasks()->where('sequence', 2)->firstOrFail()->status);
    }

    private function settle(Plan $plan, bool $passed): void
    {
        new ReflectionMethod(VerifyPlanAction::class, 'settle')
            ->invoke(new VerifyPlanAction($plan), $passed);
    }

    /**
     * @param list<TaskData> $tasks
     */
    private function planWithTasks(array $tasks): Plan
    {
        /** @var Users $user */
        $user = auth()->user();

        return new CreatePlanAction(
            new PlanData(
                app: app(Apps::class),
                company: $user->getCurrentCompany(),
                title: 'Verified plan',
                planType: 'qualification',
                user: $user,
                status: PlanStatusEnum::ACTIVE,
            ),
            tasks: $tasks,
        )->execute();
    }
}
