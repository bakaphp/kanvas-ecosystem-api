<?php

declare(strict_types=1);

namespace Tests\Intelligence\Integration\Harness;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Kanvas\Approvals\Actions\CancelApprovalAction;
use Kanvas\Approvals\Actions\ExpireApprovalRequestsAction;
use Kanvas\Approvals\Actions\RejectAction;
use Kanvas\Approvals\Actions\RequestApprovalAction;
use Kanvas\Approvals\Enums\ApprovalOriginEnum;
use Kanvas\Approvals\Enums\ApprovalTriggerEnum;
use Kanvas\Approvals\Models\ApprovalPolicy;
use Kanvas\Approvals\Models\ApprovalRequest;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\AgentRuntime\Harness\Actions\FinalizeHarnessSessionAction;
use Kanvas\Intelligence\AgentRuntime\Harness\Actions\RecordCodingOutcomeAction;
use Kanvas\Intelligence\AgentRuntime\Harness\Actions\RequestPushApprovalAction;
use Kanvas\Intelligence\AgentRuntime\Harness\Approvals\CodingPushApprovalHandler;
use Kanvas\Intelligence\AgentRuntime\Harness\DataTransferObject\CodingOutcome;
use Kanvas\Intelligence\AgentRuntime\Harness\DataTransferObject\HarnessDiff;
use Kanvas\Intelligence\AgentRuntime\Harness\Enums\CodingOutcomeEnum;
use Kanvas\Intelligence\AgentRuntime\Harness\Enums\HarnessStatusEnum;
use Kanvas\Intelligence\AgentRuntime\Harness\Jobs\VerifyHarnessDiffJob;
use Kanvas\Intelligence\AgentRuntime\Harness\Models\AgentTaskSession;
use Kanvas\NervousSystem\Plan\Enums\PlanStatusEnum;
use Kanvas\NervousSystem\Plan\Enums\TaskStatusEnum;
use Kanvas\NervousSystem\Plan\Jobs\ResumeParentTaskJob;
use Kanvas\NervousSystem\Plan\Models\Task;
use Kanvas\NervousSystem\Plan\Models\TaskChildOutcome;
use Tests\Intelligence\Integration\Harness\Concerns\CreatesTaskSessions;
use Tests\TestCase;
use Tests\Traits\MakesPlans;

/**
 * A coding job is over when the work is somewhere a person can use it, or it is known that it never
 * will be — and whichever of the five endings it takes, the task that delegated it hears about it.
 */
class CodingOutcomeTest extends TestCase
{
    use CreatesTaskSessions;
    use DatabaseTransactions;
    use MakesPlans;

