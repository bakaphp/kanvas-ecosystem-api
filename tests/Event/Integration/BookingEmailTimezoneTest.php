<?php

declare(strict_types=1);

namespace Tests\Event\Integration;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Event\Events\Actions\SendEventEmailsAction;
use Kanvas\Event\Events\Enums\EmailTemplateEnum;
use Kanvas\Event\Events\Models\EventCategory;
use Kanvas\Event\Events\Models\EventReminder;
use Kanvas\Event\Events\Models\EventType;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Event\Events\Models\TimeSlots;
use Kanvas\Event\Events\Notifications\EventParticipantNotification;
use Kanvas\Event\Events\Services\ResourceTimezoneService;
use Kanvas\Event\Support\Setup;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Regions\Models\Regions;
use Tests\GraphQL\Inventory\Traits\InventoryCases;
use Tests\TestCase;

class BookingEmailTimezoneTest extends TestCase
{
    use InventoryCases;
    use DatabaseTransactions;

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

        $this->variant->set(ResourceTimezoneService::CUSTOM_FIELD, 'America/Santo_Domingo');

        new Setup($this->apps, $this->user, $this->company)->run();
    }

    public function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function testBookingEmailRendersTheResourceLocalTimeWhileKeepingTheInstantInUtc(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-05 12:00:00', 'UTC'));
        Notification::fake();

        // 13:00 UTC is 09:00 in Santo Domingo — the hour the venue actually opens.
        $timeSlot = TimeSlots::create([
            'apps_id' => $this->apps->getId(),
            'companies_id' => $this->company->getId(),
            'resources_id' => $this->variant->getId(),
            'resources_type' => $this->variant->getMorphClass(),
            'start_at' => Carbon::parse('2026-08-06 13:00:00', 'UTC'),
            'end_at' => Carbon::parse('2026-08-06 14:00:00', 'UTC'),
            'initial_capacity' => 5,
            'status' => 'open',
        ]);

        $response = $this->graphQL('
            mutation bookTimeSlot($input: TimeSlotBookingInput!) {
                bookTimeSlot(input: $input) { id }
            }
        ', [
            'input' => [
                'time_slot_id' => (string) $timeSlot->id,
                'event_name' => 'Timezone booking ' . uniqid(),
                'participants' => [
                    [
                        'firstname' => 'John',
                        'lastname' => 'Doe',
                        'contacts' => [
                            ['contacts_types_id' => 1, 'value' => 'john.tz@example.com', 'weight' => 1],
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

        $eventVersion = EventVersion::findOrFail($response->json('data.bookTimeSlot.id'));

        // The stored instant stays UTC — scheduling and comparisons depend on it.
        $this->assertEquals('2026-08-06 13:00', $eventVersion->start_at->format('Y-m-d H:i'));

        $reminder = EventReminder::where('event_version_id', $eventVersion->getId())
            ->where('notification_type', EmailTemplateEnum::BOOKING_REMINDER->value)
            ->firstOrFail();

        $this->assertEquals('2026-08-05 13:00', $reminder->send_at->format('Y-m-d H:i'));

        Notification::fake();

        new SendEventEmailsAction($eventVersion)->execute();

        Notification::assertSentOnDemand(
            EventParticipantNotification::class,
            function (EventParticipantNotification $notification) {
                $data = $notification->getData();

                return $data['start_date'] === '2026-08-06'
                    && $data['start_time'] === '09:00'
                    && $data['end_time'] === '10:00'
                    && $data['timezone'] === 'America/Santo_Domingo';
            }
        );
    }
}
