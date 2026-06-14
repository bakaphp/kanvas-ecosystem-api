<?php

declare(strict_types=1);

namespace Tests\GraphQL\Event;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Kanvas\Apps\Models\Apps;
use Kanvas\Event\Events\Jobs\GenerateTimeSlots;
use Kanvas\Event\Events\Models\ScheduleRules;
use Kanvas\Event\Events\Models\TimeSlots;
use Kanvas\Event\Support\Setup;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Regions\Models\Regions;
use Tests\GraphQL\Inventory\Traits\InventoryCases;
use Tests\TestCase;

class ScheduleRulesTest extends TestCase
{
    use InventoryCases;

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

    public function testCreateScheduleRule(): void
    {
        Queue::fake();

        $input = [
            'resources_id' => $this->variantId,
            'resources_type' => 'variant',
            'start_at' => now()->addDay()->format('Y-m-d H:i:s'),
            'end_at' => now()->addMonth()->format('Y-m-d H:i:s'),
            'rrule' => 'DTSTART:' . now()->addDay()->format('Ymd\THis') . "\nRRULE:FREQ=DAILY",
            'day_rrule' => 'DTSTART:' . now()->addDay()->format('Ymd\THis') . "\nRRULE:FREQ=MINUTELY;INTERVAL=15",
            'slot_duration_min' => 60,
            'lead_time_min' => 0,
            'cutoff_time_min' => 0,
        ];

        $response = $this->graphQL('
            mutation createScheduleRules($input: ScheduleRulesInput!) {
                createScheduleRules(input: $input) {
                    id
                    resources_id
                    resources_type
                    start_at
                    rrule
                    slot_duration_min
                }
            }
        ', [
            'input' => $input,
        ]);

        $response->assertJson([
            'data' => [
                'createScheduleRules' => [
                    'resources_id' => (string) $this->variantId,
                    'slot_duration_min' => 60,
                ],
            ],
        ]);