    protected $connectionsToTransact = [null, 'ecosystem', 'intelligence', 'social'];

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake();
        Notification::fake();
    }

    public function test_a_direct_push_closes_the_task_and_plan_and_resumes_the_parent(): void
    {
        [$parent, $child, $session] = $this->delegatedCodingJob();

        new RecordCodingOutcomeAction($session, CodingOutcome::fromPush(
            ['pushed' => true, 'branch' => 'kanvas/fix-login', 'files' => 2, 'pull_request' => 'https://github.com/x/y/pull/7'],
            $this->diff(),
        ))->execute();

        $child->refresh();
        $this->assertSame(TaskStatusEnum::DONE->value, $child->status);
        $this->assertSame(CodingOutcomeEnum::PUSHED->value, $child->result['outcome']);
        $this->assertSame('https://github.com/x/y/pull/7', $child->result['pull_request']);
        $this->assertSame(['app/Login.php', 'tests/LoginTest.php'], $child->result['files']);
        $this->assertSame(PlanStatusEnum::DONE->value, $child->plan->status);
        $this->assertParentResumed($parent, $child);
    }

    public function test_a_failed_push_blocks_the_task_with_the_reason(): void
    {
        [$parent, $child, $session] = $this->delegatedCodingJob();

        new RecordCodingOutcomeAction($session, CodingOutcome::fromPush(
            ['pushed' => false, 'error' => 'remote rejected the ref'],
            $this->diff(),
            fallbackBranch: 'kanvas/fix-login',
        ))->execute();

        $child->refresh();
        $this->assertSame(TaskStatusEnum::BLOCKED->value, $child->status);
        $this->assertStringContainsString('push failed: remote rejected the ref', (string) $child->blocked_reason);
        $this->assertStringContainsString('kanvas/fix-login', (string) $child->blocked_reason);
        $this->assertSame(PlanStatusEnum::FAILED->value, $child->plan->status);
        $this->assertParentResumed($parent, $child);
    }

    public function test_awaiting_push_parks_the_task_and_a_rejection_blocks_it(): void
    {
        [$parent, $child, $session] = $this->delegatedCodingJob();
        $request = $this->awaitPush($child, $session);

        $child->refresh();
        $this->assertSame(TaskStatusEnum::WAITING->value, $child->status);
        $this->assertSame(PlanStatusEnum::ACTIVE->value, $child->plan->status);
        $this->assertSame(TaskStatusEnum::WAITING->value, $parent->refresh()->status);
        Bus::assertNotDispatched(ResumeParentTaskJob::class);

        new RejectAction($request, static::$cachedUser, 'not like this')->execute();

        $child->refresh();
        $this->assertSame(TaskStatusEnum::BLOCKED->value, $child->status);
        $this->assertStringContainsString('rejected: not like this', (string) $child->blocked_reason);
        $this->assertSame(CodingOutcomeEnum::PUSH_REJECTED->value, $child->result['outcome']);
        $this->assertSame(PlanStatusEnum::FAILED->value, $child->plan->status);
        $this->assertParentResumed($parent, $child);
    }

    public function test_a_withdrawn_approval_skips_the_task_through_the_real_cancel_action(): void
    {
        [$parent, $child, $session] = $this->delegatedCodingJob();
        $request = $this->awaitPush($child, $session);

        new CancelApprovalAction($request, 'superseded by a newer run')->execute();

        $child->refresh();
        $this->assertSame(TaskStatusEnum::SKIPPED->value, $child->status);
        $this->assertSame(CodingOutcomeEnum::PUSH_CANCELLED->value, $child->result['outcome']);
        $this->assertSame(PlanStatusEnum::CANCELLED->value, $child->plan->status);
        $this->assertParentResumed($parent, $child);
    }

    public function test_an_expired_approval_blocks_the_task_through_the_real_sweep(): void
    {
        [$parent, $child, $session] = $this->delegatedCodingJob();
        $request = $this->awaitPush($child, $session);
        $request->expires_at = Carbon::now()->subMinute();
        $request->saveOrFail();

        new ExpireApprovalRequestsAction(app(Apps::class))->execute();

        $child->refresh();
        $this->assertSame(TaskStatusEnum::BLOCKED->value, $child->status);
        $this->assertStringContainsString('expired', (string) $child->blocked_reason);
        $this->assertSame(CodingOutcomeEnum::PUSH_EXPIRED->value, $child->result['outcome']);
        $this->assertParentResumed($parent, $child);
    }

    /** The approver said yes, the push itself failed: a failed push, said out loud, not a done task. */
    public function test_an_approved_push_that_cannot_land_is_a_failed_push(): void
    {
        [$parent, $child, $session] = $this->delegatedCodingJob();
        $request = $this->awaitPush($child, $session);

        $result = new CodingPushApprovalHandler()->handle($request, static::$cachedUser);

        $this->assertFalse($result['pushed']);
        $child->refresh();
        $this->assertSame(TaskStatusEnum::BLOCKED->value, $child->status);
        $this->assertSame(CodingOutcomeEnum::PUSH_FAILED->value, $child->result['outcome']);
        $this->assertSame(static::$cachedUser->getId(), $child->result['decided_by_users_id']);
        $this->assertParentResumed($parent, $child);
    }

    public function test_a_second_ending_is_logged_and_changes_nothing(): void
    {
        [, $child, $session] = $this->delegatedCodingJob();

        new RecordCodingOutcomeAction($session, CodingOutcome::fromPush(['pushed' => true, 'branch' => 'kanvas/fix-login'], $this->diff()))->execute();
        new RecordCodingOutcomeAction($session, CodingOutcome::pushExpired('kanvas/fix-login'))->execute();

        $this->assertSame(TaskStatusEnum::DONE->value, $child->refresh()->status);
        $this->assertSame(1, TaskChildOutcome::query()->where('child_task_id', $child->getId())->count());
        $this->assertDatabaseHas(
            'nervous_system_events',
            [
                'source_entity_type' => Task::class,
                'source_entity_id' => $child->getId(),
                'event_type' => 'coding.outcome.after_close',
            ],
            'intelligence',
        );
    }

    public function test_an_unreadable_diff_parks_the_run_instead_of_recording_it(): void
    {
        [, $child, $session] = $this->delegatedCodingJob();

        new FinalizeHarnessSessionAction($session, $this->completedTick())->execute();

        $this->assertSame(HarnessStatusEnum::VERIFYING_DIFF->value, $session->refresh()->status);
        $this->assertSame(TaskStatusEnum::IN_PROGRESS->value, $child->refresh()->status);
        Bus::assertDispatched(VerifyHarnessDiffJob::class, fn (VerifyHarnessDiffJob $job): bool => $job->sessionId === $session->getId());
    }

    public function test_giving_up_on_the_diff_fails_the_run_without_recording_delivery(): void
    {
        [$parent, $child, $session] = $this->delegatedCodingJob(HarnessStatusEnum::VERIFYING_DIFF);

        new VerifyHarnessDiffJob(app(Apps::class), $session->getId(), VerifyHarnessDiffJob::MAX_ATTEMPTS)->handle();

        $this->assertSame(HarnessStatusEnum::FAILED->value, $session->refresh()->status);
        $child->refresh();
        $this->assertSame(TaskStatusEnum::BLOCKED->value, $child->status);
        $this->assertStringContainsString('could not say what changed', (string) $child->blocked_reason);
        $this->assertParentResumed($parent, $child);
    }

    public function test_a_diff_that_arrives_later_completes_the_parked_run(): void
    {
        [, $child, $session] = $this->delegatedCodingJob(HarnessStatusEnum::VERIFYING_DIFF, branch: null);

        new FinalizeHarnessSessionAction($session, diff: $this->diff())->execute();

        $this->assertSame(HarnessStatusEnum::COMPLETED->value, $session->refresh()->status);
        $child->refresh();
        $this->assertSame(TaskStatusEnum::DONE->value, $child->status);
        $this->assertSame(CodingOutcomeEnum::REVIEW_ONLY->value, $child->result['outcome']);
    }

    /** The first empty run is retried (CodingRetryTest); the retry's empty run is the failure. */
    public function test_a_branchless_empty_diff_on_the_last_try_is_a_failure(): void
    {
        [, $child, $first] = $this->delegatedCodingJob(HarnessStatusEnum::FAILED, branch: null);
        $session = $this->createTaskSession(HarnessStatusEnum::RUNNING, [
            'task_id' => $child->getId(),
            'plan_id' => $child->plan_id,
            'companies_id' => $child->companies_id,
            'agent_id' => $first->agent_id,
            'retry_of_session_id' => $first->getId(),
        ])->refresh();

        new FinalizeHarnessSessionAction($session, $this->completedTick(), diff: new HarnessDiff())->execute();

        $this->assertSame(HarnessStatusEnum::FAILED->value, $session->refresh()->status);
        $this->assertStringContainsString('no file in the workspace changed', (string) $session->error_message);
        $this->assertSame(TaskStatusEnum::BLOCKED->value, $child->refresh()->status);
    }

    private function awaitPush(Task $child, AgentTaskSession $session): ApprovalRequest
    {
        $policy = ApprovalPolicy::create([
            'apps_id' => app(Apps::class)->getId(),
            'companies_id' => $child->companies_id,
            'system_modules_id' => $child->approvalSystemModuleId(),
            'approval_type' => RequestPushApprovalAction::APPROVAL_TYPE,
            'handler' => CodingPushApprovalHandler::class,
            'steps' => [[
                'step' => 1,
                'resolver' => 'explicit_users',
                'config' => ['users_id' => [static::$cachedUser->getId()]],
                'required_approvals' => 1,
            ]],
            'trigger' => ApprovalTriggerEnum::MANUAL,
        ]);

        $request = new RequestApprovalAction(
            entity: $child,
            policy: $policy,
            origin: ApprovalOriginEnum::SYSTEM,
        )->execute()->refresh();

        new RecordCodingOutcomeAction($session, CodingOutcome::awaitingPush($this->diff(), $request, $session->branch))->execute();

        return $request;
    }

    private function assertParentResumed(Task $parent, Task $child): void
    {
        $this->assertSame(TaskStatusEnum::IN_PROGRESS->value, $parent->refresh()->status);
        $this->assertSame(1, TaskChildOutcome::query()->where('child_task_id', $child->getId())->count());
        Bus::assertDispatchedTimes(ResumeParentTaskJob::class, 1);
    }
}
