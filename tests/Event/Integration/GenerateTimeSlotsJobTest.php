<?php

declare(strict_types=1);

namespace Tests\Event\Integration;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Event\Events\Jobs\GenerateTimeSlots;
use Kanvas\Event\Events\Models\ScheduleException;
use Kanvas\Event\Events\Models\ScheduleRules;
use Kanvas\Event\Events\Models\TimeSlots;
use Kanvas\Event\Support\Setup;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Regions\Models\Regions;
use Tests\GraphQL\Inventory\Traits\InventoryCases;
use Tests\TestCase;

class GenerateTimeSlotsJobTest extends TestCase
{
    use InventoryCases;
    use DatabaseTransactions;

    // Every connection this test writes to has to be listed: DatabaseTransactions only wraps the ones
    // named here, and the slot tables live on `event` while the variant they hang off lives on
    // `inventory`. Left off, those rows are committed for good — they accumulate in the shared CI
    // database run after run, and a count this test makes about its own rule can then be inflated by
    // work it never did.
    /**
     * `inventory` stays out on purpose: CreateProductAction relies on
     * `DB::connection('inventory')->transaction($cb, 3)` to retry the gap-lock deadlock
     * concurrent product inserts hit, and Laravel only retries a transaction it opened
     * itself — listing the connection here demotes that one to a savepoint and the
     * deadlock escapes as a 500.
     */
    protected $connectionsToTransact = ['mysql', 'ecosystem', 'event'];

    protected $variant;
    protected $region;
    protected $company;
    protected $user;
    protected $apps;
    protected $variantId;
    protected $warehouseId;

    public function setUp(): void
    {
        parent::setUp();

        $this->apps = app(Apps::class);
        $this->user = auth()->user();
        $this->company = $this->user->getCurrentCompany();
        $this->region = Regions::getDefault($this->company, $this->apps);

        $warehouseResponse = $this->graphQLData($this->createWarehouses((string) $this->region->getId()), 'createWarehouse');
        $channelResponse = $this->graphQLData($this->createChannel(), 'createChannel');
        $this->warehouseId = (string) $warehouseResponse['id'];

        $productResponse = $this->graphQLData(
            $this->createProduct(attributes: [
                [
                    'name' => 'event_slot',
                    'value' => 100,
                ],
            ]),
            'createProduct'
        );

        $product = Products::find($productResponse['id']);
        $this->variantId = $product->variants()->first()->id;

        $this->addVariantToChannel(
            variantId: (string) $this->variantId,
            channelId: $channelResponse['id'],
            warehouseData: [
                'id' => $warehouseResponse['id'],
            ]
        );

        $this->addVariantToWarehouse(
            variantId: (string) $this->variantId,
            warehouseId: $warehouseResponse['id'],
            amount: 100
        );

        // Set timezone to UTC to ensure consistent RRULE occurrence generation across environments
        $this->company->timezone = 'UTC';
        $this->company->saveOrFail();

        $setup = new Setup($this->apps, $this->user, $this->company);
        $setup->run();
    }

    public function testGenerateTimeSlotsCreatesSlots(): void
    {
        $startDate = Carbon::now()->addDay()->setTime(9, 0, 0);
        $endDate = $startDate->copy()->addDays(7);

        $scheduleRule = ScheduleRules::create([
            'apps_id' => $this->apps->getId(),
            'companies_id' => $this->company->getId(),
            'resources_id' => $this->variantId,
            'resources_type' => 'Kanvas\Inventory\Variants\Models\Variants',
            'start_at' => $startDate,
            'end_at' => $endDate,
            'rrule' => 'RRULE:FREQ=DAILY',
            'slot_duration_min' => 60,
            'lead_time_min' => 0,
            'cutoff_time_min' => 0,
            'capacity_override' => 5,
        ]);

        $job = new GenerateTimeSlots(
            $this->variantId,
            $scheduleRule->id,
            $startDate,
            $endDate
        );

        $job->handle();

        // Verify time slots were created
        $this->assertDatabaseHas('time_slots', [
            'resources_id' => $this->variantId,
            'schedule_rules_id' => $scheduleRule->id,
            'initial_capacity' => 5,
        ], 'event');

        // Check that multiple slots were created (7 days = 7 slots)
        $slots = TimeSlots::where('schedule_rules_id', $scheduleRule->id)->count();
        $this->assertGreaterThan(0, $slots);
    }

