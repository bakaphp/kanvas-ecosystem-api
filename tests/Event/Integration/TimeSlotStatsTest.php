<?php

declare(strict_types=1);

namespace Tests\Event\Integration;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Event\Events\Models\EventCategory;
use Kanvas\Event\Events\Models\EventType;
use Kanvas\Event\Events\Models\TimeSlots;
use Kanvas\Event\Support\Setup;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Regions\Models\Regions;
use Tests\GraphQL\Inventory\Traits\InventoryCases;
use Tests\TestCase;

class TimeSlotStatsTest extends TestCase
{
    use DatabaseTransactions;
    use InventoryCases;

    protected $apps;
    protected $user;
    protected $company;
    protected $region;
    protected Variants $variant;

    private string $statsQuery = '
        query timeSlotStats($input: TimeSlotStatsInput!) {
            timeSlotStats(input: $input) {
                capacity
                booked
                occupancy_percentage
                slots_count
                byHour { label capacity booked occupancy_percentage slots_count }
                byDay { label capacity booked occupancy_percentage }
                peak { label occupancy_percentage }
                lowest { label occupancy_percentage }
            }
        }
    ';

    /**
     * Booking writes to the `event` connection (BuildEventDataAction firstOrCreates a default
     * Theme), which the default DatabaseTransactions wrapping does not roll back.
     *
     * `inventory` stays out on purpose: CreateProductAction relies on
     * `DB::connection('inventory')->transaction($cb, 3)` to retry the gap-lock deadlock
     * concurrent product inserts hit, and Laravel only retries a transaction it opened
     * itself — listing the connection here demotes that one to a savepoint and the
     * deadlock escapes as a 500.
     */
    protected function connectionsToTransact(): array
    {
        return [null, 'event'];
    }

    public function setUp(): void
    {
        parent::setUp();

        // Booking a free tee time also fires the BOOKING_CREATED email, which needs the
        // `booking_created` template seeded. Faking keeps these tests off that dependency.
        Notification::fake();

        $this->apps = app(Apps::class);
        $this->user = Auth::user();
        $this->company = $this->user->getCurrentCompany();
        $this->region = Regions::getDefault($this->company, $this->apps);

        $warehouseResponse = $this->graphQLData($this->createWarehouses((string) $this->region->getId()), 'createWarehouse');
        $channelResponse = $this->graphQLData($this->createChannel(), 'createChannel');
        $productResponse = $this->graphQLData($this->createProduct(), 'createProduct');

        $this->variant = Products::find($productResponse['id'])->variants()->first();

        $this->graphQLData(
            $this->addVariantToChannel(
                variantId: (string) $this->variant->getId(),
                channelId: $channelResponse['id'],
                warehouseData: ['id' => $warehouseResponse['id']]
            ),
            'addVariantToChannel'
        );

        $this->graphQLData(
            $this->addVariantToWarehouse(
                variantId: (string) $this->variant->getId(),
                warehouseId: (string) $warehouseResponse['id'],
                amount: 10
            ),
            'addVariantToWarehouse'
        );

        $this->company->timezone = 'UTC';
        $this->company->saveOrFail();

        new Setup($this->apps, $this->user, $this->company)->run();
    }

    public function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function testOccupancyCountsCapacityAndBookedPlayers(): void
    {
        $day = Carbon::now()->addDay()->format('Y-m-d');

        $morning = $this->createSlot($day . ' 13:00:00', capacity: 4);
        $this->createSlot($day . ' 15:00:00', capacity: 4);

        $this->book($morning);

        $stats = $this->queryStats($day);

        $this->assertSame(8, $stats['capacity']);
        $this->assertSame(1, $stats['booked']);
        $this->assertSame(12.5, $stats['occupancy_percentage']);
        $this->assertSame(2, $stats['slots_count']);
    }

