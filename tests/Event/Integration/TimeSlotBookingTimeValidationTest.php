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

/**
 * Regression: bookTimeSlot only validated capacity, so a slot that had already started (or
 * finished) could be reserved and paid for. The failure only surfaced afterwards in
 * CreatePassAction — "Cannot issue a pass for an event that has already finished." — by which
 * point the customer had been charged.
 */
class TimeSlotBookingTimeValidationTest extends TestCase
{
    use InventoryCases;
    use DatabaseTransactions;

    protected $apps;
    protected $user;
    protected $company;
    protected $region;
    protected Variants $variant;

    /**
     * `inventory` is deliberately NOT transacted. CreateProductAction leans on
     * `DB::connection('inventory')->transaction($cb, 3)` to retry the gap-lock deadlock that
     * concurrent product inserts hit, and Laravel can only retry a transaction it opened
     * itself — wrapping this connection here demotes that one to a savepoint and the
     * deadlock escapes as a 500 instead.
     */
    protected function connectionsToTransact(): array
    {
        return [null, 'event'];
    }

    public function setUp(): void
    {
        parent::setUp();

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

    public function testBookingATimeSlotThatAlreadyFinishedIsRejected(): void
    {
        $timeSlot = $this->makeTimeSlot(
            Carbon::now()->subHours(3),
            Carbon::now()->subHours(2)
        );

        $response = $this->bookTimeSlot($timeSlot);

        $this->assertStringContainsString(
            'already started',
            (string) $response->json('errors.0.message')
        );

        $this->assertSame(0, EventVersion::where('time_slot_id', $timeSlot->id)->count());
    }

    /**
     * The expensive case: the slot is still running, so nothing downstream complains until the
     * pass is issued after payment.
     */
    public function testBookingATimeSlotAlreadyInProgressIsRejected(): void
    {
        $timeSlot = $this->makeTimeSlot(
            Carbon::now()->subMinutes(10),
            Carbon::now()->addMinutes(50)
        );

        $response = $this->bookTimeSlot($timeSlot);

        $this->assertStringContainsString(
            'already started',
            (string) $response->json('errors.0.message')
        );

        $this->assertSame(0, EventVersion::where('time_slot_id', $timeSlot->id)->count());
    }

    public function testBookingAnUpcomingTimeSlotStillSucceeds(): void
    {
        $timeSlot = $this->makeTimeSlot(
            Carbon::now()->addDay(),
            Carbon::now()->addDay()->addHour()
        );

        $response = $this->bookTimeSlot($timeSlot);

        $this->assertNull($response->json('errors'), json_encode($response->json('errors')));
        $this->assertNotNull($response->json('data.bookTimeSlot.id'));
    }

    public function testMovingABookingToATimeSlotThatAlreadyStartedIsRejected(): void
    {
        $upcoming = $this->makeTimeSlot(
            Carbon::now()->addDay(),
            Carbon::now()->addDay()->addHour()
        );

        $eventVersionId = $this->bookTimeSlot($upcoming)->json('data.bookTimeSlot.id');
        $this->assertNotNull($eventVersionId);

        $started = $this->makeTimeSlot(
            Carbon::now()->subMinutes(10),
            Carbon::now()->addMinutes(50)
        );

        $response = $this->graphQL('
            mutation updateTimeSlotBooking($input: TimeSlotBookingUpdateInput!) {
                updateTimeSlotBooking(input: $input) { id }
            }
        ', [
            'input' => [
                'event_version_id' => (string) $eventVersionId,
                'new_time_slot_id' => (string) $started->id,
            ],
        ], [], $this->bookingHeaders());

        $this->assertStringContainsString(
            'already started',
            (string) $response->json('errors.0.message')
        );

        $this->assertEquals(
            $upcoming->id,
            EventVersion::findOrFail($eventVersionId)->time_slot_id
        );
    }

    private function makeTimeSlot(Carbon $startAt, Carbon $endAt): TimeSlots
    {
        return TimeSlots::create([
            'apps_id' => $this->apps->getId(),
            'companies_id' => $this->company->getId(),
            'resources_id' => $this->variant->getId(),
            'resources_type' => $this->variant->getMorphClass(),
            'start_at' => $startAt,
            'end_at' => $endAt,
            'initial_capacity' => 5,
            'status' => 'open',
        ]);
    }

    private function bookTimeSlot(TimeSlots $timeSlot)
    {
        return $this->graphQL('
            mutation bookTimeSlot($input: TimeSlotBookingInput!) {
                bookTimeSlot(input: $input) { id }
            }
        ', [
            'input' => [
                'time_slot_id' => (string) $timeSlot->id,
                'event_name' => 'Time validation booking ' . uniqid(),
                'participants' => [
                    [
                        'firstname' => 'John',
                        'lastname' => 'Doe',
                        'contacts' => [
                            ['contacts_types_id' => 1, 'value' => 'john.slot@example.com', 'weight' => 1],
                        ],
                    ],
                ],
                'metadata' => [
                    'category_id' => EventCategory::fromCompany($this->company)->fromApp($this->apps)->first()->getId(),
                    'type_id' => EventType::fromCompany($this->company)->fromApp($this->apps)->first()->getId(),
                ],
            ],
        ], [], $this->bookingHeaders());
    }

    private function bookingHeaders(): array
    {
        return [
            'X-Kanvas-Location' => $this->company->branch->uuid,
            'X-Kanvas-App' => $this->apps->key,
        ];
    }
}