    public function testGenerateTimeSlotsWithWeeklyRecurrence(): void
    {
        $startDate = Carbon::now()->addDay()->setTime(10, 0, 0);
        $endDate = $startDate->copy()->addWeeks(4);

        $scheduleRule = ScheduleRules::create([
            'apps_id' => $this->apps->getId(),
            'companies_id' => $this->company->getId(),
            'resources_id' => $this->variantId,
            'resources_type' => 'Kanvas\Inventory\Variants\Models\Variants',
            'start_at' => $startDate,
            'end_at' => $endDate,
            'rrule' => 'RRULE:FREQ=WEEKLY;BYDAY=MO,WE,FR',
            'slot_duration_min' => 30,
            'lead_time_min' => 0,
            'cutoff_time_min' => 0,
            'capacity_override' => 5,
        ]);

        $job = new GenerateTimeSlots(
            $this->variantId,
            $scheduleRule->id,
            $startDate,
            $endDate
        );

        $job->handle();

        $slots = TimeSlots::where('schedule_rules_id', $scheduleRule->id)->get();

        $this->assertGreaterThan(0, $slots->count());

        // Verify slot duration is 30 minutes
        foreach ($slots as $slot) {
            $duration = $slot->start_at->diffInMinutes($slot->end_at);
            $this->assertEquals(30, $duration);
        }
    }

    public function testGenerateTimeSlotsUpsertsBehavior(): void
    {
        $startDate = Carbon::now()->addDay()->setTime(9, 0, 0);
        $endDate = $startDate->copy()->addDays(3);

        $scheduleRule = ScheduleRules::create([
            'apps_id' => $this->apps->getId(),
            'companies_id' => $this->company->getId(),
            'resources_id' => $this->variantId,
            'resources_type' => 'Kanvas\Inventory\Variants\Models\Variants',
            'start_at' => $startDate,
            'end_at' => $endDate,
            'rrule' => 'RRULE:FREQ=DAILY',
            'slot_duration_min' => 60,
            'lead_time_min' => 0,
            'cutoff_time_min' => 0,
            'capacity_override' => 10,
        ]);

        // First generation
        $job = new GenerateTimeSlots(
            $this->variantId,
            $scheduleRule->id,
            $startDate,
            $endDate
        );
        $job->handle();

        $initialCount = TimeSlots::where('schedule_rules_id', $scheduleRule->id)->count();

        // Update schedule rule capacity
        $scheduleRule->update(['capacity_override' => 20]);

        // Second generation (should upsert, not duplicate)
        $job = new GenerateTimeSlots(
            $this->variantId,
            $scheduleRule->id,
            $startDate,
            $endDate
        );
        $job->handle();

        // Verify no duplicate time slots exist (same resource + start_at)
        $duplicates = TimeSlots::where('schedule_rules_id', $scheduleRule->id)
            ->selectRaw('resources_id, resources_type, start_at, COUNT(*) as cnt')
            ->groupBy('resources_id', 'resources_type', 'start_at')
            ->having('cnt', '>', 1)
            ->count();

        $this->assertEquals(0, $duplicates, 'Upsert should not create duplicate time slots');

        $finalCount = TimeSlots::where('schedule_rules_id', $scheduleRule->id)->count();
        $this->assertEquals($initialCount, $finalCount, 'Slot count should remain the same after upsert');

        // Initial capacity should be updated to 20
        $slots = TimeSlots::where('schedule_rules_id', $scheduleRule->id)->get();
        foreach ($slots as $slot) {
            $this->assertEquals(20, $slot->initial_capacity);
            // Capacity (available slots) should also be 20 since no bookings yet
            $this->assertEquals(20, $slot->capacity);
        }
    }

