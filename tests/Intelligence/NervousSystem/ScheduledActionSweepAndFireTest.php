<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\NervousSystem\Scheduling\Actions\CancelScheduledActionAction;
use Kanvas\NervousSystem\Scheduling\Actions\CreateScheduledActionAction;
use Kanvas\NervousSystem\Scheduling\DataTransferObject\ScheduledAction as ScheduledActionData;
use Kanvas\NervousSystem\Scheduling\Enums\ScheduledActionStatusEnum;
use Kanvas\NervousSystem\Scheduling\Enums\ScheduledActionTypeEnum;
use Kanvas\NervousSystem\Scheduling\Jobs\RunScheduledAgentActionJob;
use Kanvas\NervousSystem\Scheduling\Models\ScheduledAction;
use Kanvas\NervousSystem\Scheduling\Notifications\ScheduledReminderNotification;
use Kanvas\NervousSystem\Scheduling\Services\ScheduledActionSweepService;
use Kanvas\Users\Models\Users;
use ReflectionMethod;
use RuntimeException;
use Tests\Stubs\Intelligence\BusyThreadNeuronAgentStub;
use Tests\Stubs\Intelligence\SalesNeuronAgentStub;
use Tests\TestCase;

class ScheduledActionSweepAndFireTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'intelligence'];

    private function context(): array
    {
        $app = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();
        /** @var Companies $company */
        $company = $user->getCurrentCompany();

        return [$app, $company, $user];
    }

    private function makeReminder(?string $cron = null, ?int $maxOccurrences = null): ScheduledAction
    {
        [$app, $company, $user] = $this->context();

        return new CreateScheduledActionAction(
            new ScheduledActionData(
                app: $app,
                company: $company,
                user: $user,
                type: ScheduledActionTypeEnum::REMINDER,
                timezone: 'UTC',
                runAt: $cron === null ? Carbon::now()->addHour() : null,
                message: 'Ping the client',
                recurrenceCron: $cron,
                maxOccurrences: $maxOccurrences,
            ),
        )->execute();
    }

    /** Force a row to be due (past run_at) without going through the create-time future guard. */
    private function makeDue(ScheduledAction $action, ?Carbon $runAt = null): ScheduledAction
    {
        $action->run_at = $runAt ?? Carbon::now()->subMinute();
        $action->status = ScheduledActionStatusEnum::PENDING->value;
        $action->saveOrFail();

        return $action;
    }

    public function testClaimDueClaimsOnlyPendingDueUndeletedRows(): void
    {
        $due = $this->makeDue($this->makeReminder());
        $future = $this->makeReminder();                 // run_at +1h, not due
        $cancelled = $this->makeDue($this->makeReminder());
        $cancelled->status = ScheduledActionStatusEnum::CANCELLED->value;
        $cancelled->saveOrFail();

        $claimed = new ScheduledActionSweepService()->claimDue();
        $claimedIds = $claimed->pluck('id')->all();

        $this->assertContains($due->getId(), $claimedIds);
        $this->assertNotContains($future->getId(), $claimedIds);
        $this->assertNotContains($cancelled->getId(), $claimedIds);

        $due->refresh();
        $this->assertSame(ScheduledActionStatusEnum::EXECUTING->value, $due->status);
        $this->assertNotNull($due->claimed_at);
    }

    public function testReclaimStaleReturnsStuckExecutingRowsToPending(): void
    {
        $stale = $this->makeDue($this->makeReminder());
        $stale->status = ScheduledActionStatusEnum::EXECUTING->value;
        $stale->claimed_at = Carbon::now()->subMinutes(ScheduledActionSweepService::STALE_CLAIM_MINUTES + 5);
        $stale->saveOrFail();

        $fresh = $this->makeDue($this->makeReminder());
        $fresh->status = ScheduledActionStatusEnum::EXECUTING->value;
        $fresh->claimed_at = Carbon::now();
        $fresh->saveOrFail();

        $reclaimed = new ScheduledActionSweepService()->reclaimStale();
        $this->assertGreaterThanOrEqual(1, $reclaimed);

        $this->assertSame(ScheduledActionStatusEnum::PENDING->value, $stale->refresh()->status);
        $this->assertNull($stale->claimed_at);
        // A recently-claimed row is left alone.
        $this->assertSame(ScheduledActionStatusEnum::EXECUTING->value, $fresh->refresh()->status);
    }

    public function testSweepCommandDispatchesAJobPerDueRow(): void
    {
        Queue::fake();

        $due = [
            $this->makeDue($this->makeReminder())->getId(),
            $this->makeDue($this->makeReminder())->getId(),
        ];

        $this->artisan('kanvas:nervous-system:sweep-scheduled-actions')->assertSuccessful();

        // Asserted per row, not as a count: the sweep is global, so any other due row already on the
        // database — a leftover from another test, real data on a shared box — would break a count.
        foreach ($due as $id) {
            Queue::assertPushed(
                RunScheduledAgentActionJob::class,
                fn (RunScheduledAgentActionJob $job): bool => $job->action->getId() === $id,
            );
        }
    }

    public function testOneOffReminderFiresAndCompletes(): void
    {
        Notification::fake();
        [$app, $company, $user] = $this->context();

        $action = $this->makeDue($this->makeReminder());

        new RunScheduledAgentActionJob($app, $company, $action)->handle();

        Notification::assertSentTo($user, ScheduledReminderNotification::class);

        $action->refresh();
        $this->assertSame(ScheduledActionStatusEnum::EXECUTED->value, $action->status);
        $this->assertNotNull($action->executed_at);
        $this->assertSame(1, (int) $action->occurrences_count);
    }

    public function testRecurringReminderReArmsToAFutureSlot(): void
    {
        Notification::fake();
        [$app, $company] = $this->context();

        // Due now, but recurs every 30 min.
        $action = $this->makeDue($this->makeReminder(cron: '*/30 * * * *'), Carbon::now()->subMinute());

        new RunScheduledAgentActionJob($app, $company, $action)->handle();

        $action->refresh();
        $this->assertSame(ScheduledActionStatusEnum::PENDING->value, $action->status);
        $this->assertSame(1, (int) $action->occurrences_count);
        $this->assertTrue($action->run_at->greaterThan(Carbon::now()));
        $this->assertNull($action->claimed_at);
    }

    public function testRecurringDoesNotBackfillMissedSlots(): void
    {
        Notification::fake();
        [$app, $company] = $this->context();

        // Two hours overdue — re-arm must jump to the NEXT future slot, not replay the missed ones.
        $action = $this->makeDue($this->makeReminder(cron: '*/30 * * * *'), Carbon::now()->subHours(2));

        new RunScheduledAgentActionJob($app, $company, $action)->handle();

        $action->refresh();
        $this->assertTrue($action->run_at->greaterThan(Carbon::now()));
        $this->assertSame(1, (int) $action->occurrences_count);
    }

    public function testRecurringSeriesCompletesAtMaxOccurrences(): void
    {
        Notification::fake();
        [$app, $company] = $this->context();

        $action = $this->makeDue(
            $this->makeReminder(cron: '*/30 * * * *', maxOccurrences: 1),
            Carbon::now()->subMinute(),
        );

        new RunScheduledAgentActionJob($app, $company, $action)->handle();

        $action->refresh();
        $this->assertSame(ScheduledActionStatusEnum::COMPLETED->value, $action->status);
        $this->assertSame(1, (int) $action->occurrences_count);
    }

    public function testAgentTaskFireWakesAgentAndCompletes(): void
    {
        [$app, $company, $user] = $this->context();

        $agentType = AgentType::factory()
            ->withAppId($app->getId())
            ->create([
                'provider' => 'neuron',
                'handler' => SalesNeuronAgentStub::class,
            ]);

        $agent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create([
                'agent_type_id' => $agentType->getId(),
                'user_id' => $user->getId(),
                'is_active' => true,
            ]);

        $action = new CreateScheduledActionAction(
            new ScheduledActionData(
                app: $app,
                company: $company,
                user: $user,
                type: ScheduledActionTypeEnum::AGENT_TASK,
                timezone: 'UTC',
                runAt: Carbon::now()->addHour(),
                agent: $agent,
                instruction: 'Email the client the updated quote.',
            ),
        )->execute();
        $this->makeDue($action);

        new RunScheduledAgentActionJob($app, $company, $action)->handle();

        $action->refresh();
        $this->assertSame(ScheduledActionStatusEnum::EXECUTED->value, $action->status);
        $this->assertDatabaseHas(
            'nervous_system_events',
            [
                'source_entity_type' => ScheduledAction::class,
                'source_entity_id' => $action->getId(),
                'event_type' => 'scheduled_action.fired',
            ],
            'intelligence',
        );
    }

    /**
     * A person is mid-turn with the agent in the same thread when the task comes due (KANVAS-ECOSYSTEM-6JQ):
     * the task goes back on the queue instead of posting "I ran into a hiccup" and counting as fired.
     */
    public function testABusyThreadDefersTheAgentTaskInsteadOfFiringIt(): void
    {
        Sleep::fake();
        [$app, $company, $user] = $this->context();
        $action = $this->agentTask(BusyThreadNeuronAgentStub::class, 'Post the daily summary.');

        $job = new RunScheduledAgentActionJob($app, $company, $action)->withFakeQueueInteractions();
        $job->handle();

        $job->assertReleased(delay: 60);
        $action->refresh();
        $this->assertNotSame(ScheduledActionStatusEnum::EXECUTED->value, $action->status);
        $this->assertSame(0, (int) $action->occurrences_count);
        $this->assertDatabaseMissing(
            'nervous_system_events',
            [
                'source_entity_type' => ScheduledAction::class,
                'source_entity_id' => $action->getId(),
                'event_type' => 'scheduled_action.fired',
            ],
            'intelligence',
        );
    }

    private function agentTask(string $handler, string $instruction): ScheduledAction
    {
        [$app, $company, $user] = $this->context();

        $agentType = AgentType::factory()
            ->withAppId($app->getId())
            ->create([
                'provider' => 'neuron',
                'handler' => $handler,
            ]);

        $agent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create([
                'agent_type_id' => $agentType->getId(),
                'user_id' => $user->getId(),
                'is_active' => true,
            ]);

        $action = new CreateScheduledActionAction(
            new ScheduledActionData(
                app: $app,
                company: $company,
                user: $user,
                type: ScheduledActionTypeEnum::AGENT_TASK,
                timezone: 'UTC',
                runAt: Carbon::now()->addHour(),
                agent: $agent,
                instruction: $instruction,
            ),
        )->execute();

        return $this->makeDue($action);
    }

    /**
     * The sweeper claims a row and queues the job; a cancel that lands in that gap used to be ignored
     * — the reminder went out and the row flipped from `cancelled` back to `executed`.
     */
    public function testACancelAfterTheClaimStopsTheReminderAndStaysCancelled(): void
    {
        Notification::fake();
        [$app, $company] = $this->context();
        $action = $this->makeDue($this->makeReminder());

        $claimed = new ScheduledActionSweepService()->claimDue()->firstWhere('id', $action->getId());
        $this->assertNotNull($claimed);
        $claim = $claimed->claimed_at?->toDateTimeString();
        $this->assertNotNull($claim);

        new CancelScheduledActionAction($claimed)->execute();

        new RunScheduledAgentActionJob(
            $app,
            $company,
            $claimed,
            $claim,
        )->handle();

        Notification::assertNothingSent();
        $action->refresh();
        $this->assertSame(ScheduledActionStatusEnum::CANCELLED->value, $action->status);
        $this->assertSame(0, (int) $action->occurrences_count);
    }

    /**
     * The job's model is reloaded when it runs, so the claim it was dispatched with is the only thing
     * that tells it apart from a newer job for the same row after a stale-claim reclaim.
     */
    public function testAJobWithASupersededClaimDoesNothing(): void
    {
        Notification::fake();
        [$app, $company] = $this->context();
        $action = $this->makeDue($this->makeReminder());

        $claimed = new ScheduledActionSweepService()->claimDue()->firstWhere('id', $action->getId());
        $staleClaim = $claimed->claimed_at?->toDateTimeString();

        $claimed->claimed_at = Carbon::now()->addMinutes(20);
        $claimed->saveOrFail();

        new RunScheduledAgentActionJob(
            $app,
            $company,
            $claimed,
            $staleClaim,
        )->handle();

        Notification::assertNothingSent();
        $this->assertSame(ScheduledActionStatusEnum::EXECUTING->value, $action->refresh()->status);
    }

    /** A cancel that lands while the turn is running must not be overwritten by the post-run write. */
    public function testACancelDuringTheTurnIsNotOverwrittenByTheOutcome(): void
    {
        [$app, $company] = $this->context();
        $action = $this->makeDue($this->makeReminder(cron: '0 9 * * *'));
        $job = new RunScheduledAgentActionJob($app, $company, $action);

        new CancelScheduledActionAction($action)->execute();

        new ReflectionMethod($job, 'reArmOrComplete')->invoke($job);

        $action->refresh();
        $this->assertSame(ScheduledActionStatusEnum::CANCELLED->value, $action->status, 'a cancelled recurrence must not re-arm');
        $this->assertSame(0, (int) $action->occurrences_count);
    }

    public function testACancelDuringTheTurnIsNotOverwrittenByAFailure(): void
    {
        [$app, $company] = $this->context();
        $action = $this->makeDue($this->makeReminder());
        $job = new RunScheduledAgentActionJob($app, $company, $action);

        new CancelScheduledActionAction($action)->execute();

        $job->failed(new RuntimeException('provider down'));

        $action->refresh();
        $this->assertSame(ScheduledActionStatusEnum::CANCELLED->value, $action->status);
        $this->assertNull($action->last_error);
        $this->assertDatabaseMissing(
            'nervous_system_events',
            [
                'source_entity_type' => ScheduledAction::class,
                'source_entity_id' => $action->getId(),
                'event_type' => 'scheduled_action.failed',
            ],
            'intelligence',
        );
    }
}
