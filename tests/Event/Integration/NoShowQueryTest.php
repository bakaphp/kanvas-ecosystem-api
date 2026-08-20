<?php

declare(strict_types=1);

namespace Tests\Event\Integration;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\TeeTime\Enums\EventStatusEnum;
use Kanvas\Event\Events\Models\EventCategory;
use Kanvas\Event\Events\Models\EventStatus;
use Kanvas\Event\Events\Models\EventType;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Event\Events\Models\TimeSlots;
use Kanvas\Event\Participants\Models\ParticipantPass;
use Kanvas\Event\Support\Setup;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Regions\Models\Regions;
use Tests\GraphQL\Inventory\Traits\InventoryCases;
use Tests\TestCase;

class NoShowQueryTest extends TestCase
{
    use DatabaseTransactions;
    use InventoryCases;

    protected $apps;
    protected $user;
    protected $company;
    protected $region;
    protected Variants $variant;

    private string $noShowsQuery = '
        query participantPasses($from: Mixed!, $to: Mixed!) {
            participantPasses(
                no_show: true
                hasEventVersion: {
                    AND: [
                        { column: START_AT, operator: GTE, value: $from }
                        { column: START_AT, operator: LTE, value: $to }
                    ]
                }
            ) {
                data {
                    id
                    used_date
                    participant { id }
                    eventVersion { id name start_at }
                }
                paginatorInfo { total }
            }
        }
    ';

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

    public function testPlayerWhoNeverCheckedInShowsUpAfterTheTeeTimePassed(): void
    {
        $eventVersion = $this->bookAndIssuePasses();

        $this->travelPastTheTeeTime();

        $rows = $this->queryNoShows();

        $this->assertCount(1, $rows);
        $this->assertSame((string) $eventVersion->getId(), $rows[0]['eventVersion']['id']);
        $this->assertNull($rows[0]['used_date']);
    }

    /**
     * `forAllParticipants()` also issues an event-level pass with a null participant. Counting it
     * would inflate every foursome by one phantom no-show.
     */
    public function testEventLevelPassIsNotCountedAsAPlayer(): void
    {
        $eventVersion = $this->bookAndIssuePasses();

        $issued = ParticipantPass::where('event_version_id', $eventVersion->getId())->count();
        $this->assertGreaterThan(1, $issued, 'the flow must have issued a participant pass and an event-level one');

        $this->travelPastTheTeeTime();

        $this->assertCount(1, $this->queryNoShows());
    }

    public function testCheckedInPlayerIsNotANoShow(): void
    {
        $eventVersion = $this->bookAndIssuePasses();

        ParticipantPass::where('event_version_id', $eventVersion->getId())
            ->whereNotNull('participant_id')
            ->update(['used_date' => Carbon::now()]);

        $this->travelPastTheTeeTime();

        $this->assertCount(0, $this->queryNoShows());
    }

    public function testCancelledBookingIsNotANoShow(): void
    {
        $eventVersion = $this->bookAndIssuePasses();

        $cancelled = EventStatus::firstOrCreate([
            'companies_id' => $this->company->getId(),
            'apps_id' => $this->apps->getId(),
            'name' => EventStatusEnum::CANCELLED->value,
        ], [
            'users_id' => $this->user->getId(),
            'slug' => EventStatusEnum::CANCELLED->value,
        ]);

        $eventVersion->event->update(['event_status_id' => $cancelled->getId()]);

        $this->travelPastTheTeeTime();

        $this->assertCount(0, $this->queryNoShows(), 'nobody was expected to show up for a cancelled tee time');
    }

    public function testBookingThatHasNotStartedYetIsNotANoShow(): void
    {
        $this->bookAndIssuePasses();

        $this->assertCount(0, $this->queryNoShows());
    }

    private function bookAndIssuePasses(): EventVersion
    {
        Carbon::setTestNow(Carbon::parse('2026-08-05 12:00:00', 'UTC'));

        $timeSlot = TimeSlots::create([
            'apps_id' => $this->apps->getId(),
            'companies_id' => $this->company->getId(),
            'resources_id' => $this->variant->getId(),
            'resources_type' => $this->variant->getMorphClass(),
            'start_at' => Carbon::parse('2026-08-05 14:00:00', 'UTC'),
            'end_at' => Carbon::parse('2026-08-05 15:00:00', 'UTC'),
            'initial_capacity' => 4,
            'status' => 'open',
        ]);

        $response = $this->graphQL('
            mutation bookTimeSlot($input: TimeSlotBookingInput!) {
                bookTimeSlot(input: $input) { id }
            }
        ', [
            'input' => [
                'time_slot_id' => (string) $timeSlot->id,
                'event_name' => 'No-show booking ' . uniqid(),
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

        // No order is created for this booking, so CreateEventAction already issued the passes
        // (participant-level plus one event-level). Issuing them again here would double every row.
        return EventVersion::findOrFail($response->json('data.bookTimeSlot.id'));
    }

    private function travelPastTheTeeTime(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-05 18:00:00', 'UTC'));
    }

    private function queryNoShows(): array
    {
        $response = $this->graphQL($this->noShowsQuery, [
            'from' => '2026-08-01',
            'to' => '2026-08-31',
        ]);

        $this->assertNull($response->json('errors'), json_encode($response->json('errors')));

        return $response->json('data.participantPasses.data');
    }
}