    public function testGenerateTimeSlotsRespectsBlackoutPeriods(): void
    {
        $startDate = Carbon::now()->addDay()->setTime(9, 0, 0);
        // Use addDays(6)->endOfDay() to create a 7-day window; getOccurrencesBetween() is inclusive on both ends
        $endDate = $startDate->copy()->addDays(6)->endOfDay();

        $scheduleRule = ScheduleRules::create([
            'apps_id' => $this->apps->getId(),
            'companies_id' => $this->company->getId(),
            'resources_id' => $this->variantId,
            'resources_type' => 'Kanvas\Inventory\Variants\Models\Variants',
            'start_at' => $startDate,
            'end_at' => $endDate,
            'rrule' => 'RRULE:FREQ=DAILY',
            'slot_duration_min' => 60,
            'lead_time_min' => 0,
            'cutoff_time_min' => 0,
            'capacity_override' => 5,
        ]);

        // Create a blackout period for day 3
        $blackoutStart = $startDate->copy()->addDays(3);
        $blackoutEnd = $blackoutStart->copy()->endOfDay();

        ScheduleException::create([
            'apps_id' => $this->apps->getId(),
            'companies_id' => $this->company->getId(),
            'resources_id' => $this->variantId,
            'resources_type' => 'Kanvas\Inventory\Variants\Models\Variants',
            'kind' => 'blackout',
            'window_start' => $blackoutStart,
            'window_end' => $blackoutEnd,
        ]);

        $job = new GenerateTimeSlots(
            $this->variantId,
            $scheduleRule->id,
            $startDate,
            $endDate
        );
        $job->handle();

        $slots = TimeSlots::where('schedule_rules_id', $scheduleRule->id)->get();

        // Verify no slots were created during the blackout period
        foreach ($slots as $slot) {
            $this->assertFalse(
                $slot->start_at->between($blackoutStart, $blackoutEnd),
                'Time slot should not exist during blackout period'
            );
        }

        // Should have 6 slots (7 days - 1 blackout day)
        $this->assertEquals(6, $slots->count());
    }

    public function testGenerateTimeSlotsStoresScheduleRuleId(): void
    {
        $startDate = Carbon::now()->addDay()->setTime(9, 0, 0);
        $endDate = $startDate->copy()->addDays(2);

        $scheduleRule = ScheduleRules::create([
            'apps_id' => $this->apps->getId(),
            'companies_id' => $this->company->getId(),
            'resources_id' => $this->variantId,
            'resources_type' => 'Kanvas\Inventory\Variants\Models\Variants',
            'start_at' => $startDate,
            'end_at' => $endDate,
            'rrule' => 'RRULE:FREQ=DAILY',
            'slot_duration_min' => 60,
            'lead_time_min' => 0,
            'cutoff_time_min' => 0,
            'capacity_override' => 5,
        ]);

        $job = new GenerateTimeSlots(
            $this->variantId,
            $scheduleRule->id,
            $startDate,
            $endDate
        );
        $job->handle();

        $slots = TimeSlots::where('resources_id', $this->variantId)->get();

        // All slots should have the schedule_rules_id set
        foreach ($slots as $slot) {
            $this->assertEquals($scheduleRule->id, $slot->schedule_rules_id);
            $this->assertTrue($slot->isFromScheduleRule());
            $this->assertFalse($slot->isStandalone());
        }
    }

    public function testGenerateTimeSlotsKeepsTodayWhenNowIsPastTheOpeningHour(): void
    {
        // A Thursday, mid-afternoon: past the 09:00 opening but well inside the 09:00-17:00 window.
        Carbon::setTestNow(Carbon::parse('2026-08-06 13:14:00', 'UTC'));

        $dtstart = '20260806T090000';
        $scheduleRule = ScheduleRules::create([
            'apps_id' => $this->apps->getId(),
            'companies_id' => $this->company->getId(),
            'resources_id' => $this->variantId,
            'resources_type' => 'Kanvas\Inventory\Variants\Models\Variants',
            'start_at' => Carbon::parse('2026-08-06 09:00:00', 'UTC'),
            'end_at' => Carbon::parse('2026-08-06 23:59:59', 'UTC'),
            'rrule' => "DTSTART:{$dtstart}\nRRULE:FREQ=WEEKLY;BYDAY=TH",
            'day_rrule' => "DTSTART:{$dtstart}\nRRULE:FREQ=MINUTELY;INTERVAL=60;BYHOUR=9,10,11,12,13,14,15,16;BYMINUTE=0",
            'slot_duration_min' => 60,
            'lead_time_min' => 0,
            'cutoff_time_min' => 0,
            'capacity_override' => 5,
        ]);

        [$windowFrom, $windowTo] = GenerateTimeSlots::resolveWindow($scheduleRule->start_at, $scheduleRule->end_at);

        new GenerateTimeSlots(
            $this->variantId,
            $scheduleRule->id,
            $windowFrom,
            $windowTo
        )->handle();

        $slots = TimeSlots::where('schedule_rules_id', $scheduleRule->id)->orderBy('start_at')->get();

        $this->assertCount(3, $slots, 'Only the 14:00, 15:00 and 16:00 slots are still bookable');
        $this->assertEquals(
            ['14:00', '15:00', '16:00'],
            $slots->map(fn ($slot) => $slot->start_at->format('H:i'))->all()
        );

        Carbon::setTestNow();
    }

