<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\NervousSystem\Scheduling\Actions\CancelScheduledActionAction;
use Kanvas\NervousSystem\Scheduling\Actions\CreateScheduledActionAction;
use Kanvas\NervousSystem\Scheduling\DataTransferObject\ScheduledAction as ScheduledActionData;
use Kanvas\NervousSystem\Scheduling\Enums\ScheduledActionStatusEnum;
use Kanvas\NervousSystem\Scheduling\Enums\ScheduledActionTypeEnum;
use Kanvas\NervousSystem\Scheduling\Models\ScheduledAction;
use Kanvas\NervousSystem\Scheduling\Services\ScheduledActionTimezoneResolver;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

class ScheduledActionTest extends TestCase
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

    private function reminderData(
        Companies $company,
        Users $user,
        Apps $app,
        ?Carbon $runAt,
        string $timezone = 'UTC',
        ?string $cron = null,
        ScheduledActionTypeEnum $type = ScheduledActionTypeEnum::REMINDER,
        ?Agent $agent = null,
    ): ScheduledActionData {
        return new ScheduledActionData(
            app: $app,
            company: $company,
            user: $user,
            type: $type,
            timezone: $timezone,
            runAt: $runAt,
            agent: $agent,
            message: $type === ScheduledActionTypeEnum::REMINDER ? 'Ping the client' : null,
            instruction: $type === ScheduledActionTypeEnum::AGENT_TASK ? 'Email the client the quote' : null,
            recurrenceCron: $cron,
        );
    }

    public function testTimezoneResolverPrefersCompanyThenUserThenUtc(): void
    {
        [, $company, $user] = $this->context();
        $resolver = new ScheduledActionTimezoneResolver();

        $company->timezone = 'America/Santo_Domingo';
        $user->timezone = 'America/New_York';
        $this->assertSame('America/Santo_Domingo', $resolver->resolve($company, $user));

        $company->timezone = '';
        $this->assertSame('America/New_York', $resolver->resolve($company, $user));

        $company->timezone = 'Not/AZone';
        $user->timezone = null;
        $this->assertSame(
            config('app.timezone') ?: 'UTC',
            $resolver->resolve($company, $user),
        );
    }

    public function testNextRunAtComputesCronInNonUtcZoneAsUtc(): void
    {
        $action = new ScheduledAction();
        $action->recurrence_cron = '0 9 * * *';
        $action->timezone = 'America/New_York';

        $next = $action->nextRunAt(Carbon::now());

        $this->assertSame('UTC', $next->timezoneName);
        $this->assertTrue($next->isFuture());
        // 9am wall-clock in New York, regardless of DST offset.
        $this->assertSame('09:00', $next->setTimezone('America/New_York')->format('H:i'));
    }

    public function testCreateOneOffReminderPersistsAsPending(): void
    {
        [$app, $company, $user] = $this->context();
        $runAt = Carbon::now()->addHour();

        $action = new CreateScheduledActionAction(
            $this->reminderData($company, $user, $app, $runAt),
        )->execute();

        $this->assertSame(ScheduledActionStatusEnum::PENDING->value, $action->status);
        $this->assertSame(ScheduledActionTypeEnum::REMINDER->value, $action->action_type);
        $this->assertSame($user->getId(), $action->users_id);
        $this->assertFalse($action->isRecurring());
        $this->assertSame('Ping the client', $action->payload['message']);
        $this->assertTrue($action->run_at->greaterThan(Carbon::now()));
    }

    public function testCreateRejectsPastRunAt(): void
    {
        [$app, $company, $user] = $this->context();

        $this->expectException(ValidationException::class);

        new CreateScheduledActionAction(
            $this->reminderData($company, $user, $app, Carbon::now()->subMinute()),
        )->execute();
    }

    public function testCreateRejectsReminderWithoutMessage(): void
    {
        [$app, $company, $user] = $this->context();

        $data = new ScheduledActionData(
            app: $app,
            company: $company,
            user: $user,
            type: ScheduledActionTypeEnum::REMINDER,
            timezone: 'UTC',
            runAt: Carbon::now()->addHour(),
            message: '   ',
        );

        $this->expectException(ValidationException::class);
        new CreateScheduledActionAction($data)->execute();
    }

    public function testCreateRejectsTooFarHorizon(): void
    {
        [$app, $company, $user] = $this->context();

        $this->expectException(ValidationException::class);

        new CreateScheduledActionAction(
            $this->reminderData($company, $user, $app, Carbon::now()->addYears(2)),
        )->execute();
    }

    public function testCreateRecurringDerivesRunAtFromCron(): void
    {
        [$app, $company, $user] = $this->context();

        $action = new CreateScheduledActionAction(
            $this->reminderData($company, $user, $app, runAt: null, cron: '*/30 * * * *'),
        )->execute();

        $this->assertTrue($action->isRecurring());
        $this->assertSame('*/30 * * * *', $action->recurrence_cron);
        $this->assertTrue($action->run_at->greaterThan(Carbon::now()));
        $this->assertSame(ScheduledActionStatusEnum::PENDING->value, $action->status);
    }

    public function testCreateRecurringAgentTaskRejectsBelowFifteenMinuteFloor(): void
    {
        [$app, $company, $user] = $this->context();

        $this->expectException(ValidationException::class);

        new CreateScheduledActionAction(
            $this->reminderData(
                $company,
                $user,
                $app,
                runAt: null,
                cron: '*/5 * * * *',
                type: ScheduledActionTypeEnum::AGENT_TASK,
            ),
        )->execute();
    }

    public function testCreateRecurringReminderAllowsFiveMinuteCadence(): void
    {
        [$app, $company, $user] = $this->context();

        $action = new CreateScheduledActionAction(
            $this->reminderData($company, $user, $app, runAt: null, cron: '*/5 * * * *'),
        )->execute();

        $this->assertTrue($action->isRecurring());
        $this->assertSame('*/5 * * * *', $action->recurrence_cron);
    }

    public function testCancelSetsCancelledAndIsIdempotent(): void
    {
        [$app, $company, $user] = $this->context();

        $action = new CreateScheduledActionAction(
            $this->reminderData($company, $user, $app, Carbon::now()->addHour()),
        )->execute();

        $cancelled = new CancelScheduledActionAction($action)->execute();
        $this->assertSame(ScheduledActionStatusEnum::CANCELLED->value, $cancelled->status);

        // Second cancel is a no-op — status stays cancelled.
        $again = new CancelScheduledActionAction($cancelled)->execute();
        $this->assertSame(ScheduledActionStatusEnum::CANCELLED->value, $again->status);
    }

    public function testDeletingAgentCascadeSoftDeletesPendingScheduledActions(): void
    {
        [$app, $company, $user] = $this->context();

        $agent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create();

        $action = new CreateScheduledActionAction(
            $this->reminderData($company, $user, $app, Carbon::now()->addHour(), agent: $agent),
        )->execute();

        $this->assertSame(0, (int) $action->is_deleted);

        $agent->delete();

        // Read the raw row — a soft-deleted model is hidden by the default scope, so assert on the
        // table directly to prove the cascade soft-deleted it rather than leaving it live.
        $row = DB::connection('intelligence')
            ->table('nervous_system_scheduled_actions')
            ->where('id', $action->getId())
            ->first();
        $this->assertNotNull($row);
        $this->assertSame(1, (int) $row->is_deleted);

        // A soft-deleted row must not be picked up by the sweep.
        $this->assertSame(
            0,
            ScheduledAction::query()->where('id', $action->getId())->due()->count(),
        );
    }
}
