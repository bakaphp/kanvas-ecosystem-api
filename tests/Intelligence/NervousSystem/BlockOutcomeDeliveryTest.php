<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\NervousSystem\Plan\Enums\PlanBlockedNeedsEnum;
use Kanvas\NervousSystem\Plan\Enums\PlanChangeTypeEnum;
use Kanvas\NervousSystem\Plan\Enums\PlanStatusEnum;
use Kanvas\NervousSystem\Plan\Events\PlanBroadcast;
use Kanvas\NervousSystem\Plan\Jobs\NotifyPlanOwnerOfBlockedPlanJob;
use Kanvas\NervousSystem\Plan\Listeners\WakeProjectManagerOnPlanOutcomeListener;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\NervousSystem\Plan\Services\BlockOutcomeDeliveryService;
use Kanvas\NervousSystem\Project\Actions\EnsureCompanyOperationsProjectAction;
use Kanvas\NervousSystem\Project\Jobs\NotifyProjectOwnerOfBlockedPlanJob;
use Kanvas\NervousSystem\Project\Jobs\WakeAgentForProjectJob;
use Kanvas\NervousSystem\Project\Models\Project;
use Kanvas\Users\Models\Users;
use RuntimeException;
use Tests\Stubs\Intelligence\ScriptedCompanyBrainAgentStub;
use Tests\TestCase;
use Tests\Traits\MakesCompanyBrain;
use Tests\Traits\MakesEmployees;
use Tests\Traits\MakesPlans;

/**
 * A handed-off plan blocks: the agent that handed it off and the blocked alert are both queued to tell
 * the person. One message, whichever runs first, retries or fails. Every test drives the two jobs the
 * observer and the listener actually queued, carrying their own cycle id.
 */
final class BlockOutcomeDeliveryTest extends TestCase
{
    use DatabaseTransactions;
    use MakesCompanyBrain;
    use MakesEmployees;
    use MakesPlans;

    private const string BRAIN_SAYS = 'The invoice is blocked: an admin needs to add the e-CF tool.';

    protected array $connectionsToTransact = ['mysql', 'intelligence', 'social', 'workflow'];

    private Users $jenn;
    private Project $operations;
    private Session $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootCompanyBrain(ScriptedCompanyBrainAgentStub::class);
        $this->jenn = $this->makeEmployee();
        $this->operations = new EnsureCompanyOperationsProjectAction($this->tenantApp, $this->company)
            ->execute();
        $this->conversation = $this->sessionWithBrain($this->jenn);

