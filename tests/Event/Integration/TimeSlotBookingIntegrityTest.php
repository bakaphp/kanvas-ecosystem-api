<?php

declare(strict_types=1);

namespace Tests\Event\Integration;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Event\Events\Actions\CancelEventAction;
use Kanvas\Event\Events\Models\EventCategory;
use Kanvas\Event\Events\Models\EventType;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Event\Events\Models\TimeSlots;
use Kanvas\Event\Events\Services\TimeSlotBookingLock;
use Kanvas\Event\Participants\Models\ParticipantPass;
use Kanvas\Event\Passes\Actions\CreatePassAction;
use Kanvas\Event\Support\Setup;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Regions\Models\Regions;
use Tests\GraphQL\Inventory\Traits\InventoryCases;
use Tests\TestCase;

class TimeSlotBookingIntegrityTest extends TestCase
{
    use InventoryCases;
    use DatabaseTransactions;

    protected $apps;
    protected $user;
    protected $company;
    protected $region;
    protected Variants $variant;

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

    public function testBookingIsRejectedWhileTheSlotLockIsHeld(): void
    {
        $timeSlot = $this->makeTimeSlot(2);
        $heldLock = Cache::lock(TimeSlotBookingLock::key($timeSlot->id), 30);
        $this->assertTrue($heldLock->get());

        try {
            $this->expectException(ValidationException::class);
            TimeSlotBookingLock::run([$timeSlot->id], fn () => 'booked', waitSeconds: 0);
        } finally {
            $heldLock->release();
        }
    }

    public function testTheBookingMutationWaitsForTheSlotLockInsteadOfBypassingIt(): void
    {
        $timeSlot = $this->makeTimeSlot(2);
        $heldLock = Cache::lock(TimeSlotBookingLock::key($timeSlot->id), 30);
        $this->assertTrue($heldLock->get());

        try {
            $response = $this->bookTimeSlot($timeSlot);
        } finally {
            $heldLock->release();
        }

        $this->assertStringContainsString('being booked by someone else', (string) $response->json('errors.0.message'));
        $this->assertSame(0, EventVersion::where('time_slot_id', $timeSlot->id)->count());
    }

    public function testLocksForSeveralSlotsAreAcquiredInOrderAndReleased(): void
    {
        $result = TimeSlotBookingLock::run([9002, 9001, 9002], fn () => 'done');

        $this->assertSame('done', $result);
        $this->assertTrue(Cache::lock(TimeSlotBookingLock::key(9001), 5)->get());
        $this->assertTrue(Cache::lock(TimeSlotBookingLock::key(9002), 5)->get());
    }

    public function testSlotCannotBeOverbookedSequentially(): void
    {
        $timeSlot = $this->makeTimeSlot(1);

        $first = $this->bookTimeSlot($timeSlot);
        $this->assertNull($first->json('errors'), json_encode($first->json('errors')));

        $second = $this->bookTimeSlot($timeSlot);
        $this->assertStringContainsString('enough available capacity', (string) $second->json('errors.0.message'));
        $this->assertSame(1, EventVersion::where('time_slot_id', $timeSlot->id)->count());
    }

    public function testCancellingABookingReleasesTheSlotCapacityAndStatus(): void
    {
        $timeSlot = $this->makeTimeSlot(1);

        $eventVersionId = $this->bookTimeSlot($timeSlot)->json('data.bookTimeSlot.id');
        $this->assertNotNull($eventVersionId);
        $this->assertSame(0, $timeSlot->refresh()->getAvailableSlots());
        $this->assertSame('booked', $timeSlot->status);

        new CancelEventAction(EventVersion::findOrFail($eventVersionId))
            ->withoutNotifications()
            ->disableWorkflow()
            ->execute();

        $timeSlot->refresh();
        $this->assertSame(1, $timeSlot->getAvailableSlots());
        $this->assertSame('open', $timeSlot->status);

        $rebook = $this->bookTimeSlot($timeSlot);
        $this->assertNull($rebook->json('errors'), json_encode($rebook->json('errors')));
    }

    public function testOnlyTheFirstCheckInOfAPassWins(): void
    {
        $timeSlot = $this->makeTimeSlot(2);
        $eventVersionId = $this->bookTimeSlot($timeSlot)->json('data.bookTimeSlot.id');
        $eventVersion = EventVersion::findOrFail($eventVersionId);

        [$pass] = new CreatePassAction($eventVersion->event, $eventVersion)->execute();

        $firstScanner = ParticipantPass::findOrFail($pass->id);
        $secondScanner = ParticipantPass::findOrFail($pass->id);

        $this->assertTrue($firstScanner->markAsUsed());
        $this->assertFalse($secondScanner->markAsUsed());
        $this->assertNotNull(ParticipantPass::findOrFail($pass->id)->used_date);
    }

    private function makeTimeSlot(int $capacity): TimeSlots
    {
        return TimeSlots::create([
            'apps_id' => $this->apps->getId(),
            'companies_id' => $this->company->getId(),
            'resources_id' => $this->variant->getId(),
            'resources_type' => $this->variant->getMorphClass(),
            'start_at' => Carbon::now()->addDay(),
            'end_at' => Carbon::now()->addDay()->addHour(),
            'initial_capacity' => $capacity,
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
                'event_name' => 'Integrity booking ' . uniqid(),
                'participants' => [
                    [
                        'firstname' => 'John',
                        'lastname' => 'Doe',
                        'contacts' => [
                            ['contacts_types_id' => 1, 'value' => 'john.integrity@example.com', 'weight' => 1],
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
    }
}