    public function testGenerateTimeSlotsSnapshotsChannelPrice(): void
    {
        $startDate = Carbon::now()->addDay()->setTime(9, 0, 0);
        $endDate = $startDate->copy()->addDay();

        $scheduleRule = ScheduleRules::create([
            'apps_id' => $this->apps->getId(),
            'companies_id' => $this->company->getId(),
            'resources_id' => $this->variantId,
            'resources_type' => 'Kanvas\Inventory\Variants\Models\Variants',
            'start_at' => $startDate,
            'end_at' => $endDate,
            'rrule' => 'RRULE:FREQ=DAILY',
            'slot_duration_min' => 60,
            'lead_time_min' => 0,
            'cutoff_time_min' => 0,
            'capacity_override' => 5,
        ]);

        new GenerateTimeSlots(
            $this->variantId,
            $scheduleRule->id,
            $startDate,
            $endDate
        )->handle();

        $slots = TimeSlots::where('schedule_rules_id', $scheduleRule->id)->get();

        $this->assertGreaterThan(0, $slots->count());

        foreach ($slots as $slot) {
            $this->assertEquals(100.0, (float) $slot->price_snapshot);
        }
    }

    public function testGenerateTimeSlotsFallsBackToWarehousePriceWhenChannelPriceIsMissing(): void
    {
        $productResponse = $this->graphQLData($this->createProduct(), 'createProduct');
        $variantId = (string) Products::find($productResponse['id'])->variants()->first()->id;

        $this->addVariantToWarehouse(
            variantId: $variantId,
            warehouseId: $this->warehouseId,
            data: [
                'id' => $variantId,
                'data' => [
                    'id' => $this->warehouseId,
                    'price' => 250,
                    'quantity' => 10,
                    'position' => 1,
                ],
            ]
        );

        $startDate = Carbon::now()->addDay()->setTime(9, 0, 0);
        $endDate = $startDate->copy()->addDay();

        $scheduleRule = ScheduleRules::create([
            'apps_id' => $this->apps->getId(),
            'companies_id' => $this->company->getId(),
            'resources_id' => $variantId,
            'resources_type' => 'Kanvas\Inventory\Variants\Models\Variants',
            'start_at' => $startDate,
            'end_at' => $endDate,
            'rrule' => 'RRULE:FREQ=DAILY',
            'slot_duration_min' => 60,
            'lead_time_min' => 0,
            'cutoff_time_min' => 0,
            'capacity_override' => 5,
        ]);

        new GenerateTimeSlots(
            (int) $variantId,
            $scheduleRule->id,
            $startDate,
            $endDate
        )->handle();

        $slots = TimeSlots::where('schedule_rules_id', $scheduleRule->id)->get();

        $this->assertGreaterThan(0, $slots->count());

        foreach ($slots as $slot) {
            $this->assertEquals(250.0, (float) $slot->price_snapshot);
        }
    }

    public function testGenerateTimeSlotsDefaultsCapacityWhenRuleHasNoOverride(): void
    {
        $startDate = Carbon::now()->addDay()->setTime(8, 0, 0);
        $endDate = $startDate->copy()->endOfDay();
        $dayCode = strtoupper(substr($startDate->format('D'), 0, 2));
        $dtstart = $startDate->format('Ymd\THis');

        // Mirrors what CreateScheduleRulesFromOperationDaysAction produces for a rule
        // with no capacity_override — the variant has no default_capacity column, so
        // the job must fall back instead of inserting a null capacity.
        $scheduleRule = ScheduleRules::create([
            'apps_id' => $this->apps->getId(),
            'companies_id' => $this->company->getId(),
            'resources_id' => $this->variantId,
            'resources_type' => 'Kanvas\Inventory\Variants\Models\Variants',
            'start_at' => $startDate,
            'end_at' => $endDate,
            'rrule' => "DTSTART:{$dtstart}\nRRULE:FREQ=WEEKLY;BYDAY={$dayCode}",
            'day_rrule' => "DTSTART:{$dtstart}\nRRULE:FREQ=MINUTELY;INTERVAL=15;BYHOUR=8,9;BYMINUTE=0,15,30,45",
            'slot_duration_min' => 15,
            'lead_time_min' => 0,
            'cutoff_time_min' => 0,
            'capacity_override' => null,
        ]);

        new GenerateTimeSlots(
            $this->variantId,
            $scheduleRule->id,
            $startDate,
            $endDate
        )->handle();

        $slots = TimeSlots::where('schedule_rules_id', $scheduleRule->id)->get();

        $this->assertGreaterThan(0, $slots->count());

        foreach ($slots as $slot) {
            $this->assertEquals(1, $slot->initial_capacity);
        }
    }