        ScriptedCompanyBrainAgentStub::$reply = self::BRAIN_SAYS;
        // CI runs the queue inline: an @mention's notification would render a mail template there.
        Notification::fake();
        Bus::fake([
            WakeAgentForProjectJob::class,
            NotifyPlanOwnerOfBlockedPlanJob::class,
            NotifyProjectOwnerOfBlockedPlanJob::class,
        ]);
    }

    public function testTheBlockIsReservedForBrainAndBothJobsCarryItsCycle(): void
    {
        $plan = $this->blockedPlan();

        $cycle = $this->wake()->blockOutcomeId;

        $this->assertNotNull($cycle);
        $this->assertSame($cycle, $this->alert()->blockOutcomeId);
        $this->assertTrue(new BlockOutcomeDeliveryService($plan)->creatorIsPending($cycle));
    }

    public function testTheAlertWaitsWhileBrainStillHasTheFloor(): void
    {
        $this->blockedPlan();
        $alert = $this->alert()->withFakeQueueInteractions();

        $alert->handle();

        $alert->assertReleased(30);
        $this->assertSame(0, $this->told());
    }

    public function testAlertFirstThenBrainGivesOneMessage(): void
    {
        $this->blockedPlan();

        $this->alert()->withFakeQueueInteractions()->handle();
        $this->wake()->handle();
        $this->alert()->withFakeQueueInteractions()->handle();

        $this->assertSame(1, $this->told());
        $this->assertSame(1, $this->told(self::BRAIN_SAYS));
    }

    public function testBrainFirstThenAlertGivesOneMessage(): void
    {
        $this->blockedPlan();

        $this->wake()->handle();
        $this->alert()->withFakeQueueInteractions()->handle();

        $this->assertSame(1, $this->told());
        $this->assertSame(1, $this->told(self::BRAIN_SAYS));
    }

    public function testARetryAfterBrainAlreadyAnsweredSaysNothingMore(): void
    {
        $this->blockedPlan();

        $this->wake()->handle();
        $this->wake()->handle();

        $this->assertSame(1, $this->told());
    }

    public function testWhenBrainsWakeFailsTheAlertSaysItInstead(): void
    {
        $plan = $this->blockedPlan();
        $cycle = $this->wake()->blockOutcomeId;

        $this->wake()->failed(new RuntimeException('provider down'));

        $this->assertFalse(new BlockOutcomeDeliveryService($plan)->creatorIsPending($cycle));
        Bus::assertDispatchedTimes(NotifyPlanOwnerOfBlockedPlanJob::class, 2);

        $this->alert()->withFakeQueueInteractions()->handle();

        $this->assertSame(1, $this->told());
        $this->assertSame(0, $this->told(self::BRAIN_SAYS));
    }

    public function testASilentBrainOnAStillBlockedPlanLetsTheAlertSpeak(): void
    {
        $this->blockedPlan();
        ScriptedCompanyBrainAgentStub::$reply = 'NO_UPDATE';

        $this->wake()->handle();

        Bus::assertDispatchedTimes(NotifyPlanOwnerOfBlockedPlanJob::class, 2);

        $this->alert()->withFakeQueueInteractions()->handle();

        $this->assertSame(1, $this->told());
    }

    public function testABlockBrainFixedByReassigningStaysQuiet(): void
    {
        $plan = $this->blockedPlan();
        ScriptedCompanyBrainAgentStub::$reply = 'NO_UPDATE';
        $plan->status = PlanStatusEnum::ACTIVE->value;
        $plan->saveQuietly();

        $this->wake()->handle();
        $this->alert()->withFakeQueueInteractions()->handle();

        $this->assertSame(0, $this->told());
    }

    public function testDeferringBehindABusyThreadKeepsTheReservation(): void
    {
        $plan = $this->blockedPlan();
        $queued = $this->wake();
        $deferred = new class (
            project: $queued->project,
            reason: $queued->reason,
            triggerMessage: $queued->triggerMessage,
            wakeAgent: $queued->wakeAgent,
            aboutPlan: $queued->aboutPlan,
            blockOutcomeId: $queued->blockOutcomeId,
        ) extends WakeAgentForProjectJob {
            protected function runAgentWake(...$arguments): ?array
            {
                return null;
            }
        };

        $deferred->handle();

        $this->assertTrue(new BlockOutcomeDeliveryService($plan)->creatorIsPending((string) $queued->blockOutcomeId));
        Bus::assertDispatchedTimes(NotifyPlanOwnerOfBlockedPlanJob::class, 1);
        $this->assertSame(0, $this->told());
    }

    public function testPastItsDeadlineTheAlertSpeaksAndALateBrainDoesNot(): void
    {
        $this->blockedPlan();

        $this->travel(61)->minutes();
        $this->alert()->withFakeQueueInteractions()->handle();
        $this->wake()->handle();

        $this->assertSame(1, $this->told());
        $this->assertSame(0, $this->told(self::BRAIN_SAYS));
    }

    public function testAnOldWakeCannotAnswerOrReleaseANewBlock(): void
    {
        $plan = $this->blockedPlan();
        $oldWake = $this->wake();

        $this->movePlanTo($plan, PlanStatusEnum::ACTIVE);
        $this->movePlanTo($plan->refresh(), PlanStatusEnum::BLOCKED);
        $newCycle = new BlockOutcomeDeliveryService($plan)->currentCycleId();

        $this->assertNotSame($oldWake->blockOutcomeId, $newCycle);

        $oldWake->handle();
        $oldWake->failed(new RuntimeException('late'));

        $this->assertSame(0, $this->told());
        $this->assertTrue(new BlockOutcomeDeliveryService($plan)->creatorIsPending((string) $newCycle));
    }

    public function testABrainThatCannotPostItsAnswerHandsOverToTheAlert(): void
    {
        $plan = $this->blockedPlan(withConversation: false);
        $cycle = $this->wake()->blockOutcomeId;

        $this->wake()->handle();

        $this->assertFalse(new BlockOutcomeDeliveryService($plan)->wasDelivered((string) $cycle));
        $this->assertFalse(new BlockOutcomeDeliveryService($plan)->creatorIsPending((string) $cycle));
        Bus::assertDispatchedTimes(NotifyPlanOwnerOfBlockedPlanJob::class, 2);
    }

    public function testAFallbackWithoutAnOriginDoesNotMarkTheBlockDelivered(): void
    {
        $plan = $this->blockedPlan(withConversation: false);
        $cycle = (string) $this->wake()->blockOutcomeId;
        $this->wake()->failed(new RuntimeException('provider down'));

        $this->alert()->withFakeQueueInteractions()->handle();

        $this->assertFalse(new BlockOutcomeDeliveryService($plan)->wasDelivered($cycle));
    }

    public function testFinishingAnOldDeliveryCannotMarkANewerBlockDelivered(): void
    {
        $plan = $this->blockedPlan();
        $delivery = new BlockOutcomeDeliveryService($plan);
        $oldCycle = (string) $this->wake()->blockOutcomeId;

        $this->assertTrue($delivery->claimDelivery($oldCycle, asCreator: true, deliver: function () use ($plan): bool {
            $this->movePlanTo($plan, PlanStatusEnum::ACTIVE);
            $this->movePlanTo($plan->refresh(), PlanStatusEnum::BLOCKED);

            return true;
        }));

        $newCycle = (string) $delivery->currentCycleId();
        $this->assertNotSame($oldCycle, $newCycle);
        $this->assertFalse($delivery->wasDelivered($newCycle));
        $this->assertTrue($delivery->creatorIsPending($newCycle));
    }

    public function testARepeatedReservationDoesNotExtendTheFallbackDeadline(): void
    {
        $plan = $this->blockedPlan();
        $cycle = (string) $this->wake()->blockOutcomeId;
        $delivery = new BlockOutcomeDeliveryService($plan);

        $this->travel(59)->minutes();
        $this->assertTrue($delivery->reserveForCreator($cycle));
        $this->travel(2)->minutes();

        $this->assertFalse($delivery->creatorIsPending($cycle));
        $this->alert()->withFakeQueueInteractions()->handle();
        $this->assertSame(1, $this->told());
    }

    public function testACapabilityBlockOnAnotherPmsProjectStillGoesToTheAsker(): void
    {
        $plan = $this->handedOffPlan(project: $this->projectManagedBy($this->makeAgent()));
        $plan->blocked_needs = PlanBlockedNeedsEnum::CAPABILITY->value;
        $plan->saveQuietly();

        $this->movePlanTo($plan, PlanStatusEnum::BLOCKED);

        Bus::assertDispatched(NotifyPlanOwnerOfBlockedPlanJob::class, fn (NotifyPlanOwnerOfBlockedPlanJob $job): bool => $job->blockOutcomeId !== null);
        Bus::assertNotDispatched(NotifyProjectOwnerOfBlockedPlanJob::class);
    }

    public function testAPmsOwnCapabilityBlockKeepsItsProjectDigest(): void
    {
        $project = $this->projectManagedBy($this->makeAgent());
        $plan = $this->makePlan(['project_id' => $project->getId(), 'created_by_agent_id' => $project->agent_id]);
        $plan->blocked_needs = PlanBlockedNeedsEnum::CAPABILITY->value;
        $plan->saveQuietly();

        $this->movePlanTo($plan, PlanStatusEnum::BLOCKED);

        Bus::assertDispatched(NotifyProjectOwnerOfBlockedPlanJob::class);
        Bus::assertNotDispatched(NotifyPlanOwnerOfBlockedPlanJob::class);
    }

    public function testAPlanClosedDirectlyHoldsBackTheDoneAlertForBrainsAnswer(): void
    {
        $plan = $this->handedOffPlan();

        $this->movePlanTo($plan, PlanStatusEnum::DONE);

        $this->assertTrue($plan->answerIsComing());

        $this->wake()->handle();

        $this->assertSame(1, $this->told(self::BRAIN_SAYS));
    }

    public function testASyncedBlockOfHandedOffWorkStillWakesBrain(): void
    {
        $plan = $this->handedOffPlan();
        $plan->status = PlanStatusEnum::BLOCKED->value;
        $plan->saveQuietly();

        new WakeProjectManagerOnPlanOutcomeListener()->handle(new PlanBroadcast(
            $plan->refresh(),
            PlanChangeTypeEnum::UPDATED,
            previousStatus: PlanStatusEnum::ACTIVE->value,
            fromSync: true,
        ));

        Bus::assertDispatched(WakeAgentForProjectJob::class, fn (WakeAgentForProjectJob $job): bool => $this->brain->is($job->wakeAgent));
    }

    public function testASyncedChangeOnAPmsOwnPlanStillWakesNobody(): void
    {
        $project = $this->projectManagedBy($this->makeAgent());
        $plan = $this->makePlan(['project_id' => $project->getId(), 'status' => PlanStatusEnum::BLOCKED->value]);

        new WakeProjectManagerOnPlanOutcomeListener()->handle(new PlanBroadcast(
            $plan->refresh(),
            PlanChangeTypeEnum::UPDATED,
            previousStatus: PlanStatusEnum::ACTIVE->value,
            fromSync: true,
        ));

        Bus::assertNotDispatched(WakeAgentForProjectJob::class);
    }

    public function testAHandedOffOutcomeWaitsForAHeldLockAndAPmsOwnBlockIsDropped(): void
    {
        $this->blockedPlan();
        $handedOff = $this->wake()->withFakeQueueInteractions();
        $project = $this->projectManagedBy($this->makeAgent());
        $own = new WakeAgentForProjectJob(
            $project,
            WakeAgentForProjectJob::REASON_PLAN_OUTCOME,
            'The plan is blocked.',
            wakeAgent: $project->pmAgent,
            aboutPlan: $this->makePlan(['project_id' => $project->getId()]),
        )->withFakeQueueInteractions();

        $this->assertTrue($this->collidesWithAHeldLock($handedOff));
        $handedOff->assertReleased(15);

        $this->assertTrue($this->collidesWithAHeldLock($own));
        // Kept out and not put back: the wake is dropped.
        $own->assertNotReleased();
    }

    public function testAPmsFinishedReportStillWaitsForItsProjectLock(): void
    {
        $project = $this->projectManagedBy($this->makeAgent());
        $report = new WakeAgentForProjectJob(
            $project,
            WakeAgentForProjectJob::REASON_PLAN_OUTCOME,
            'Every task is finished.',
            reportsOn: $this->makePlan(['project_id' => $project->getId()]),
        )->withFakeQueueInteractions();

        $this->assertTrue($this->collidesWithAHeldLock($report));
        $report->assertReleased(15);
    }

    private function handedOffPlan(?Project $project = null, bool $withConversation = true): Plan
    {
        return $this->makePlan([
            'project_id' => ($project ?? $this->operations)->getId(),
            'title' => 'Prepare an invoice',
            'created_by_agent_id' => $this->brain->getId(),
            'origin_users_id' => $this->jenn->getId(),
            'origin_session_id' => $withConversation ? $this->conversation->getId() : null,
            'origin_channel_id' => $withConversation ? $this->conversation->channel_id : null,
        ])->refresh();
    }

    /**
     * Blocked through the real path, so the observer starts the cycle and queues the alert, and the
     * listener reserves it and queues Brain's wake.
     */
    private function blockedPlan(bool $withConversation = true): Plan
    {
        $plan = $this->handedOffPlan(withConversation: $withConversation);
        $this->movePlanTo($plan, PlanStatusEnum::BLOCKED);

        return $plan->refresh();
    }

    private function wake(): WakeAgentForProjectJob
    {
        return Bus::dispatched(WakeAgentForProjectJob::class)->last();
    }

    private function alert(): NotifyPlanOwnerOfBlockedPlanJob
    {
        return Bus::dispatched(NotifyPlanOwnerOfBlockedPlanJob::class)->last();
    }

    /**
     * Runs the job's overlap middleware with its lock already taken, and says whether the job was kept
     * out.
     */
    private function collidesWithAHeldLock(WakeAgentForProjectJob $job): bool
    {
        foreach ($job->middleware() as $middleware) {
            if (! $middleware instanceof WithoutOverlapping) {
                continue;
            }

            $held = Cache::lock($middleware->getLockKey($job), 30);
            $held->get();
            $ran = false;

            try {
                $middleware->handle($job, function () use (&$ran): void {
                    $ran = true;
                });
            } finally {
                $held->release();
            }

            return ! $ran;
        }

        $this->fail('The wake has no overlap lock.');
    }

    /**
     * Messages in the conversation the work was asked for in.
     */
    private function told(?string $containing = null): int
    {
        return $this->messagesIn($this->conversation, $containing);
    }
}
