<?php

declare(strict_types=1);

namespace Tests\Event\Integration;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Kanvas\Apps\Models\Apps;
use Kanvas\Event\Events\Enums\ConfigurationEnum;
use Kanvas\Event\Events\Jobs\GenerateTimeSlots;
use Kanvas\Event\Events\Models\ScheduleRules;
use Kanvas\Event\Events\Models\TimeSlots;
use Tests\TestCase;

class GenerateUpcomingTimeSlotsCommandTest extends TestCase
{
    use DatabaseTransactions;

    // Every connection this test writes to has to be listed: DatabaseTransactions only wraps the ones
    // named here, and the slot tables live on `event` while variants live on `inventory`. Left off,
    // those rows commit for good — and the upcoming-slots command sweeps EVERY active rule in the
    // database, so one test's committed rule gets extra slots generated for it by another test
    // running in a parallel process.
    protected $connectionsToTransact = ['mysql', 'ecosystem', 'inventory', 'event'];

    protected Apps $apps;
    protected $company;

    public function setUp(): void
    {
        parent::setUp();

        $this->apps = app(Apps::class);
        $this->company = auth()->user()->getCurrentCompany();
    }

    public function tearDown(): void
    {
        Carbon::setTestNow();
        // Settings are cached outside the test transaction; leaving these set leaks a
        // shortened horizon / an opted-in app into every other test in the shared DB.
        $this->apps->del(ConfigurationEnum::BOOKING_HORIZON_DAYS->value);
        $this->apps->del(ConfigurationEnum::GENERATE_UPCOMING_TIME_SLOTS->value);

        parent::tearDown();
    }

    public function testResolveWindowCapsAtDefaultHorizon(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-16 10:00:00'));

        [$windowFrom, $windowTo] = GenerateTimeSlots::resolveWindow(null, null);

        $this->assertTrue($windowFrom->equalTo(Carbon::now()));
        $this->assertTrue(
            $windowTo->equalTo(Carbon::now()->addDays(GenerateTimeSlots::DEFAULT_HORIZON_DAYS))
        );
    }

    public function testResolveWindowUsesEndAtWhenSoonerThanHorizon(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-16 10:00:00'));

        $endAt = Carbon::now()->addDays(5);

        [, $windowTo] = GenerateTimeSlots::resolveWindow(null, $endAt);

        $this->assertTrue($windowTo->equalTo($endAt));
    }

    public function testResolveWindowCapsEndAtBeyondHorizon(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-16 10:00:00'));

        $endAt = Carbon::now()->addYears(3);

        [, $windowTo] = GenerateTimeSlots::resolveWindow(null, $endAt, 30);

        $this->assertTrue($windowTo->equalTo(Carbon::now()->addDays(30)));
    }

    public function testHorizonDaysForAppReadsAppSetting(): void
    {
        $this->apps->set(ConfigurationEnum::BOOKING_HORIZON_DAYS->value, 14);

        $this->assertEquals(14, GenerateTimeSlots::horizonDaysForApp($this->apps));

        $this->apps->set(ConfigurationEnum::BOOKING_HORIZON_DAYS->value, 0);

        $this->assertEquals(
            GenerateTimeSlots::DEFAULT_HORIZON_DAYS,
            GenerateTimeSlots::horizonDaysForApp($this->apps)
        );
    }

    public function testCommandDispatchesForActiveRulesAndSkipsExpired(): void
    {
        Bus::fake([GenerateTimeSlots::class]);

        $activeRule = $this->createScheduleRule(['end_at' => null]);
        $expiredRule = $this->createScheduleRule(['end_at' => Carbon::now()->subDay()]);

        $this->artisan('kanvas:events:generate-upcoming-time-slots', [
            'app_id' => $this->apps->getId(),
        ])->assertExitCode(0);

        Bus::assertDispatched(
            GenerateTimeSlots::class,
            fn (GenerateTimeSlots $job) => $job->ruleId === $activeRule->id
        );

        Bus::assertNotDispatched(
            GenerateTimeSlots::class,
            fn (GenerateTimeSlots $job) => $job->ruleId === $expiredRule->id
        );
    }

    public function testCommandWindowRespectsAppHorizonSetting(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-16 10:00:00'));
        Bus::fake([GenerateTimeSlots::class]);

        $this->apps->set(ConfigurationEnum::BOOKING_HORIZON_DAYS->value, 10);

        $rule = $this->createScheduleRule(['end_at' => null]);

        $this->artisan('kanvas:events:generate-upcoming-time-slots', [
            'app_id' => $this->apps->getId(),
        ])->assertExitCode(0);

        Bus::assertDispatched(
            GenerateTimeSlots::class,
            fn (GenerateTimeSlots $job) => $job->ruleId === $rule->id
                && $job->windowTo->equalTo(Carbon::now()->addDays(10))
        );
    }

    public function testCommandWithoutAppIdOnlyRunsOptedInApps(): void
    {
        Bus::fake([GenerateTimeSlots::class]);

        $rule = $this->createScheduleRule(['end_at' => null]);

        $this->artisan('kanvas:events:generate-upcoming-time-slots')->assertExitCode(0);

        Bus::assertNotDispatched(
            GenerateTimeSlots::class,
            fn (GenerateTimeSlots $job) => $job->ruleId === $rule->id
        );

        $this->apps->set(ConfigurationEnum::GENERATE_UPCOMING_TIME_SLOTS->value, '1');

        $this->artisan('kanvas:events:generate-upcoming-time-slots')->assertExitCode(0);

        Bus::assertDispatched(
            GenerateTimeSlots::class,
            fn (GenerateTimeSlots $job) => $job->ruleId === $rule->id
        );
    }

    public function testPruneDeletesPastUnbookedSlotsAndKeepsFutureOnes(): void
    {
        Bus::fake([GenerateTimeSlots::class]);

        $rule = $this->createScheduleRule(['end_at' => null]);

        $pastSlot = $this->createTimeSlot($rule, Carbon::now()->subDays(2));
        $futureSlot = $this->createTimeSlot($rule, Carbon::now()->addDays(2));

        $this->artisan('kanvas:events:generate-upcoming-time-slots', [
            'app_id' => $this->apps->getId(),
            '--prune' => true,
        ])->assertExitCode(0);

        $this->assertDatabaseMissing('time_slots', ['id' => $pastSlot->id], 'event');
        $this->assertDatabaseHas('time_slots', ['id' => $futureSlot->id], 'event');
    }

    protected function createScheduleRule(array $overrides = []): ScheduleRules
    {
        return ScheduleRules::create(array_merge([
            'apps_id' => $this->apps->getId(),
            'companies_id' => $this->company->getId(),
            'resources_id' => 999999,
            'resources_type' => 'Kanvas\Inventory\Variants\Models\Variants',
            'start_at' => Carbon::now()->subDay(),
            'end_at' => null,
            'rrule' => 'RRULE:FREQ=DAILY',
            'slot_duration_min' => 15,
            'lead_time_min' => 0,
            'cutoff_time_min' => 0,
            'capacity_override' => 4,
        ], $overrides));
    }

    protected function createTimeSlot(ScheduleRules $rule, Carbon $startAt): TimeSlots
    {
        return TimeSlots::create([
            'apps_id' => $this->apps->getId(),
            'companies_id' => $this->company->getId(),
            'resources_id' => $rule->resources_id,
            'resources_type' => $rule->resources_type,
            'schedule_rules_id' => $rule->id,
            'start_at' => $startAt,
            'end_at' => $startAt->clone()->addMinutes(15),
            'initial_capacity' => 4,
        ]);
    }
}