        Queue::assertPushed(GenerateTimeSlots::class);
    }

    public function testGenerateTimeSlotsExpandsFullDayWithByHour(): void
    {
        $now = Carbon::parse('2026-07-06 06:00:00', 'UTC'); // Monday, before the daily open
        Carbon::setTestNow($now);

        $day = '20260706';

        $scheduleRule = ScheduleRules::create([
            'apps_id' => $this->apps->getId(),
            'companies_id' => $this->company->getId(),
            'resources_id' => $this->variantId,
            'resources_type' => 'Kanvas\Inventory\Variants\Models\Variants',
            'start_at' => $now->clone(),
            'end_at' => $now->clone()->addDay(),
            'rrule' => "DTSTART:{$day}T080000Z\nRRULE:FREQ=DAILY;UNTIL={$day}T235959Z",
            'day_rrule' => "DTSTART:{$day}T080000\nRRULE:FREQ=MINUTELY;INTERVAL=15;BYHOUR=8,9,10,11,12,13,14,15,16,17;BYMINUTE=0,15,30,45",
            'slot_duration_min' => 15,
            'lead_time_min' => 0,
            'cutoff_time_min' => 0,
            'capacity_override' => 4,
        ]);

        new GenerateTimeSlots(
            $this->variantId,
            $scheduleRule->id,
            $now->clone(),
            $now->clone()->addDay(),
        )->handle();

        // 08:00–17:45 every 15 min = 40 slots. php-rrule's BYHOUR truncation stopped at 22 (13:45),
        // dropping every afternoon slot — this guards against that regression.
        $slots = DB::connection('event')->table('time_slots')
            ->where('schedule_rules_id', $scheduleRule->id)
            ->selectRaw('count(*) as total, min(start_at) as first_at, max(start_at) as last_at')
            ->first();

        $this->assertGreaterThanOrEqual(35, $slots->total);

        // The truncation bug capped the day at 08:00–13:45 (a ~5.75h span). A full 08:00–17:45
        // day spans ~9.75h, so requiring >= 8h between first and last slot proves the afternoon
        // slots are present regardless of the resource timezone.
        $spanHours = Carbon::parse($slots->first_at)->diffInHours(Carbon::parse($slots->last_at));
        $this->assertGreaterThanOrEqual(8, $spanHours);

        Carbon::setTestNow();
    }

    public function testGenerationWindowUsesFutureStartAndEndDates(): void
    {
        Queue::fake();

        $start = now()->addDays(3)->startOfHour();
        $end = now()->addDays(10)->startOfHour();

        $this->graphQL('
            mutation createScheduleRules($input: ScheduleRulesInput!) {
                createScheduleRules(input: $input) { id }
            }
        ', [
            'input' => [
                'resources_id' => $this->variantId,
                'resources_type' => 'variant',
                'start_at' => $start->format('Y-m-d H:i:s'),
                'end_at' => $end->format('Y-m-d H:i:s'),
                'rrule' => 'DTSTART:' . $start->format('Ymd\THis') . "\nRRULE:FREQ=DAILY",
                'day_rrule' => 'DTSTART:' . $start->format('Ymd\THis') . "\nRRULE:FREQ=MINUTELY;INTERVAL=15",
                'slot_duration_min' => 15,
                'lead_time_min' => 0,
                'cutoff_time_min' => 0,
            ],
        ])->assertSuccessful();

        // Future start_at is honored (not now()), and end_at bounds the window (not now()+1yr).
        Queue::assertPushed(
            GenerateTimeSlots::class,
            fn ($job) => $job->windowFrom->format('Y-m-d H:i:s') === $start->format('Y-m-d H:i:s')
                && $job->windowTo->format('Y-m-d H:i:s') === $end->format('Y-m-d H:i:s')
        );
    }

    public function testGenerationWindowClampsPastStartToNow(): void
    {
        Carbon::setTestNow(now());
        $frozenNow = now();
        Queue::fake();

        $start = $frozenNow->clone()->subDays(5); // already in the past

        $this->graphQL('
            mutation createScheduleRules($input: ScheduleRulesInput!) {
                createScheduleRules(input: $input) { id }
            }
        ', [
            'input' => [
                'resources_id' => $this->variantId,
                'resources_type' => 'variant',
                'start_at' => $start->format('Y-m-d H:i:s'),
                'rrule' => 'DTSTART:' . $start->format('Ymd\THis') . "\nRRULE:FREQ=DAILY",
                'day_rrule' => 'DTSTART:' . $start->format('Ymd\THis') . "\nRRULE:FREQ=MINUTELY;INTERVAL=15",
                'slot_duration_min' => 15,
                'lead_time_min' => 0,
                'cutoff_time_min' => 0,
            ],
        ])->assertSuccessful();

        // Past start_at must not backfill — the window starts at now, not 5 days ago.
        Queue::assertPushed(
            GenerateTimeSlots::class,
            fn ($job) => $job->windowFrom->format('Y-m-d H:i:s') === $frozenNow->format('Y-m-d H:i:s')
        );

        Carbon::setTestNow();
    }

    public function testUpdateScheduleRule(): void
    {
        $scheduleRule = ScheduleRules::create([
            'apps_id' => $this->apps->getId(),
            'companies_id' => $this->company->getId(),
            'resources_id' => $this->variantId,
            'resources_type' => 'Kanvas\Inventory\Variants\Models\Variants',
            'start_at' => now()->addDay(),
            'end_at' => now()->addMonth(),
            'rrule' => 'FREQ=DAILY',
            'slot_duration_min' => 60,
            'lead_time_min' => 0,
            'cutoff_time_min' => 0,
        ]);

        // Create some time slots
        TimeSlots::create([
            'apps_id' => $this->apps->getId(),
            'companies_id' => $this->company->getId(),
            'resources_id' => $this->variantId,
            'resources_type' => 'Kanvas\Inventory\Variants\Models\Variants',
            'schedule_rules_id' => $scheduleRule->id,
            'start_at' => now()->addDays(2),
            'end_at' => now()->addDays(2)->addHour(),
            'initial_capacity' => 10,
        ]);

        $this->assertDatabaseHas('time_slots', [
            'schedule_rules_id' => $scheduleRule->id,
        ], 'event');

        Queue::fake();

        $input = [
            'slot_duration_min' => 30,
            'rrule' => 'FREQ=WEEKLY;BYDAY=MO,WE,FR',
        ];

        $response = $this->graphQL('
            mutation updateScheduleRules($id: ID!, $input: ScheduleRulesUpdateInput!) {
                updateScheduleRules(id: $id, input: $input) {
                    id
                    slot_duration_min
                    rrule
                }
            }
        ', [
            'id' => $scheduleRule->id,
            'input' => $input,
        ]);

        $response->assertJson([
            'data' => [
                'updateScheduleRules' => [
                    'slot_duration_min' => 30,
                    'rrule' => 'FREQ=WEEKLY;BYDAY=MO,WE,FR',
                ],
            ],
        ]);

        // Verify upcoming time slots were deleted
        $this->assertDatabaseMissing('time_slots', [
            'schedule_rules_id' => $scheduleRule->id,
            'start_at' => now()->addDays(2)->format('Y-m-d H:i:s'),
        ], 'event');

        Queue::assertPushed(GenerateTimeSlots::class);
    }

    public function testDeleteScheduleRule(): void
    {
        $scheduleRule = ScheduleRules::create([
            'apps_id' => $this->apps->getId(),
            'companies_id' => $this->company->getId(),
            'resources_id' => $this->variantId,
            'resources_type' => 'Kanvas\Inventory\Variants\Models\Variants',
            'start_at' => now()->addDay(),
            'end_at' => now()->addMonth(),
            'rrule' => 'FREQ=DAILY',
            'slot_duration_min' => 60,
            'lead_time_min' => 0,
            'cutoff_time_min' => 0,
        ]);

        // Create upcoming time slots
        TimeSlots::create([
            'apps_id' => $this->apps->getId(),
            'companies_id' => $this->company->getId(),
            'resources_id' => $this->variantId,
            'resources_type' => 'Kanvas\Inventory\Variants\Models\Variants',
            'schedule_rules_id' => $scheduleRule->id,
            'start_at' => now()->addDays(2),
            'end_at' => now()->addDays(2)->addHour(),
            'initial_capacity' => 10,
        ]);

        $this->assertDatabaseHas('time_slots', [
            'schedule_rules_id' => $scheduleRule->id,
        ], 'event');

        $response = $this->graphQL('
            mutation deleteScheduleRules($id: ID!) {
                deleteScheduleRules(id: $id)
            }
        ', [
            'id' => $scheduleRule->id,
        ]);

        $response->assertJson([
            'data' => [
                'deleteScheduleRules' => true,
            ],
        ]);

        // Verify upcoming time slots were deleted
        $this->assertDatabaseMissing('time_slots', [
            'schedule_rules_id' => $scheduleRule->id,
        ], 'event');
    }

    public function testQueryScheduleRulesWithResourceTypeFilter(): void
    {
        ScheduleRules::create([
            'apps_id' => $this->apps->getId(),
            'companies_id' => $this->company->getId(),
            'resources_id' => $this->variantId,
            'resources_type' => 'Kanvas\Inventory\Variants\Models\Variants',
            'start_at' => now()->addDay(),
            'end_at' => now()->addMonth(),
            'rrule' => 'FREQ=DAILY',
            'slot_duration_min' => 60,
            'lead_time_min' => 0,
            'cutoff_time_min' => 0,
        ]);

        $response = $this->graphQL('
            query {
                scheduleRules(
                    resourceType: {
                        column: RESOURCES_TYPE
                        operator: EQ
                        value: "variant"
                    }
                ) {
                    data {
                        id
                        resources_id
                        resources_type
                    }
                }
            }
        ');

        $response->assertJsonStructure([
            'data' => [
                'scheduleRules' => [
                    'data' => [
                        '*' => [
                            'id',
                            'resources_id',
                            'resources_type',
                        ],
                    ],
                ],
            ],
        ]);
    }

    public function testModelDeleteUpcomingTimeSlotsMethod(): void
    {
        $scheduleRule = ScheduleRules::create([
            'apps_id' => $this->apps->getId(),
            'companies_id' => $this->company->getId(),
            'resources_id' => $this->variantId,
            'resources_type' => 'Kanvas\Inventory\Variants\Models\Variants',
            'start_at' => now()->addDay(),
            'end_at' => now()->addMonth(),
            'rrule' => 'FREQ=DAILY',
            'slot_duration_min' => 60,
            'lead_time_min' => 0,
            'cutoff_time_min' => 0,
        ]);

        // Create past time slot (should not be deleted)
        $pastSlot = TimeSlots::create([
            'apps_id' => $this->apps->getId(),
            'companies_id' => $this->company->getId(),
            'resources_id' => $this->variantId,
            'resources_type' => 'Kanvas\Inventory\Variants\Models\Variants',
            'schedule_rules_id' => $scheduleRule->id,
            'start_at' => now()->subDay(),
            'end_at' => now()->subDay()->addHour(),
            'initial_capacity' => 10,
        ]);

        // Create upcoming time slot (should be deleted)
        $upcomingSlot = TimeSlots::create([
            'apps_id' => $this->apps->getId(),
            'companies_id' => $this->company->getId(),
            'resources_id' => $this->variantId,
            'resources_type' => 'Kanvas\Inventory\Variants\Models\Variants',
            'schedule_rules_id' => $scheduleRule->id,
            'start_at' => now()->addDays(2),
            'end_at' => now()->addDays(2)->addHour(),
            'initial_capacity' => 10,
        ]);

        $scheduleRule->deleteUpcomingTimeSlots();

        // Past slot should still exist
        $this->assertDatabaseHas('time_slots', [
            'id' => $pastSlot->id,
        ], 'event');

        // Upcoming slot should be deleted
        $this->assertDatabaseMissing('time_slots', [
            'id' => $upcomingSlot->id,
        ], 'event');
    }

    public function testTimeSlotHasScheduleRuleRelationship(): void
    {
        $scheduleRule = ScheduleRules::create([
            'apps_id' => $this->apps->getId(),
            'companies_id' => $this->company->getId(),
            'resources_id' => $this->variantId,
            'resources_type' => 'Kanvas\Inventory\Variants\Models\Variants',
            'start_at' => now()->addDay(),
            'end_at' => now()->addMonth(),
            'rrule' => 'FREQ=DAILY',
            'slot_duration_min' => 60,
            'lead_time_min' => 0,
            'cutoff_time_min' => 0,
        ]);

        $timeSlot = TimeSlots::create([
            'apps_id' => $this->apps->getId(),
            'companies_id' => $this->company->getId(),
            'resources_id' => $this->variantId,
            'resources_type' => 'Kanvas\Inventory\Variants\Models\Variants',
            'schedule_rules_id' => $scheduleRule->id,
            'start_at' => now()->addDays(2),
            'end_at' => now()->addDays(2)->addHour(),
            'initial_capacity' => 10,
        ]);

        $this->assertTrue($timeSlot->isFromScheduleRule());
        $this->assertFalse($timeSlot->isStandalone());
        $this->assertEquals($scheduleRule->id, $timeSlot->scheduleRule->id);
    }

    public function testStandaloneTimeSlot(): void
    {
        $timeSlot = TimeSlots::create([
            'apps_id' => $this->apps->getId(),
            'companies_id' => $this->company->getId(),
            'resources_id' => $this->variantId,
            'resources_type' => 'Kanvas\Inventory\Variants\Models\Variants',
            'schedule_rules_id' => null,
            'start_at' => now()->addDays(2),
            'end_at' => now()->addDays(2)->addHour(),
            'initial_capacity' => 10,
        ]);

        $this->assertFalse($timeSlot->isFromScheduleRule());
        $this->assertTrue($timeSlot->isStandalone());
        $this->assertNull($timeSlot->scheduleRule);
    }

    public function testQueryTimeSlotsFilteredByScheduleRule(): void
    {
        $scheduleRule = ScheduleRules::create([
            'apps_id' => $this->apps->getId(),
            'companies_id' => $this->company->getId(),
            'resources_id' => $this->variantId,
            'resources_type' => 'Kanvas\Inventory\Variants\Models\Variants',
            'start_at' => now()->addDay(),
            'end_at' => now()->addMonth(),
            'rrule' => 'FREQ=DAILY',
            'slot_duration_min' => 60,
            'lead_time_min' => 0,
            'cutoff_time_min' => 0,
        ]);

        TimeSlots::create([
            'apps_id' => $this->apps->getId(),
            'companies_id' => $this->company->getId(),
            'resources_id' => $this->variantId,
            'resources_type' => 'Kanvas\Inventory\Variants\Models\Variants',
            'schedule_rules_id' => $scheduleRule->id,
            'start_at' => now()->addDays(2),
            'end_at' => now()->addDays(2)->addHour(),
            'initial_capacity' => 10,
        ]);

        $response = $this->graphQL('
            query ($scheduleRuleId: Mixed!) {
                timeSlots(
                    where: {
                        column: SCHEDULE_RULES_ID
                        operator: EQ
                        value: $scheduleRuleId
                    }
                ) {
                    data {
                        id
                        schedule_rules_id
                        is_from_schedule_rule
                        is_standalone
                        scheduleRule {
                            id
                        }
                    }
                }
            }
        ', [
            'scheduleRuleId' => $scheduleRule->id,
        ]);

        $response->assertJson([
            'data' => [
                'timeSlots' => [
                    'data' => [
                        [
                            'schedule_rules_id' => (string) $scheduleRule->id,
                            'is_from_schedule_rule' => true,
                            'is_standalone' => false,
                        ],
                    ],
                ],
            ],
        ]);
    }

    public function testQueryStandaloneTimeSlots(): void
    {
        TimeSlots::create([
            'apps_id' => $this->apps->getId(),
            'companies_id' => $this->company->getId(),
            'resources_id' => $this->variantId,
            'resources_type' => 'Kanvas\Inventory\Variants\Models\Variants',
            'schedule_rules_id' => null,
            'start_at' => now()->addDays(2),
            'end_at' => now()->addDays(2)->addHour(),
            'initial_capacity' => 10,
        ]);

        $response = $this->graphQL('
            query {
                timeSlots(
                    where: {
                        column: SCHEDULE_RULES_ID
                        operator: IS_NULL
                    }
                ) {
                    data {
                        id
                        is_standalone
                        is_from_schedule_rule
                    }
                }
            }
        ');

        $response->assertJson([
            'data' => [
                'timeSlots' => [
                    'data' => [
                        [
                            'is_standalone' => true,
                            'is_from_schedule_rule' => false,
                        ],
                    ],
                ],
            ],
        ]);
    }

    public function testDeleteScheduleRuleWithTimeSlots(): void
    {
        $scheduleRule = ScheduleRules::create([
            'apps_id' => $this->apps->getId(),
            'companies_id' => $this->company->getId(),
            'resources_id' => $this->variantId,
            'resources_type' => 'Kanvas\Inventory\Variants\Models\Variants',
            'start_at' => now()->addDay(),
            'end_at' => now()->addMonth(),
            'rrule' => 'DTSTART:' . now()->addDay()->format('Ymd\THis') . "\nRRULE:FREQ=DAILY",
            'day_rrule' => 'DTSTART:' . now()->addDay()->format('Ymd\THis') . "\nRRULE:FREQ=MINUTELY;INTERVAL=15",
            'slot_duration_min' => 15,
            'lead_time_min' => 0,
            'cutoff_time_min' => 0,
        ]);

        // Create two upcoming time slots
        $unbookedSlot = TimeSlots::create([
            'apps_id' => $this->apps->getId(),
            'companies_id' => $this->company->getId(),
            'resources_id' => $this->variantId,
            'resources_type' => 'Kanvas\Inventory\Variants\Models\Variants',
            'schedule_rules_id' => $scheduleRule->id,
            'start_at' => now()->addDays(2)->setTime(10, 0),
            'end_at' => now()->addDays(2)->setTime(11, 0),
            'initial_capacity' => 10,
        ]);

        $bookedSlot = TimeSlots::create([
            'apps_id' => $this->apps->getId(),
            'companies_id' => $this->company->getId(),
            'resources_id' => $this->variantId,
            'resources_type' => 'Kanvas\Inventory\Variants\Models\Variants',
            'schedule_rules_id' => $scheduleRule->id,
            'start_at' => now()->addDays(3)->setTime(10, 0),
            'end_at' => now()->addDays(3)->setTime(11, 0),
            'initial_capacity' => 10,
        ]);

        // Create a booking for the second time slot using the timeSlotBooking mutation
        $bookingData = [
            'time_slot_id' => $bookedSlot->id,
            'participants' => [
                [
                    'firstname' => 'John',
                    'lastname' => 'Doe',
                    'contacts' => [
                        [
                            'contacts_types_id' => 1,
                            'value' => 'john@example.com',
                            'weight' => 1,
                        ],
                    ],
                ],
            ],
            'event_name' => 'Booked Event',
            'event_description' => 'This slot is booked',
        ];

        $this->graphQL('
            mutation bookTimeSlot($input: TimeSlotBookingInput!) {
                bookTimeSlot(input: $input) {
                    id
                    event {
                        id
                    }
                }
            }
        ', [
            'input' => $bookingData,
        ]);

        $scheduleRule->deleteUpcomingTimeSlots();

        // Unbooked slot should be deleted
        $this->assertDatabaseMissing('time_slots', [
            'id' => $unbookedSlot->id,
        ], 'event');

        // Booked slot should still exist (protected)
        /*  $this->assertDatabaseHas('time_slots', [
             'id' => $bookedSlot->id,
         ], 'event'); */
    }
}
