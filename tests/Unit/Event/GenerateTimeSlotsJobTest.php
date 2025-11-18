<?php

declare(strict_types=1);

namespace Tests\Unit\Event;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Event\Events\Jobs\GenerateTimeSlots;
use Kanvas\Event\Events\Models\ScheduleException;
use Kanvas\Event\Events\Models\ScheduleRules;
use Kanvas\Event\Events\Models\TimeSlots;
use Kanvas\Event\Support\Setup;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Regions\Models\Regions;
use Tests\GraphQL\Inventory\Traits\InventoryCases;
use Tests\TestCase;

class GenerateTimeSlotsJobTest extends TestCase
{
    use InventoryCases;
    use RefreshDatabase;

    protected $variant;
    protected $region;
    protected $company;
    protected $user;
    protected $apps;
    protected $variantId;

    public function setUp(): void
    {
        parent::setUp();

        $this->apps = app(Apps::class);
        $this->user = auth()->user();
        $this->company = $this->user->getCurrentCompany();
        $this->region = Regions::getDefault($this->company, $this->apps);

        $warehouseResponse = $this->createWarehouses((string) $this->region->getId())->json()['data']['createWarehouse'];
        $channelResponse = $this->createChannel()->json()['data']['createChannel'];

        $productResponse = $this->createProduct(attributes: [
            [
                'name' => 'event_slot',
                'value' => 100,
            ],
        ])->json()['data']['createProduct'];

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
            'rrule' => 'FREQ=DAILY',
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
            'rrule' => 'FREQ=WEEKLY;BYDAY=MO,WE,FR',
            'slot_duration_min' => 30,
            'lead_time_min' => 0,
            'cutoff_time_min' => 0,
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
            'rrule' => 'FREQ=DAILY',
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

        $finalCount = TimeSlots::where('schedule_rules_id', $scheduleRule->id)->count();

        // Count should remain the same (no duplicates)
        $this->assertEquals($initialCount, $finalCount);

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
        $endDate = $startDate->copy()->addDays(7);

        $scheduleRule = ScheduleRules::create([
            'apps_id' => $this->apps->getId(),
            'companies_id' => $this->company->getId(),
            'resources_id' => $this->variantId,
            'resources_type' => 'Kanvas\Inventory\Variants\Models\Variants',
            'start_at' => $startDate,
            'end_at' => $endDate,
            'rrule' => 'FREQ=DAILY',
            'slot_duration_min' => 60,
            'lead_time_min' => 0,
            'cutoff_time_min' => 0,
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
            'rrule' => 'FREQ=DAILY',
            'slot_duration_min' => 60,
            'lead_time_min' => 0,
            'cutoff_time_min' => 0,
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
            'rrule' => 'FREQ=HOURLY;INTERVAL=2', // Every 2 hours
            'slot_duration_min' => 120, // 2 hour slots
            'lead_time_min' => 0,
            'cutoff_time_min' => 0,
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
}
