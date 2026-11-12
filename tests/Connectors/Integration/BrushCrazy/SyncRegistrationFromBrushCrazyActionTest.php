<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\BrushCrazy;

use Baka\Contracts\AppInterface;
use Baka\Support\Str;
use Baka\Users\Contracts\UserInterface;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\BrushCrazy\Actions\ResolveBrushCrazyUnknownGuestAction;
use Kanvas\Connectors\BrushCrazy\Actions\SeedBrushCrazyLookupDataAction;
use Kanvas\Connectors\BrushCrazy\Actions\SyncCalendarableFromBrushCrazyAction;
use Kanvas\Connectors\BrushCrazy\Actions\SyncCustomerFromBrushCrazyAction;
use Kanvas\Connectors\BrushCrazy\Actions\SyncRegistrationFromBrushCrazyAction;
use Kanvas\Connectors\BrushCrazy\Client;
use Kanvas\Connectors\BrushCrazy\Enums\CalendarableTypeEnum;
use Kanvas\Connectors\BrushCrazy\Enums\CustomFieldEnum;
use Kanvas\Connectors\BrushCrazy\Mappers\RegistrationMapper;
use Kanvas\Connectors\BrushCrazy\Support\IdMapRepository;
use Kanvas\Connectors\BrushCrazy\Support\StudioContext;
use Kanvas\Connectors\BrushCrazy\Support\StudioCredits;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Event\Events\Models\EventVersionParticipant;
use Kanvas\Event\Participants\Models\Participant;
use Kanvas\Event\Participants\Models\ParticipantType;
use Kanvas\Event\Themes\Models\ThemeArea;
use Kanvas\Guild\Customers\Models\People;
use RuntimeException;
use stdClass;
use Tests\TestCase;

/**
 * Every test enters through syncCalendarables(), the path the pull and the webhook take. Only the
 * BrushCrazy reads are replaced (tests have no connection to that database); the per-class lock,
 * routing, writes and recount are the real ones.
 */
final class SyncRegistrationFromBrushCrazyActionTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'event', 'crm', 'ecosystem'];

    private int $calendarableId;

    private int $buyerA;

    private int $buyerB;

    private EventVersion $version;

    /** @var array<string, array{registrations?: array<int, stdClass>, attendees?: array<int, stdClass>}> */
    private array $classes = [];

    /** @var array<int, stdClass> */
    private array $users = [];

    /** @var array<string, int> */
    private array $studios = [];

    private ?int $siteParticipantId = null;

    protected function setUp(): void
    {
        parent::setUp();

        // Custom fields also land in Redis, which never rolls back — fresh source ids per test keep
        // one run from resolving another run's rows.
        $this->calendarableId = random_int(10_000_000, 99_999_999);
        $this->buyerA = random_int(10_000_000, 49_999_999);
        $this->buyerB = random_int(50_000_000, 99_999_999);

        $user = auth()->user();
        new SeedBrushCrazyLookupDataAction(app(Apps::class), $user->getCurrentCompany(), $user)->execute();

        $this->version = $this->importCalendarable($this->calendarableId);
        $this->studios[$this->key()] = 3;
    }

    public function testCreatesOneParticipationPerBuyerWithItsSeats(): void
    {
        $results = $this->sync(
            [
                $this->registration(1, $this->buyerA),
                $this->registration(2, $this->buyerA),
                $this->registration(3, $this->buyerB),
            ],
            [
                $this->attendee(10, 1),
                $this->attendee(11, 1),
                $this->attendee(12, 2),
                $this->attendee(13, 3),
            ],
        );

        $mine = $this->participationOf($this->buyerA);

        $this->assertSame(1, $results['synced']);
        $this->assertSame(2, $results['participants']);
        $this->assertCount(2, $this->participations());
        $this->assertSame(3, $mine->metadata['brushcrazy']['seat_count']);
        $this->assertSame($this->key() . ':u' . $this->buyerA, $mine->get(CustomFieldEnum::BRUSHCRAZY_PARTICIPATION_KEY->value));
        $this->assertSame($this->buyerA, (int) $mine->participant->people->get(CustomFieldEnum::BRUSHCRAZY_USER_ID->value));

        // Seats, not participation rows: and the observer would have added one per row on top.
        $this->assertSame(4, (int) $this->version->fresh()->total_attendees);
    }

    public function testReSyncUpdatesTheSameRows(): void
    {
        $this->sync([$this->registration(1, $this->buyerA)], [$this->attendee(10, 1)]);
        $first = $this->participationOf($this->buyerA);

        $this->sync([$this->registration(1, $this->buyerA)], [$this->attendee(10, 1), $this->attendee(11, 1)]);

        $this->assertSame($first->getId(), $this->participationOf($this->buyerA)->getId());
        $this->assertCount(1, $this->participations());
        $this->assertSame(2, (int) $this->version->fresh()->total_attendees);
    }

    public function testRefundSoftDeletesAndARevertRestoresTheSameRow(): void
    {
        $this->sync([$this->registration(1, $this->buyerA)], [$this->attendee(10, 1)]);
        $live = $this->participationOf($this->buyerA);

        $this->sync([$this->registration(1, $this->buyerA, '2026-02-01 00:00:00')], [$this->attendee(10, 1)]);

        $this->assertTrue((bool) $live->fresh()->is_deleted);
        $this->assertSame(0, (int) $this->version->fresh()->total_attendees);

        $this->sync([$this->registration(1, $this->buyerA)], [$this->attendee(10, 1)]);

        $this->assertSame($live->getId(), $this->participationOf($this->buyerA)->getId());
        $this->assertFalse((bool) $live->fresh()->is_deleted);
        $this->assertSame(1, (int) $this->version->fresh()->total_attendees);
    }

    public function testSaleMovedToAnotherCustomerRetiresTheOldParticipationAndMovingBackRestoresIt(): void
    {
        $this->sync([$this->registration(1, $this->buyerA)], [$this->attendee(10, 1)]);
        $before = $this->participationOf($this->buyerA);

        $this->sync([$this->registration(1, $this->buyerB)], [$this->attendee(10, 1)]);

        $this->assertNotSame($before->getId(), $this->participationOf($this->buyerB)->getId());
        $this->assertTrue((bool) $before->fresh()->is_deleted);
        $this->assertSame(1, (int) $this->version->fresh()->total_attendees);

        $this->sync([$this->registration(1, $this->buyerA)], [$this->attendee(10, 1)]);

        $this->assertFalse((bool) $before->fresh()->is_deleted);
        $this->assertSame(1, (int) $this->version->fresh()->total_attendees);
    }

    /** A participation staff deleted in Kanvas is not the mirror's to bring back. */
    public function testAParticipationDeletedInKanvasStaysDeleted(): void
    {
        $this->sync([$this->registration(1, $this->buyerA)], [$this->attendee(10, 1)]);
        $participation = $this->participationOf($this->buyerA);
        $participation->forceFill(['is_deleted' => true])->saveQuietly();

        $this->sync([$this->registration(1, $this->buyerA)], [$this->attendee(10, 1), $this->attendee(11, 1)]);

        $this->assertTrue((bool) $participation->fresh()->is_deleted);
        $this->assertSame(2, $participation->fresh()->metadata['brushcrazy']['seat_count']);
        $this->assertSame(0, (int) $this->version->fresh()->total_attendees);
    }

    public function testRegistrationsWithoutABuyerShareOneUnknownGuest(): void
    {
        $this->sync([$this->registration(1, null)], [$this->attendee(10, 1)]);
        $guest = $this->participationOf(null);
        $this->sync([$this->registration(1, null)], [$this->attendee(10, 1)]);

        $people = People::query()
            ->where('companies_id', auth()->user()->getCurrentCompany()->getId())
            ->where('name', 'Unknown Guest')
            ->get()
            ->filter(fn (People $row) => $row->get(CustomFieldEnum::BRUSHCRAZY_UNKNOWN_GUEST->value) !== null);

        $this->assertSame($guest->participant_id, $this->participationOf(null)->participant_id);
        $this->assertCount(1, $people);
        $this->assertContains(RegistrationMapper::FLAG_NO_BUYER, $guest->metadata['brushcrazy']['flags']);
    }

    /** Buyers with no People here and no `users` row at the source fold into the guest participation. */
    public function testBuyersGoneAtTheSourceShareTheGuestParticipation(): void
    {
        $this->classes[$this->key()] = [
            'registrations' => [$this->registration(1, null), $this->registration(2, $this->buyerA), $this->registration(3, $this->buyerB)],
            'attendees' => [$this->attendee(10, 1), $this->attendee(11, 2), $this->attendee(12, 3)],
        ];

        $this->action()->syncCalendarables($this->client(), [$this->key()]);

        $participations = $this->participations();

        $this->assertCount(1, $participations);
        $this->assertSame(3, $participations->first()->metadata['brushcrazy']['seat_count']);
        $this->assertSame(3, (int) $this->version->fresh()->total_attendees);
    }

    /** The first group's seats changed before the second one threw; the version is still recounted. */
    public function testAFailingClassIsCountedAndItsVersionStillRecounted(): void
    {
        $this->sync([$this->registration(1, $this->buyerA)], [$this->attendee(10, 1)]);

        $results = $this->sync(
            [$this->registration(1, $this->buyerA), $this->registration(2, $this->buyerB)],
            [$this->attendee(10, 1), $this->attendee(11, 1), $this->attendee(12, 2)],
            $this->action(failingBuyerId: $this->buyerB),
        );

        $this->assertSame(1, $results['failed']);
        $this->assertSame(0, $results['synced']);
        $this->assertStringContainsString('Buyer ' . $this->buyerB . ' failed', $results['errors'][0]);
        $this->assertSame(2, (int) $this->version->fresh()->total_attendees);
    }

    /** A class another run holds fails alone; the rest of the batch is written. */
    public function testALockedClassFailsAloneWhileTheRestOfTheBatchSyncs(): void
    {
        $otherId = $this->calendarableId + 1;
        $other = $this->importCalendarable($otherId);
        $otherKey = CalendarableTypeEnum::LESSON->value . ':' . $otherId;
        $this->studios[$otherKey] = 3;
        $this->classes[$otherKey] = [
            'registrations' => [$this->registration(1, $this->buyerB, calendarableId: $otherId)],
            'attendees' => [$this->attendee(20, 1)],
        ];
        $this->classes[$this->key()] = [
            'registrations' => [$this->registration(2, $this->buyerA)],
            'attendees' => [$this->attendee(10, 2)],
        ];
        $this->users[$this->buyerA] = $this->user($this->buyerA);
        $this->users[$this->buyerB] = $this->user($this->buyerB);

        $lock = Cache::lock('brushcrazy-registrations:' . app(Apps::class)->getId() . ':' . $this->key(), 60);
        $this->assertTrue($lock->get());

        try {
            $results = $this->action()->syncCalendarables($this->client(), [$this->key(), $otherKey]);
        } finally {
            $lock->release();
        }

        $this->assertSame(1, $results['synced']);
        $this->assertSame(1, $results['failed']);
        $this->assertStringContainsString('locked', $results['errors'][0]);
        $this->assertCount(0, $this->participations());
        $this->assertSame(1, (int) $other->fresh()->total_attendees);
    }

    public function testClassesOfAnotherStudioOrGoneAtTheSourceAreNotWritten(): void
    {
        $this->studios[$this->key()] = 4;
        $goneKey = CalendarableTypeEnum::LESSON->value . ':' . ($this->calendarableId + 1);

        $results = $this->sync([$this->registration(1, $this->buyerA)], [$this->attendee(10, 1)]);
        $missing = $this->action()->syncCalendarables($this->client(), [$goneKey]);

        $this->assertSame(1, $results['misrouted']);
        $this->assertSame(1, $missing['missing']);
        $this->assertCount(0, $this->participations());
    }

    /** A set() that failed after the insert must not make the next sync seat the buyer again. */
    public function testAParticipationMissingItsKeyFieldIsFoundByItsMetadataAndHealed(): void
    {
        $this->sync([$this->registration(1, $this->buyerA)], [$this->attendee(10, 1)]);
        $first = $this->participationOf($this->buyerA);
        $first->del(CustomFieldEnum::BRUSHCRAZY_PARTICIPATION_KEY->value);

        $this->sync([$this->registration(1, $this->buyerA)], [$this->attendee(10, 1), $this->attendee(11, 1)]);

        $this->assertSame($first->getId(), $this->participationOf($this->buyerA)->getId());
        $this->assertCount(1, $this->participations());
        $this->assertSame(
            $this->key() . ':u' . $this->buyerA,
            EventVersionParticipant::find($first->getId())->get(CustomFieldEnum::BRUSHCRAZY_PARTICIPATION_KEY->value)
        );
    }

    /** Two concurrent first syncs used to leave two rows for one buyer; the later one is retired. */
    public function testDuplicateParticipationsAreRetired(): void
    {
        $this->sync([$this->registration(1, $this->buyerA)], [$this->attendee(10, 1)]);
        $first = $this->participationOf($this->buyerA);
        $duplicate = $first->replicate();
        $duplicate->saveQuietly();

        $this->sync([$this->registration(1, $this->buyerA)], [$this->attendee(10, 1)]);

        $this->assertFalse((bool) $first->fresh()->is_deleted);
        $this->assertTrue((bool) $duplicate->fresh()->is_deleted);
        $this->assertSame(1, (int) $this->version->fresh()->total_attendees);
    }

    public function testAParticipantDeletedInKanvasIsRestored(): void
    {
        $this->sync([$this->registration(1, $this->buyerA)], [$this->attendee(10, 1)]);
        $participant = Participant::withTrashed()->find($this->participationOf($this->buyerA)->participant_id);
        $participant->forceFill(['is_deleted' => true])->saveQuietly();

        $this->sync([$this->registration(1, $this->buyerA)], [$this->attendee(10, 1)]);

        $this->assertFalse((bool) Participant::withTrashed()->find($participant->getId())->is_deleted);
    }

    public function testMetadataWrittenInKanvasSurvivesAResync(): void
    {
        $this->sync([$this->registration(1, $this->buyerA)], [$this->attendee(10, 1)]);
        $row = $this->participationOf($this->buyerA);
        $row->metadata = $row->metadata + ['kanvas' => ['note' => 'VIP']];
        $row->saveQuietly();

        $this->sync([$this->registration(1, $this->buyerA)], [$this->attendee(10, 1), $this->attendee(11, 1)]);
        $metadata = $row->fresh()->metadata;

        $this->assertSame('VIP', $metadata['kanvas']['note']);
        $this->assertSame(2, $metadata['brushcrazy']['seat_count']);
    }

    /** The site's per-attendee participations are the sale; the mirror adds none and counts no seat twice. */
    public function testRegistrationsOfAKanvasOrderAreNotMirrored(): void
    {
        $order = Str::uuid()->toString();
        $site = [
            $this->siteParticipation($this->reservation('confirmed', '+10', $order)),
            $this->siteParticipation($this->reservation('confirmed', '+10', $order)),
        ];

        $this->sync(
            [$this->registration(1, $this->buyerA, kanvasOrderUuid: strtoupper($order)), $this->registration(2, $this->buyerB)],
            [$this->attendee(10, 1), $this->attendee(11, 1), $this->attendee(12, 2)],
        );

        $this->assertNull($this->participationOf($this->buyerA));
        $this->assertSame(1, $this->participationOf($this->buyerB)->metadata['brushcrazy']['seat_count']);
        // assertEquals: the JSON column hands keys back in its own order.
        $this->assertEquals($site[0]->metadata, $site[0]->fresh()->metadata);
        $this->assertSame(3, (int) $this->version->fresh()->total_attendees);
    }

    /** Mirrored before BrushCrazy carried the uuid: once it does, the mirror's copy goes. */
    public function testAMirrorParticipationOfAnAdoptedSaleIsRetired(): void
    {
        $order = Str::uuid()->toString();
        $this->siteParticipation($this->reservation('confirmed', '+10', $order));

        $this->sync([$this->registration(1, $this->buyerA)], [$this->attendee(10, 1)]);
        $mirrored = $this->participationOf($this->buyerA);
        $this->assertSame(2, (int) $this->version->fresh()->total_attendees);

        $this->sync([$this->registration(1, $this->buyerA, kanvasOrderUuid: $order)], [$this->attendee(10, 1)]);

        $this->assertTrue((bool) $mirrored->fresh()->is_deleted);
        $this->assertSame(1, (int) $this->version->fresh()->total_attendees);
    }

    public function testARefundInBrushCrazyTakesDownTheKanvasOrderAndARevertRestoresIt(): void
    {
        $order = Str::uuid()->toString();
        $refunded = $this->siteParticipation($this->reservation('confirmed', '+10', $order));
        $untouched = $this->siteParticipation($this->reservation('confirmed', '+10', Str::uuid()->toString()));

        $this->sync([$this->refundedKanvasRegistration(1, $order)], [$this->attendee(10, 1)]);

        $this->assertTrue((bool) $refunded->fresh()->is_deleted);
        $this->assertTrue((bool) $refunded->fresh()->get(CustomFieldEnum::BRUSHCRAZY_DELETED_BY_MIRROR->value));
        $this->assertFalse((bool) $untouched->fresh()->is_deleted);
        $this->assertSame(1, (int) $this->version->fresh()->total_attendees);

        $this->sync([$this->registration(1, $this->buyerA, kanvasOrderUuid: $order)], [$this->attendee(10, 1)]);

        $this->assertFalse((bool) $refunded->fresh()->is_deleted);
        $this->assertSame(2, (int) $this->version->fresh()->total_attendees);
    }

    /** One live registration of the order is enough to keep it. */
    public function testAPartialRefundLeavesTheKanvasOrderAlone(): void
    {
        $order = Str::uuid()->toString();
        $participation = $this->siteParticipation($this->reservation('confirmed', '+10', $order));

        $this->sync(
            [$this->refundedKanvasRegistration(1, $order), $this->registration(2, $this->buyerA, kanvasOrderUuid: $order)],
            [$this->attendee(10, 1), $this->attendee(11, 2)],
        );

        $this->assertFalse((bool) $participation->fresh()->is_deleted);
    }

    public function testASiteReleaseIsNotUndoneByTheMirror(): void
    {
        $order = Str::uuid()->toString();
        $released = $this->siteParticipation($this->reservation('confirmed', '+10', $order), deleted: true);

        $this->sync([$this->registration(1, $this->buyerA, kanvasOrderUuid: $order)], [$this->attendee(10, 1)]);

        $this->assertTrue((bool) $released->fresh()->is_deleted);
        $this->assertSame(0, (int) $this->version->fresh()->total_attendees);
    }

    public function testExpiredHoldsDoNotCountAndSiteRowsAreNeverRetired(): void
    {
        $site = [
            $this->siteParticipation($this->reservation('held', '+10')),
            $this->siteParticipation($this->reservation('held', '-1')),
            $this->siteParticipation($this->reservation('release_pending', '-1')),
            $this->siteParticipation($this->reservation('confirmed', '-30', Str::uuid()->toString())),
            $this->siteParticipation(['source' => 'brushcrazy-redesign', 'painter_index' => 0]),
        ];

        $this->sync(
            [$this->registration(1, $this->buyerA)],
            [$this->attendee(10, 1), $this->attendee(11, 1)],
        );

        foreach ($site as $participation) {
            $this->assertFalse((bool) $participation->fresh()->is_deleted);
        }

        // held live 1 + confirmed 1 + legacy 1 + mirror 2; expired held and release_pending 0.
        $this->assertSame(5, (int) $this->version->fresh()->total_attendees);
    }

    /**
     * @param  array<int, stdClass>  $registrations
     * @param  array<int, stdClass>  $attendees
     * @return array<string, mixed>
     */
    private function sync(
        array $registrations,
        array $attendees = [],
        ?SyncRegistrationFromBrushCrazyAction $action = null
    ): array {
        $this->classes[$this->key()] = ['registrations' => $registrations, 'attendees' => $attendees];

        foreach ($registrations as $registration) {
            if ($registration->buyer_id !== null) {
                $this->users[$registration->buyer_id] ??= $this->user($registration->buyer_id);
            }
        }

        return ($action ?? $this->action())->syncCalendarables($this->client(), [$this->key()]);
    }

    private function key(): string
    {
        return CalendarableTypeEnum::LESSON->value . ':' . $this->calendarableId;
    }

    /**
     * @return Collection<int, EventVersionParticipant>
     */
    private function participations(): Collection
    {
        return EventVersionParticipant::query()->where('event_version_id', $this->version->getId())->get();
    }

    private function participationOf(?int $buyerId): ?EventVersionParticipant
    {
        return EventVersionParticipant::withTrashed()
            ->where('event_version_id', $this->version->getId())
            ->orderBy('id')
            ->get()
            ->first(
                fn (EventVersionParticipant $row) => is_array($row->metadata)
                    && isset($row->metadata['brushcrazy'])
                    && $row->metadata['brushcrazy']['buyer_id'] === $buyerId
            );
    }

    /**
     * Only the BrushCrazy reads are faked; everything they feed is the action's own code.
     */
    private function action(?int $failingBuyerId = null): SyncRegistrationFromBrushCrazyAction
    {
        return new class (
            app(Apps::class),
            auth()->user(),
            $this->context(),
            $this->customers($failingBuyerId),
            fn (): array => ['classes' => $this->classes, 'users' => $this->users, 'studios' => $this->studios],
        ) extends SyncRegistrationFromBrushCrazyAction {
            protected int $lockWaitSeconds = 0;

            public function __construct(
                AppInterface $app,
                UserInterface $user,
                StudioContext $context,
                SyncCustomerFromBrushCrazyAction $customers,
                private Closure $fakeSource,
            ) {
                parent::__construct($app, $user, $context, customers: $customers);
            }

            protected function readStudios(Client $client, array $calendarableKeys): array
            {
                return array_intersect_key(($this->fakeSource)()['studios'], array_flip($calendarableKeys));
            }

            protected function readBuyerIds(Client $client, array $calendarableKeys): array
            {
                $registrations = [];

                foreach ($calendarableKeys as $key) {
                    array_push($registrations, ...(($this->fakeSource)()['classes'][$key]['registrations'] ?? []));
                }

                return $this->buyerIdsOf($registrations);
            }

            protected function readSource(Client $client, string $calendarableKey): array
            {
                return (($this->fakeSource)()['classes'][$calendarableKey] ?? []) + ['registrations' => [], 'attendees' => [], 'sale_items' => []];
            }

            protected function readUsers(Client $client, array $bcUserIds): array
            {
                return array_intersect_key(($this->fakeSource)()['users'], array_flip($bcUserIds));
            }
        };
    }

    private function client(): Client
    {
        return new class () extends Client {
            public function __construct()
            {
            }
        };
    }

    /**
     * Studio credits are read from the BrushCrazy database, which tests have no connection to.
     */
    private function customers(?int $failingBuyerId): SyncCustomerFromBrushCrazyAction
    {
        $noCredits = new class () extends StudioCredits {
            public function __construct()
            {
            }

            public function forUsers(int $bcStudioId, array $bcUserIds): array
            {
                return [];
            }
        };

        $customers = new class (
            app(Apps::class),
            auth()->user(),
            $this->context(),
            credits: $noCredits
        ) extends SyncCustomerFromBrushCrazyAction {
            public ?int $failingBuyerId = null;

            public function fromRow(stdClass $row): People
            {
                if ((int) $row->id === $this->failingBuyerId) {
                    throw new RuntimeException('Buyer ' . $row->id . ' failed');
                }

                return parent::fromRow($row);
            }
        };

        $customers->failingBuyerId = $failingBuyerId;

        return $customers;
    }

    private function context(): StudioContext
    {
        $company = auth()->user()->getCurrentCompany();

        return new StudioContext(
            bcStudioId: 3,
            company: $company,
            branch: $company->defaultBranch,
            themeArea: ThemeArea::query()
                ->where('apps_id', app(Apps::class)->getId())
                ->where('companies_id', $company->getId())
                ->where('name', SeedBrushCrazyLookupDataAction::UNASSIGNED)
                ->firstOrFail(),
            timezone: 'America/Denver',
        );
    }

    private function importCalendarable(int $calendarableId): EventVersion
    {
        return new SyncCalendarableFromBrushCrazyAction(
            app(Apps::class),
            auth()->user(),
            $this->context(),
            Carbon::now('UTC'),
        )->fromRow(CalendarableTypeEnum::LESSON, $this->calendarable($calendarableId));
    }

    /**
     * A participation as the site writes it (`addPeopleToEventVersion`): no mirror key.
     *
     * @param  array<string, mixed>  $metadata
     */
    private function siteParticipation(array $metadata, bool $deleted = false): EventVersionParticipant
    {
        $participation = new EventVersionParticipant();
        $participation->forceFill([
            'event_version_id' => $this->version->getId(),
            'participant_id' => $this->siteParticipantId(),
            'participant_type_id' => new IdMapRepository()->lookup(
                $this->context()->companyId(),
                ParticipantType::class,
                'participant-type:attendee'
            ),
            'ticket_price' => 35,
            'discount' => 0,
            'metadata' => $metadata,
            'is_deleted' => $deleted,
        ]);
        $participation->saveQuietly();

        return $participation;
    }

    private function siteParticipantId(): int
    {
        if ($this->siteParticipantId !== null) {
            return $this->siteParticipantId;
        }

        $people = new ResolveBrushCrazyUnknownGuestAction(app(Apps::class), auth()->user(), $this->context())->execute();
        $participant = new Participant();
        $participant->forceFill([
            'uuid' => Str::uuid()->toString(),
            'apps_id' => app(Apps::class)->getId(),
            'companies_id' => $this->context()->companyId(),
            'users_id' => auth()->user()->getId(),
            'people_id' => $people->getId(),
            'theme_area_id' => $this->context()->themeAreaId(),
            'participant_status_id' => 1,
            'slug' => 'site-attendee-' . Str::uuid()->toString(),
        ]);
        $participant->saveQuietly();

        return $this->siteParticipantId = (int) $participant->getId();
    }

    /**
     * The site's `brushcrazy.event-reservation.v1` metadata (brushcrazy-web
     * src/features/reservations/reservation-metadata.ts).
     *
     * @return array<string, mixed>
     */
    private function reservation(string $state, string $holdMinutesFromNow, ?string $orderUuid = null): array
    {
        $metadata = [
            'schema' => 'brushcrazy.event-reservation.v1',
            'state' => $state,
            'reservation_key' => 'test-' . $this->calendarableId,
            'attendee_index' => 0,
            'variant_id' => '1',
            'organizer_user_id' => '1',
            'organizer_user_uuid' => Str::uuid()->toString(),
            'hold_expires_at' => Carbon::now('UTC')->addMinutes((int) $holdMinutesFromNow)->format('Y-m-d\TH:i:s.v\Z'),
        ];

        if ($state === 'confirmed') {
            $metadata['confirmed_at'] = Carbon::now('UTC')->format('Y-m-d\TH:i:s.v\Z');
        }

        if ($orderUuid !== null) {
            $metadata['order_id'] = '1';
            $metadata['order_uuid'] = $orderUuid;
        }

        return $metadata;
    }

    private function registration(
        int $id,
        ?int $buyerId,
        ?string $deletedAt = null,
        ?string $kanvasOrderUuid = null,
        ?int $calendarableId = null
    ): stdClass {
        return (object) [
            'id' => $id,
            'calendarable_type' => CalendarableTypeEnum::LESSON->value,
            'calendarable_id' => $calendarableId ?? $this->calendarableId,
            'sale_id' => $buyerId !== null ? $id + 1000 : null,
            'sale_found' => $buyerId !== null ? $id + 1000 : null,
            'buyer_id' => $buyerId,
            'sale_created_at' => '2026-01-01 18:30:00',
            'kanvas_order_uuid' => $kanvasOrderUuid,
            'notes' => null,
            'created_at' => '2025-12-01 10:00:00',
            'deleted_at' => $deletedAt,
        ];
    }

    private function refundedKanvasRegistration(int $id, string $orderUuid): stdClass
    {
        return $this->registration(
            $id,
            $this->buyerA,
            '2026-02-01 00:00:00',
            $orderUuid
        );
    }

    private function attendee(int $id, int $registrationId): stdClass
    {
        return (object) [
            'id' => $id,
            'registration_id' => $registrationId,
            'name' => 'Seat ' . $id,
            'checked_in_at' => null,
            'no_show' => 0,
            'deleted_at' => null,
        ];
    }

    private function user(int $id): stdClass
    {
        return (object) [
            'id' => $id,
            'name' => 'Buyer ' . $id,
            'display_name' => null,
            'email' => 'buyer' . $id . '@example.com',
            'phone_number' => null,
            'birthday' => null,
            'street_number' => null,
            'route' => null,
            'suite' => null,
            'locality' => null,
            'administrative_area_level_1' => null,
            'postal_code' => null,
            'points' => 0,
            'rewards' => '{}',
            'settings' => '{}',
            'favorite_studio' => 3,
            'last_studio' => 3,
            'created_at' => '2020-05-01 10:00:00',
            'deleted_at' => null,
        ];
    }

    private function calendarable(int $id): stdClass
    {
        return (object) [
            'id' => $id,
            'type' => 'public-class',
            'name' => 'Sunset Canvas',
            'description' => null,
            'start' => '2026-09-15 01:00:00',
            'end' => '2026-09-15 04:00:00',
            'occupancy' => 24,
            'fundraiser' => 0,
            'rsvp' => false,
            'private' => false,
            'password' => null,
            'painting_id' => null,
            'studio_id' => 3,
            'published_at' => '2026-01-01 00:00:00',
            'deleted_at' => null,
        ];
    }
}
