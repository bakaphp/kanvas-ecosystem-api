<?php

declare(strict_types=1);

namespace Tests\Intelligence\Integration\Harness;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Kanvas\Approvals\Enums\ApprovalStatusEnum;
use Kanvas\Approvals\Models\ApprovalRequest;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\AgentRuntime\Harness\Actions\RequestSessionExtensionAction;
use Kanvas\Intelligence\AgentRuntime\Harness\Enums\HarnessStatusEnum;
use Kanvas\Intelligence\AgentRuntime\Harness\Jobs\PollHarnessSessionJob;
use Kanvas\Intelligence\AgentRuntime\Harness\Models\AgentTaskSession;
use Tests\Intelligence\Integration\Harness\Concerns\CreatesTaskSessions;
use Tests\TestCase;

/**
 * The poller's side of a session paused at its limit: it keeps ticking without touching the runtime,
 * and is what notices the approval being decided, ignored, or approved-but-never-resumed.
 */
class SessionExtensionWaitTest extends TestCase
{
    use CreatesTaskSessions;
    use DatabaseTransactions;

    protected $connectionsToTransact = ['mysql', 'ecosystem', 'intelligence', 'social'];

    private const string POST_MORTEM = 'Coding job hit its time limit of 180 minutes.';

    public function testAPendingRequestKeepsTheSessionParkedAndAlive(): void
    {
        Queue::fake();
        $session = $this->parkedSession();
        $this->openRequest($session, ApprovalStatusEnum::PENDING);

        $this->tick($session);

        $session->refresh();
        $this->assertSame(HarnessStatusEnum::AWAITING_EXTENSION->value, $session->status);
        $this->assertNotNull($session->heartbeat_at, 'the silence sweeper would reap a parked session');
        Queue::assertPushed(PollHarnessSessionJob::class);
    }

    public function testNobodyAnsweringInTimeStopsTheRunAndWithdrawsTheRequest(): void
    {
        Queue::fake();
        $session = $this->parkedSession();
        $request = $this->openRequest(
            $session,
            ApprovalStatusEnum::PENDING,
            createdAt: Carbon::now()->subMinutes(PollHarnessSessionJob::EXTENSION_WAIT_MINUTES + 1)
        );

        $this->tick($session);

        $this->assertSame(HarnessStatusEnum::CANCELLED->value, $session->refresh()->status);
        $this->assertStringContainsString(self::POST_MORTEM, (string) $session->error_message);
        $this->assertStringContainsString('Nobody approved more time', (string) $session->error_message);
        $this->assertSame(ApprovalStatusEnum::CANCELLED, $request->refresh()->status);
    }

    public function testARejectedRequestStopsTheRunWithThePostMortem(): void
    {
        Queue::fake();
        $session = $this->parkedSession();
        $this->openRequest($session, ApprovalStatusEnum::REJECTED);

        $this->tick($session);

        $this->assertSame(HarnessStatusEnum::CANCELLED->value, $session->refresh()->status);
        $this->assertStringContainsString(self::POST_MORTEM, (string) $session->error_message);
        Queue::assertNotPushed(PollHarnessSessionJob::class);
    }

    /**
     * Approved but still parked long after the decision means the handler's resume failed. Waiting
     * forever would hold a concurrency slot for a run nobody is going to restart.
     */
    public function testAnApprovalThatNeverResumedTheSessionFailsIt(): void
    {
        Queue::fake();
        $session = $this->parkedSession();
        $this->openRequest($session, ApprovalStatusEnum::APPROVED, resolvedAt: Carbon::now()->subMinutes(5));

        $this->tick($session);

        $this->assertSame(HarnessStatusEnum::FAILED->value, $session->refresh()->status);
        $this->assertStringContainsString('could not be resumed', (string) $session->error_message);
    }

    public function testAFreshApprovalIsGivenTimeToResume(): void
    {
        Queue::fake();
        $session = $this->parkedSession();
        $this->openRequest($session, ApprovalStatusEnum::APPROVED, resolvedAt: Carbon::now());

        $this->tick($session);

        $this->assertSame(HarnessStatusEnum::AWAITING_EXTENSION->value, $session->refresh()->status);
        Queue::assertPushed(PollHarnessSessionJob::class);
    }

    private function tick(AgentTaskSession $session): void
    {
        new PollHarnessSessionJob(app(Apps::class), $session->getId(), attempt: 5)->handle();
    }

    private function parkedSession(): AgentTaskSession
    {
        return $this->createTaskSession(
            HarnessStatusEnum::AWAITING_EXTENSION,
            ['error_message' => self::POST_MORTEM]
        );
    }

    private function openRequest(
        AgentTaskSession $session,
        ApprovalStatusEnum $status,
        ?Carbon $createdAt = null,
        ?Carbon $resolvedAt = null
    ): ApprovalRequest {
        $request = ApprovalRequest::create([
            'apps_id' => $session->apps_id,
            'companies_id' => 0,
            'system_modules_id' => 1,
            'entity_id' => $session->task_id,
            'approval_type' => RequestSessionExtensionAction::APPROVAL_TYPE,
            'status' => $status,
            'current_step' => 1,
            'payload' => [],
            'resolved_at' => $resolvedAt,
        ]);

        if ($createdAt !== null) {
            ApprovalRequest::query()->whereKey($request->getKey())->update(['created_at' => $createdAt]);
            $request->refresh();
        }

        return $request;
    }
}
