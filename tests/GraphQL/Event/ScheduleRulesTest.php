<?php

declare(strict_types=1);

namespace Tests\GraphQL\Event;

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
