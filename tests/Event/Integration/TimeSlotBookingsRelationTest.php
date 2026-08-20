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
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Event\Events\Models\TimeSlots;
use Kanvas\Event\Support\Setup;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Regions\Models\Regions;
use Tests\GraphQL\Inventory\Traits\InventoryCases;
use Tests\TestCase;

class TimeSlotBookingsRelationTest extends TestCase
{
    use DatabaseTransactions;
    use InventoryCases;

    protected $apps;
    protected $user;
    protected $company;
    protected $region;
    protected Variants $variant;

    /**
     * Booking writes to the `event` connection (BuildEventDataAction firstOrCreates a default
     * Theme), which the default DatabaseTransactions wrapping does not roll back — SetupTest
     * asserts an exact theme count and would see the leftover.
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

    public function testTimeSlotExposesItsBookingsInASingleQuery(): void
    {
        $timeSlot = $this->createTimeSlot();
        $eventVersionId = $this->book($timeSlot, 'Agenda booking ');

        $response = $this->queryTimeSlot($timeSlot);

        $this->assertNull($response->json('errors'), json_encode($response->json('errors')));

        $slot = $response->json('data.timeSlots.data.0');

        $this->assertSame((string) $timeSlot->id, $slot['id']);
        $this->assertCount(1, $slot['bookings']);
        $this->assertSame((string) $eventVersionId, $slot['bookings'][0]['id']);
        $this->assertSame(1, $slot['bookings'][0]['total_attendees']);
    }

    public function testEmptySlotReturnsAnEmptyBookingsListInsteadOfNull(): void
    {
        $timeSlot = $this->createTimeSlot();

        $response = $this->queryTimeSlot($timeSlot);

        $this->assertNull($response->json('errors'), json_encode($response->json('errors')));
        $this->assertSame([], $response->json('data.timeSlots.data.0.bookings'));
    }

    public function testSoftDeletedBookingsDropOutOfTheRelation(): void
    {
        $timeSlot = $this->createTimeSlot();
        $eventVersionId = $this->book($timeSlot, 'Cancelled booking ');

        EventVersion::findOrFail($eventVersionId)->softDelete();

        $response = $this->queryTimeSlot($timeSlot);

        $this->assertNull($response->json('errors'), json_encode($response->json('errors')));
        $this->assertSame([], $response->json('data.timeSlots.data.0.bookings'));
    }

    public function testEventVersionExposesItsTimeSlotAndIsFilterableByIt(): void
    {
        $timeSlot = $this->createTimeSlot();
        $eventVersionId = $this->book($timeSlot, 'Filterable booking ');

        $response = $this->graphQL('
            query eventVersions($timeSlotId: Mixed!) {
                eventVersions(
                    where: { column: TIME_SLOT_ID, operator: EQ, value: $timeSlotId }
                ) {
                    data {
                        id
                        timeSlot { id start_at }
                    }
                }
            }
        ', ['timeSlotId' => $timeSlot->id]);

        $this->assertNull($response->json('errors'), json_encode($response->json('errors')));

        $rows = $response->json('data.eventVersions.data');

        $this->assertCount(1, $rows);
        $this->assertSame((string) $eventVersionId, $rows[0]['id']);
        $this->assertSame((string) $timeSlot->id, $rows[0]['timeSlot']['id']);
    }

    private function createTimeSlot(): TimeSlots
    {
        return TimeSlots::create([
            'apps_id' => $this->apps->getId(),
            'companies_id' => $this->company->getId(),
            'resources_id' => $this->variant->getId(),
            'resources_type' => $this->variant->getMorphClass(),
            'start_at' => Carbon::now()->addDay()->setTime(13, 0, 0),
            'end_at' => Carbon::now()->addDay()->setTime(14, 0, 0),
            'initial_capacity' => 4,
            'status' => 'open',
        ]);
    }

    private function book(TimeSlots $timeSlot, string $namePrefix): int
    {
        $response = $this->graphQL('
            mutation bookTimeSlot($input: TimeSlotBookingInput!) {
                bookTimeSlot(input: $input) { id }
            }
        ', [
            'input' => [
                'time_slot_id' => (string) $timeSlot->id,
                'event_name' => $namePrefix . uniqid(),
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

        return (int) $response->json('data.bookTimeSlot.id');
    }

    private function queryTimeSlot(TimeSlots $timeSlot)
    {
        return $this->graphQL('
            query timeSlots($id: Mixed!) {
                timeSlots(where: { column: ID, operator: EQ, value: $id }) {
                    data {
                        id
                        initial_capacity
                        booked_slots
                        bookings {
                            id
                            total_attendees
                            eventStatus { id name }
                        }
                    }
                }
            }
        ', ['id' => $timeSlot->id]);
    }
}