    public function testEmptyHoursStayVisibleInTheHourBreakdown(): void
    {
        $day = Carbon::now()->addDay()->format('Y-m-d');

        $morning = $this->createSlot($day . ' 13:00:00', capacity: 4);
        $this->createSlot($day . ' 15:00:00', capacity: 4);

        $this->book($morning);

        $byHour = collect($this->queryStats($day)['byHour'])->keyBy('label');

        $this->assertTrue($byHour->has('15:00'), 'an hour with no bookings must still appear — it is the valley hour the card highlights');
        $this->assertSame(0, $byHour['15:00']['booked']);
        $this->assertSame(4, $byHour['15:00']['capacity']);
        $this->assertEquals(25.0, $byHour['13:00']['occupancy_percentage']);
    }

    public function testPeakAndLowestHours(): void
    {
        $day = Carbon::now()->addDay()->format('Y-m-d');

        $morning = $this->createSlot($day . ' 13:00:00', capacity: 4);
        $this->createSlot($day . ' 15:00:00', capacity: 4);

        $this->book($morning);

        $stats = $this->queryStats($day);

        $this->assertSame('13:00', $stats['peak']['label']);
        $this->assertSame('15:00', $stats['lowest']['label']);
    }

    public function testHourBucketsUseTheRequestedTimezoneNotUtc(): void
    {
        $day = Carbon::now()->addDay()->format('Y-m-d');

        $this->createSlot($day . ' 13:00:00', capacity: 4);

        $stats = $this->queryStats($day, timezone: 'America/Santo_Domingo');

        $labels = array_column($stats['byHour'], 'label');

        $this->assertContains('09:00', $labels, '13:00 UTC is 09:00 in Santo Domingo — the hour the venue actually opens');
        $this->assertNotContains('13:00', $labels);
    }

    public function testEmptyWindowDoesNotDivideByZero(): void
    {
        $stats = $this->queryStats(Carbon::now()->addYear()->format('Y-m-d'));

        $this->assertSame(0, $stats['capacity']);
        $this->assertEquals(0.0, $stats['occupancy_percentage']);
        $this->assertNull($stats['peak']);
        $this->assertNull($stats['lowest']);
    }

    private function createSlot(string $startAtUtc, int $capacity): TimeSlots
    {
        $start = Carbon::parse($startAtUtc, 'UTC');

        return TimeSlots::create([
            'apps_id' => $this->apps->getId(),
            'companies_id' => $this->company->getId(),
            'resources_id' => $this->variant->getId(),
            'resources_type' => $this->variant->getMorphClass(),
            'start_at' => $start,
            'end_at' => $start->copy()->addHour(),
            'initial_capacity' => $capacity,
            'status' => 'open',
        ]);
    }

    private function book(TimeSlots $timeSlot): void
    {
        $response = $this->graphQL('
            mutation bookTimeSlot($input: TimeSlotBookingInput!) {
                bookTimeSlot(input: $input) { id total_attendees }
            }
        ', [
            'input' => [
                'time_slot_id' => (string) $timeSlot->id,
                'event_name' => 'Occupancy booking ' . uniqid(),
                'participants' => [
                    [
                        'firstname' => 'John',
                        'lastname' => 'Doe',
                        'contacts' => [
                            ['contacts_types_id' => 1, 'value' => uniqid() . '@example.com', 'weight' => 1],
                        ],
                    ],
                ],
                'metadata' => [
                    'category_id' => EventCategory::fromCompany($this->company)->fromApp($this->apps)->first()->getId(),
                    'type_id' => EventType::fromCompany($this->company)->fromApp($this->apps)->first()->getId(),
                ],
            ],
        ], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
            'X-Kanvas-App' => $this->apps->key,
        ]);

        $this->assertNull($response->json('errors'), json_encode($response->json('errors')));
    }

    private function queryStats(string $day, string $timezone = 'UTC'): array
    {
        $response = $this->graphQL($this->statsQuery, [
            'input' => [
                'startDate' => $day,
                'endDate' => $day,
                'timezone' => $timezone,
                'resources_id' => (string) $this->variant->getId(),
                'resources_type' => $this->variant->getMorphClass(),
            ],
        ]);

        $this->assertNull($response->json('errors'), json_encode($response->json('errors')));

        return $response->json('data.timeSlotStats');
    }
}
