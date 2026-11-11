<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\BrushCrazy;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\BrushCrazy\Actions\ResolveBrushCrazyUnknownGuestAction;
use Kanvas\Connectors\BrushCrazy\Actions\RetireMovedBrushCrazyEntityAction;
use Kanvas\Connectors\BrushCrazy\Actions\SeedBrushCrazyLookupDataAction;
use Kanvas\Connectors\BrushCrazy\Actions\SyncCalendarableFromBrushCrazyAction;
use Kanvas\Connectors\BrushCrazy\Actions\SyncCommerceCatalogFromBrushCrazyAction;
use Kanvas\Connectors\BrushCrazy\Actions\SyncSaleFromBrushCrazyAction;
use Kanvas\Connectors\BrushCrazy\Actions\SyncStaffFromBrushCrazyAction;
use Kanvas\Connectors\BrushCrazy\Client;
use Kanvas\Connectors\BrushCrazy\Enums\CalendarableTypeEnum;
use Kanvas\Connectors\BrushCrazy\Enums\CustomFieldEnum;
use Kanvas\Connectors\BrushCrazy\Support\IdMapRepository;
use Kanvas\Connectors\BrushCrazy\Support\StudioContext;
use Kanvas\Event\Events\Models\Event;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Event\Events\Models\EventVersionParticipant;
use Kanvas\Event\Facilitators\Models\Facilitator;
use Kanvas\Event\Participants\Models\Participant;
use Kanvas\Event\Participants\Models\ParticipantType;
use Kanvas\Event\Themes\Models\ThemeArea;
use Kanvas\Guild\Customers\Models\PeopleType;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use ReflectionClass;
use stdClass;
use Tests\TestCase;

/**
 * The company here is the OLD studio (2) of a row BrushCrazy moved elsewhere. Only the BrushCrazy
 * reads are out of reach (tests have no connection to that database); every Kanvas write is real.
 */
final class RetireMovedBrushCrazyEntityActionTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'crm', 'event', 'ecosystem', 'commerce', 'inventory'];

    private const int STUDIO = 2;

    private StudioContext $context;

    private int $sourceId;

    protected function setUp(): void
    {
        parent::setUp();

        config(['scout.driver' => 'null', 'scout.queue' => false]);

        // Custom fields also land in Redis, which never rolls back — a fresh source id per test
        // keeps one run from resolving another run's rows.
        $this->sourceId = random_int(10_000_000, 99_999_999);

        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        new SeedBrushCrazyLookupDataAction($app, $company, $user)->execute();

        $type = PeopleType::firstOrCreate(
            [
                'name' => 'Customer',
                'apps_id' => $app->getId(),
                'companies_id' => $company->getId(),
            ],
            ['users_id' => $user->getId()],
        );
        $type->set(CustomFieldEnum::BRUSHCRAZY_LOOKUP_KEY->value, 'people-type:customer');

        $this->context = new StudioContext(
            bcStudioId: self::STUDIO,
            company: $company,
            branch: $company->defaultBranch,
            themeArea: ThemeArea::query()
                ->where('apps_id', $app->getId())
                ->where('companies_id', $company->getId())
                ->where('name', SeedBrushCrazyLookupDataAction::UNASSIGNED)
                ->firstOrFail(),
            timezone: 'America/Denver',
        );
    }

    public function testAMovedClassIsRetiredWithItsMirrorParticipationsAndRestoredWhenItMovesBack(): void
    {
        $version = $this->importLesson();
        $mirror = $this->participation(['brushcrazy' => ['participation_key' => $this->key() . ':u1']]);

        $this->assertSame(RetireMovedBrushCrazyEntityAction::MOVED, $this->action()->calendarable($this->key()));

        $retired = EventVersion::withTrashed()->find($version->getId());
        $event = Event::withTrashed()->find($version->event_id);
        $this->assertTrue((bool) $retired->is_deleted);
        $this->assertTrue((bool) $event->is_deleted);
        $this->assertTrue((bool) EventVersionParticipant::withTrashed()->find($mirror->getId())->is_deleted);
        $this->assertTrue((bool) $retired->get(CustomFieldEnum::BRUSHCRAZY_DELETED_BY_MIRROR->value));
        $this->assertTrue((bool) EventVersionParticipant::withTrashed()->find($mirror->getId())->get(CustomFieldEnum::BRUSHCRAZY_DELETED_BY_MIRROR->value));

        $restored = $this->importLesson();

        $this->assertSame($version->getId(), $restored->getId());
        $this->assertFalse((bool) EventVersion::withTrashed()->find($version->getId())->is_deleted);
        $this->assertFalse((bool) Event::withTrashed()->find($version->event_id)->is_deleted);
    }

    public function testAMovedClassWithASiteBookingIsLeftInPlaceAndFlagged(): void
    {
        $version = $this->importLesson();
        $site = $this->participation(['schema' => 'brushcrazy.event-reservation.v1', 'state' => 'confirmed']);
        $action = $this->action();

        $this->assertSame(RetireMovedBrushCrazyEntityAction::NEEDS_ATTENTION, $action->calendarable($this->key()));

        $this->assertFalse((bool) EventVersion::withTrashed()->find($version->getId())->is_deleted);
        $this->assertFalse((bool) EventVersionParticipant::withTrashed()->find($site->getId())->is_deleted);
        $this->assertCount(1, $action->attention());
    }

    public function testAnExpiredSiteHoldDoesNotKeepAMovedClass(): void
    {
        $version = $this->importLesson();
        $hold = $this->participation([
            'schema' => 'brushcrazy.event-reservation.v1',
            'state' => 'held',
            'hold_expires_at' => Carbon::now('UTC')->subMinutes(5)->format('Y-m-d\TH:i:s.v\Z'),
        ]);

        $this->assertSame(RetireMovedBrushCrazyEntityAction::MOVED, $this->action()->calendarable($this->key()));

        $this->assertTrue((bool) EventVersion::withTrashed()->find($version->getId())->is_deleted);
        $this->assertFalse((bool) EventVersionParticipant::withTrashed()->find($hold->getId())->is_deleted);
    }

    public function testAMovedSaleRetiresItsMirrorOrder(): void
    {
        $order = $this->mirrorOrder();

        $this->assertSame(RetireMovedBrushCrazyEntityAction::MOVED, $this->action()->sale($this->sourceId));

        $retired = Order::withTrashed()->find($order->getId());
        $this->assertTrue((bool) $retired->is_deleted);
        $this->assertTrue((bool) $retired->get(CustomFieldEnum::BRUSHCRAZY_DELETED_BY_MIRROR->value));
    }

    public function testAnOrderCreatedInKanvasIsNeverRetired(): void
    {
        $order = $this->mirrorOrder();
        $order->disableWorkflows();
        $order->set(CustomFieldEnum::BRUSHCRAZY_ORIGIN->value, SyncSaleFromBrushCrazyAction::ORIGIN_KANVAS);
        $action = $this->action();

        $this->assertSame(RetireMovedBrushCrazyEntityAction::NEEDS_ATTENTION, $action->sale($this->sourceId));

        $this->assertFalse((bool) Order::withTrashed()->find($order->getId())->is_deleted);
        $this->assertCount(1, $action->attention());
    }

    public function testAnEmployeeMovedAwayLeavesTheFacilitatorInactiveButLive(): void
    {
        $staff = $this->staff();
        $facilitator = $staff->fromRow($this->userRow(), [$this->employment()], false);

        $this->assertSame(
            RetireMovedBrushCrazyEntityAction::MOVED,
            $this->action()->employee($this->client(), $staff, $this->sourceId)
        );

        $fresh = Facilitator::withTrashed()->find($facilitator->getId());
        $this->assertFalse((bool) $fresh->is_deleted);
        $this->assertFalse((bool) $fresh->getCustomField(CustomFieldEnum::BRUSHCRAZY_STAFF_ACTIVE->value)?->value);
    }

    public function testRowsNeverMirroredHereStayMisrouted(): void
    {
        $action = $this->action();

        $this->assertSame(RetireMovedBrushCrazyEntityAction::MISROUTED, $action->calendarable($this->key()));
        $this->assertSame(RetireMovedBrushCrazyEntityAction::MISROUTED, $action->sale($this->sourceId));
        $this->assertSame(
            RetireMovedBrushCrazyEntityAction::MISROUTED,
            $action->employee($this->client(), $this->staff(), $this->sourceId)
        );
    }

    private function action(): RetireMovedBrushCrazyEntityAction
    {
        return new RetireMovedBrushCrazyEntityAction(app(Apps::class), $this->context);
    }

    private function key(): string
    {
        return CalendarableTypeEnum::LESSON->value . ':' . $this->sourceId;
    }

    private function importLesson(): EventVersion
    {
        return new SyncCalendarableFromBrushCrazyAction(
            app(Apps::class),
            auth()->user(),
            $this->context,
            Carbon::now('UTC'),
        )->fromRow(CalendarableTypeEnum::LESSON, (object) [
            'id' => $this->sourceId,
            'studio_id' => self::STUDIO,
            'name' => 'Starry Night',
            'description' => null,
            'type' => null,
            'start' => '2025-03-01 01:00:00',
            'end' => '2025-03-01 03:00:00',
            'occupancy' => 20,
            'painting_id' => null,
            'assignee_id' => null,
            'published_at' => '2025-02-01 00:00:00',
            'rsvp' => 0,
            'private' => 0,
            'fundraiser' => 0,
            'password' => null,
            'deleted_at' => null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function participation(array $metadata): EventVersionParticipant
    {
        $versionId = new IdMapRepository()->loadFor(
            $this->context->companyId(),
            EventVersion::class,
            CustomFieldEnum::BRUSHCRAZY_CALENDARABLE_KEY,
            [$this->key()],
        )[$this->key()];

        $people = new ResolveBrushCrazyUnknownGuestAction(app(Apps::class), auth()->user(), $this->context)->execute();
        $participant = new Participant();
        $participant->forceFill([
            'uuid' => Str::uuid()->toString(),
            'apps_id' => app(Apps::class)->getId(),
            'companies_id' => $this->context->companyId(),
            'users_id' => auth()->user()->getId(),
            'people_id' => $people->getId(),
            'theme_area_id' => $this->context->themeAreaId(),
            'participant_status_id' => 1,
            'slug' => 'moved-attendee-' . Str::uuid()->toString(),
        ]);
        $participant->saveQuietly();

        $participation = new EventVersionParticipant();
        $participation->forceFill([
            'event_version_id' => $versionId,
            'participant_id' => $participant->getId(),
            'participant_type_id' => new IdMapRepository()->lookup(
                $this->context->companyId(),
                ParticipantType::class,
                'participant-type:attendee'
            ),
            'ticket_price' => 35,
            'discount' => 0,
            'metadata' => $metadata,
            'is_deleted' => false,
        ]);
        $participation->saveQuietly();

        return $participation;
    }

    /** An order as the sale mirror leaves it: mapped by BRUSHCRAZY_SALE_ID, no kanvas origin. */
    private function mirrorOrder(): Order
    {
        $user = auth()->user();
        $catalog = new SyncCommerceCatalogFromBrushCrazyAction(app(Apps::class), $user, $this->context);

        $order = Order::withoutEvents(fn () => Order::factory()
            ->withCompanyId($this->context->companyId())
            ->withUserId($user->getId())
            ->completed()
            ->create([
                'order_number' => $this->sourceId,
                'region_id' => $catalog->region()->getId(),
                'total_gross_amount' => 37.45,
                'payment_status' => PaymentStatusEnum::PAID->value,
                'metadata' => ['brushcrazy' => ['sale_id' => $this->sourceId, 'studio_id' => self::STUDIO]],
            ]));
        $order->disableWorkflows();
        $order->set(CustomFieldEnum::BRUSHCRAZY_SALE_ID->value, $this->sourceId);

        return $order;
    }

    /**
     * Stands in for the source: this user is no longer staff of the old studio.
     */
    private function staff(): SyncStaffFromBrushCrazyAction
    {
        return new class (app(Apps::class), auth()->user(), $this->context) extends SyncStaffFromBrushCrazyAction {
            public function fromUserId(Client $client, int $bcUserId): ?Facilitator
            {
                return $this->deactivate($bcUserId);
            }
        };
    }

    /** Never queried: the staff stand-in above does not read the source. */
    private function client(): Client
    {
        return new ReflectionClass(Client::class)->newInstanceWithoutConstructor();
    }

    private function userRow(): stdClass
    {
        return (object) [
            'id' => $this->sourceId,
            'name' => 'Jane Doe',
            'display_name' => null,
            'email' => 'jane.' . $this->sourceId . '@example.com',
            'phone_number' => null,
            'birthday' => null,
            'street_number' => null,
            'route' => null,
            'suite' => null,
            'locality' => null,
            'administrative_area_level_1' => null,
            'postal_code' => null,
            'points' => 0,
            'rewards' => null,
            'settings' => null,
            'favorite_studio' => null,
            'last_studio' => null,
            'created_at' => '2020-05-01 10:00:00',
            'deleted_at' => null,
        ];
    }

    private function employment(): stdClass
    {
        return (object) [
            'id' => 1,
            'studio_id' => self::STUDIO,
            'user_id' => $this->sourceId,
            'employee_id' => 1234,
            'position' => 'Artist',
            'wage' => '{"amount":1500,"currency":"USD"}',
            'hired_at' => '2021-05-01 00:00:00',
            'invited_at' => '2021-04-30 00:00:00',
            'verified_at' => '2021-05-01 00:00:00',
            'terminated_at' => null,
            'transitioned_at' => null,
            'deleted_at' => null,
        ];
    }
}
