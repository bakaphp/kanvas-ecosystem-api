<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\NervousSystem\Scheduling\Actions\CreateScheduledActionAction;
use Kanvas\NervousSystem\Scheduling\DataTransferObject\ScheduledAction as ScheduledActionData;
use Kanvas\NervousSystem\Scheduling\Enums\ScheduledActionStatusEnum;
use Kanvas\NervousSystem\Scheduling\Enums\ScheduledActionTypeEnum;
use Kanvas\NervousSystem\Scheduling\Jobs\RunScheduledAgentActionJob;
use Kanvas\NervousSystem\Scheduling\Models\ScheduledAction;
use Kanvas\NervousSystem\Scheduling\Notifications\ScheduledReminderNotification;
use Kanvas\NervousSystem\Scheduling\Services\ScheduledActionSweepService;
use Kanvas\Users\Models\Users;
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
}