    public function testGenerateTimeSlotsWithMultipleHourSlots(): void
    {
        $startDate = Carbon::now()->addDay()->setTime(9, 0, 0);
        $endDate = $startDate->copy()->addDay();

        $scheduleRule = ScheduleRules::create([
            'apps_id' => $this->apps->getId(),
            'companies_id' => $this->company->getId(),
            'resources_id' => $this->variantId,
            'resources_type' => 'Kanvas\Inventory\Variants\Models\Variants',
            'start_at' => $startDate,
            'end_at' => $endDate,
            'rrule' => 'RRULE:FREQ=HOURLY;INTERVAL=2', // Every 2 hours
            'slot_duration_min' => 120, // 2 hour slots
            'lead_time_min' => 0,
            'cutoff_time_min' => 0,
            'capacity_override' => 5,
        ]);

        $job = new GenerateTimeSlots(
            $this->variantId,
            $scheduleRule->id,
            $startDate,
            $endDate
        );
        $job->handle();

        $slots = TimeSlots::where('schedule_rules_id', $scheduleRule->id)->get();

        $this->assertGreaterThan(0, $slots->count());

        // Verify each slot is 2 hours long
        foreach ($slots as $slot) {
            $duration = $slot->start_at->diffInMinutes($slot->end_at);
            $this->assertEquals(120, $duration);
        }
    }

    public function testGenerateTimeSlotsSkipsRuleWhoseResourceWasDeleted(): void
    {
        $startDate = Carbon::now()->addDay()->setTime(9, 0, 0);
        $endDate = $startDate->copy()->addDays(7);

        $scheduleRule = ScheduleRules::create([
            'apps_id' => $this->apps->getId(),
            'companies_id' => $this->company->getId(),
            'resources_id' => $this->variantId,
            'resources_type' => 'Kanvas\Inventory\Variants\Models\Variants',
            'start_at' => $startDate,
            'end_at' => $endDate,
            'rrule' => 'RRULE:FREQ=DAILY',
            'slot_duration_min' => 60,
            'lead_time_min' => 0,
            'cutoff_time_min' => 0,
            'capacity_override' => 5,
        ]);

        // Bypass the model: VariantObserver refuses to delete a product's last variant,
        // but the row can still end up flagged deleted (product deletion, direct cleanup).
        Variants::where('id', $this->variantId)->update(['is_deleted' => 1]);

        new GenerateTimeSlots(
            $this->variantId,
            $scheduleRule->id,
            $startDate,
            $endDate
        )->handle();

        $this->assertSame(0, TimeSlots::where('schedule_rules_id', $scheduleRule->id)->count());
    }

    public function testGenerateTimeSlotsSkipsDeletedRule(): void
    {
        $startDate = Carbon::now()->addDay()->setTime(9, 0, 0);
        $endDate = $startDate->copy()->addDays(7);

        $scheduleRule = ScheduleRules::create([
            'apps_id' => $this->apps->getId(),
            'companies_id' => $this->company->getId(),
            'resources_id' => $this->variantId,
            'resources_type' => 'Kanvas\Inventory\Variants\Models\Variants',
            'start_at' => $startDate,
            'end_at' => $endDate,
            'rrule' => 'RRULE:FREQ=DAILY',
            'slot_duration_min' => 60,
            'lead_time_min' => 0,
            'cutoff_time_min' => 0,
            'capacity_override' => 5,
        ]);

        $scheduleRule->is_deleted = 1;
        $scheduleRule->saveOrFail();

        new GenerateTimeSlots(
            $this->variantId,
            $scheduleRule->id,
            $startDate,
            $endDate
        )->handle();

        $this->assertSame(0, TimeSlots::where('schedule_rules_id', $scheduleRule->id)->count());
    }
}
