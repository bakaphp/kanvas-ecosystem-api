<?php

declare(strict_types=1);

namespace Kanvas\Connectors\BrushCrazy\Actions;

use Baka\Contracts\AppInterface;
use Baka\Support\Str;
use Baka\Users\Contracts\UserInterface;
use Closure;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Kanvas\Connectors\BrushCrazy\Client;
use Kanvas\Connectors\BrushCrazy\Concerns\UpsertsMirroredRows;
use Kanvas\Connectors\BrushCrazy\Enums\CalendarableTypeEnum;
use Kanvas\Connectors\BrushCrazy\Enums\CustomFieldEnum;
use Kanvas\Connectors\BrushCrazy\Mappers\RegistrationMapper;
use Kanvas\Connectors\BrushCrazy\Support\IdMapRepository;
use Kanvas\Connectors\BrushCrazy\Support\SaleSource;
use Kanvas\Connectors\BrushCrazy\Support\StudioContext;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Event\Events\Models\EventVersionParticipant;
use Kanvas\Event\Participants\Models\Participant;
use Kanvas\Event\Participants\Models\ParticipantType;
use Kanvas\Guild\Customers\Models\People;
use RuntimeException;
use stdClass;
use Throwable;

/**
 * Writes BrushCrazy registrations into Kanvas as one EventVersionParticipant per buyer per
 * calendarable, seats in its metadata. The bulk import and the mirror webhook both go through
 * syncCalendarables(), so the two cannot drift.
 *
 * The unit of sync is the whole calendarable, not a registration: a buyer's participation merges
 * all of their registrations, a refund touches several at once, and a sale moved to another
 * customer (SaleCustomerChangeService) has to leave the old buyer's participation as well as join
 * the new one. Re-reading every registration of the calendarable converges all three.
 *
 * A sale the site made in Kanvas (`sales.kanvas_order_uuid`) is not mirrored: the site's own
 * participations, one per attendee and tied to the order by `metadata.order_uuid`, already are its
 * seats. The mirror only takes them down when BrushCrazy's admin refunds every registration of it.
 *
 * Model events stay off for every write. EventVersionParticipantObserver bumps total_attendees by
 * one per row (re-saving and re-indexing the version) and fires the participant-added/removed
 * workflow, which can notify the customer. total_attendees is recounted instead — as live seats,
 * which is what BrushCrazy measures capacity in.
 */
class SyncRegistrationFromBrushCrazyAction
{
    use UpsertsMirroredRows;

    /** BrushCrazy morph aliases whose pointers resolve to calendarables via calendarablesFor(). */
    public const array POINTER_MORPHS = ['registration', 'registration-attendee', 'sale'];

    /** One class's read and writes take seconds; a crashed worker frees the class after this. */
    private const int LOCK_SECONDS = 120;

    /**
     * A site participation counts one seat while its hold is live: `hold_expires_at` is the
     * site's `Date.toISOString()`, so a string compare against the same format orders correctly.
     */
    private const string SEATS_SQL = <<<'SQL'
        CASE
            WHEN JSON_EXTRACT(metadata, '$.brushcrazy.seat_count') IS NOT NULL
                THEN CAST(JSON_EXTRACT(metadata, '$.brushcrazy.seat_count') AS SIGNED)
            WHEN JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.state')) IN ('held', 'release_pending')
                AND JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.hold_expires_at')) <= ?
                THEN 0
            ELSE 1
        END
        SQL;

    /** A class another run holds longer than this is counted failed; the next pull heals it. */
    protected int $lockWaitSeconds = 15;

    protected IdMapRepository $idMaps;

    protected SyncCustomerFromBrushCrazyAction $customers;

    /** @var array<int, true> */
    protected array $touchedVersionIds = [];

    /** @var array<int, People> */
    protected array $createdPeople = [];

    protected ?int $participantTypeId = null;

    protected ?Participant $unknownGuest = null;

    /** @var array<int, Participant> */
    protected array $participants = [];

    /** @var array<int, stdClass> source `users` rows for buyers with no People here */
    protected array $buyerRows = [];

    /** @var array<int, true> buyers with no People here and no `users` row at the source */
    protected array $goneBuyerIds = [];

    /** @var array<int, true> */
    protected array $resolvedBuyerIds = [];

    /** @var array<int, SaleSource> keyed by the Client's object id */
    protected array $saleSources = [];

    public function __construct(
        protected AppInterface $app,
        protected UserInterface $user,
        protected StudioContext $context,
        ?IdMapRepository $idMaps = null,
        ?SyncCustomerFromBrushCrazyAction $customers = null,
    ) {
        $this->idMaps = $idMaps ?? new IdMapRepository();
        $this->customers = $customers ?? new SyncCustomerFromBrushCrazyAction(
            $app,
            $user,
            $context,
            $this->idMaps
        );
    }

    /**
     * Calendarable keys ("{morph}:{id}") a mirror pointer touches. An attendee whose registration
     * was nulled out (registration_id is SET NULL at the source) touches nothing: it has no event
     * to belong to.
     *
     * @return array<int, string>
     */
    public static function calendarablesFor(Client $client, string $entityType, int $entityId): array
    {
        $registrations = $client->table('registrations')->select('calendarable_type', 'calendarable_id');

        $rows = match ($entityType) {
            'registration' => $registrations->where('id', $entityId)->get(),
            'registration-attendee' => $registrations->whereIn(
                'id',
                $client->table('registration_attendees')
                    ->where('id', $entityId)
                    ->whereNotNull('registration_id')
                    ->select('registration_id')
            )->get(),
            'sale' => $registrations->where('sale_id', $entityId)->get()->merge(
                $client->table('sale_items')
                    ->where('sale_id', $entityId)
                    ->whereIn('option_type', array_column(CalendarableTypeEnum::cases(), 'value'))
                    ->selectRaw('option_type as calendarable_type, option_id as calendarable_id')
                    ->get()
            ),
            default => collect(),
        };

        $keys = [];

        foreach ($rows as $row) {
            if (CalendarableTypeEnum::tryFrom((string) $row->calendarable_type) !== null && $row->calendarable_id !== null) {
                $keys[$row->calendarable_type . ':' . $row->calendarable_id] = true;
            }
        }

        return array_keys($keys);
    }

    /**
     * Sync a batch of calendarables. A calendarable whose source row is gone is `missing`; one from
     * another studio is `misrouted` (its own studio's receiver gets the pointer).
     *
     * Each class is locked on its own, from its source read to its last write: a run that read an
     * older snapshot can no longer write it over a newer one another run (the webhook vs the pull)
     * has already written, and a webhook never waits on a whole pull chunk. Studios and buyers are
     * read for the batch up front, which only decides routing and what to preload.
     *
     * @param  array<int, string>  $calendarableKeys
     * @return array{synced: int, participants: int, failed: int, missing: int, misrouted: int, errors: array<int, string>, warnings: array<int, string>}
     */
    public function syncCalendarables(Client $client, array $calendarableKeys): array
    {
        $results = [
            'synced' => 0,
            'participants' => 0,
            'failed' => 0,
            'missing' => 0,
            'misrouted' => 0,
            'errors' => [],
            'warnings' => [],
        ];

        $calendarableKeys = array_values(array_unique($calendarableKeys));
        $studios = $this->readStudios($client, $calendarableKeys);
        $this->preload($client, $calendarableKeys);

        foreach ($calendarableKeys as $key) {
            if (! isset($studios[$key])) {
                $results['missing']++;

                continue;
            }

            if ($studios[$key] !== $this->context->bcStudioId) {
                $results['misrouted']++;

                continue;
            }

            try {
                $outcome = $this->lockFor($key)->block(
                    $this->lockWaitSeconds,
                    fn (): array => $this->syncCalendarable($client, $key)
                );

                $results['synced']++;
                $results['participants'] += $outcome['participants'];
                array_push($results['warnings'], ...$outcome['warnings']);
            } catch (LockTimeoutException) {
                $results['failed']++;
                $results['errors'][] = $key . ' — locked by another sync; the next pull retries it';
            } catch (Throwable $e) {
                $results['failed']++;
                $results['errors'][] = $key . ' — ' . $e->getMessage();
            }
        }

        $this->recountAttendees();

        // Customers created here were written with model events off, so Scout never saw them.
        if ($this->createdPeople !== []) {
            new EloquentCollection($this->createdPeople)->searchable();
            $this->createdPeople = [];
        }

        return $results;
    }

    /**
     * total_attendees = live seats across the version's live participations. A mirrored one
     * counts its BrushCrazy seats; any other (the site's, one per attendee) counts one, except a
     * site hold whose `hold_expires_at` has passed.
     */
    public function recountAttendees(): void
    {
        $versionIds = array_keys($this->touchedVersionIds);
        $this->touchedVersionIds = [];

        if ($versionIds === []) {
            return;
        }

        $seats = [];
        $rows = EventVersionParticipant::query()
            ->whereIn('event_version_id', $versionIds)
            ->groupBy('event_version_id')
            ->selectRaw(
                'event_version_id, SUM(' . self::SEATS_SQL . ') as seats',
                [Carbon::now('UTC')->format('Y-m-d\TH:i:s.v\Z')]
            )
            ->toBase()
            ->get();

        foreach ($rows as $row) {
            $seats[(int) $row->event_version_id] = (int) $row->seats;
        }

        EventVersion::withTrashed()
            ->whereIn('id', $versionIds)
            ->each(function (EventVersion $version) use ($seats) {
                $version->total_attendees = $seats[(int) $version->getId()] ?? 0;

                if ($version->isDirty('total_attendees')) {
                    $version->saveQuietly();
                }
            });
    }

    /**
     * Caller holds the calendarable's lock, so the rows written are the ones read here.
     *
     * @return array{participants: int, warnings: array<int, string>}
     */
    protected function syncCalendarable(Client $client, string $calendarableKey): array
    {
        $source = $this->readSource($client, $calendarableKey);
        $this->resolveBuyers($client, $this->buyerIdsOf($source['registrations']));

        [$registrations, $kanvasOrders] = RegistrationMapper::splitKanvasOrders($source['registrations']);

        $groups = RegistrationMapper::groups(
            $calendarableKey,
            $registrations,
            $source['attendees'],
            $source['sale_items'],
            array_keys($this->goneBuyerIds),
            $this->context->timezone
        );

        $versionId = $this->mirroredId(EventVersion::class, CustomFieldEnum::BRUSHCRAZY_CALENDARABLE_KEY, $calendarableKey)
            ?? throw new RuntimeException("Calendarable {$calendarableKey} has not been imported; run the calendarable import first.");

        $written = $this->writeGroups($versionId, $groups, $kanvasOrders);
        $warnings = [];

        foreach ($groups as $group) {
            if (in_array(RegistrationMapper::FLAG_POSSIBLE_PHANTOM_SEATS, $group['participation']['metadata']['brushcrazy']['flags'], true)) {
                $warnings[] = $group['key'] . ' — ' . $group['seat_count'] . ' seats with a deposit line on the sale; possible phantom seats';
            }
        }

        return ['participants' => count($written), 'warnings' => $warnings];
    }

    protected function lockFor(string $calendarableKey): Lock
    {
        return Cache::lock('brushcrazy-registrations:' . $this->app->getId() . ':' . $calendarableKey, self::LOCK_SECONDS);
    }

    /**
     * @param  array<int, string>  $calendarableKeys
     * @return array<string, int> calendarable key => BrushCrazy studio id, trashed classes included
     */
    protected function readStudios(Client $client, array $calendarableKeys): array
    {
        $studios = [];

        foreach (CalendarableTypeEnum::idsByMorph($calendarableKeys) as $morph => $ids) {
            $rows = $client->table(CalendarableTypeEnum::from($morph)->table())
                ->whereIn('id', $ids)
                ->pluck('studio_id', 'id');

            foreach ($rows as $id => $studioId) {
                $studios[$morph . ':' . $id] = (int) $studioId;
            }
        }

        return $studios;
    }

    /**
     * @param  array<int, string>  $calendarableKeys
     * @return array<int, int>
     */
    protected function readBuyerIds(Client $client, array $calendarableKeys): array
    {
        $byMorph = CalendarableTypeEnum::idsByMorph($calendarableKeys);

        if ($byMorph === []) {
            return [];
        }

        return $client->table('registrations')
            ->join('sales', 'sales.id', '=', 'registrations.sale_id')
            ->where(self::matchesCalendarable($byMorph, 'registrations.calendarable_type', 'registrations.calendarable_id'))
            ->where('sales.customer_id', '>', 0)
            ->distinct()
            ->pluck('sales.customer_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * Everything the mapper needs for one calendarable, trashed rows included — a refund is a soft
     * delete at the source and has to reach Kanvas as one.
     *
     * @return array{registrations: array<int, stdClass>, attendees: array<int, stdClass>, sale_items: array<int, stdClass>}
     */
    protected function readSource(Client $client, string $calendarableKey): array
    {
        $byMorph = CalendarableTypeEnum::idsByMorph([$calendarableKey]);
        $source = ['registrations' => [], 'attendees' => [], 'sale_items' => []];

        if ($byMorph === []) {
            return $source;
        }

        $columns = [
            'registrations.*',
            'sales.id as sale_found',
            'sales.customer_id as buyer_id',
            'sales.created_at as sale_created_at',
        ];

        if ($this->saleSource($client)->hasKanvasOrderUuid()) {
            $columns[] = 'sales.kanvas_order_uuid';
        }

        $source['registrations'] = $client->table('registrations')
            ->leftJoin('sales', 'sales.id', '=', 'registrations.sale_id')
            ->where(self::matchesCalendarable($byMorph, 'registrations.calendarable_type', 'registrations.calendarable_id'))
            ->orderBy('registrations.id')
            ->select($columns)
            ->get()
            ->all();

        $registrationIds = array_map(fn (stdClass $row): int => (int) $row->id, $source['registrations']);
        $saleIds = [];

        foreach ($source['registrations'] as $registration) {
            if ($registration->sale_found !== null) {
                $saleIds[(int) $registration->sale_id] = true;
            }
        }

        if ($registrationIds !== []) {
            $source['attendees'] = $client->table('registration_attendees')
                ->whereIn('registration_id', $registrationIds)
                ->orderBy('id')
                ->get()
                ->all();
        }

        if ($saleIds !== []) {
            $source['sale_items'] = $client->table('sale_items')
                ->whereIn('sale_id', array_keys($saleIds))
                ->whereNull('deleted_at')
                ->where(self::matchesCalendarable($byMorph, 'option_type', 'option_id'))
                ->orderBy('id')
                ->get()
                ->all();
        }

        return $source;
    }

    /**
     * @param  array<int, int>  $bcUserIds
     * @return array<int, stdClass> keyed by id
     */
    protected function readUsers(Client $client, array $bcUserIds): array
    {
        return $this->saleSource($client)->users($bcUserIds)->keyBy('id')->all();
    }

    /**
     * One per Client, so `sales.kanvas_order_uuid` (a BrushCrazy migration that may not have run
     * yet) is checked once per connection.
     */
    protected function saleSource(Client $client): SaleSource
    {
        return $this->saleSources[spl_object_id($client)] ??= new SaleSource($client);
    }

    /**
     * @param  array<string, array<string, mixed>>  $groups
     * @param  array<string, bool>  $kanvasOrders  order uuid => whether it has a live registration here
     * @return array<int, EventVersionParticipant>
     */
    protected function writeGroups(int $versionId, array $groups, array $kanvasOrders): array
    {
        // Marked before any write: a group that throws half way has still changed this version.
        $this->touchedVersionIds[$versionId] = true;

        [$participations, $duplicates, $siteParticipations] = $this->participationsFor($versionId);
        $this->retire($duplicates);

        $written = [];

        foreach ($groups as $key => $group) {
            $participant = $group['buyer_id'] !== null
                ? $this->buyerParticipant($group['buyer_id'])
                : null;

            if ($participant === null) {
                if ($group['buyer_id'] !== null) {
                    $group['participation']['metadata']['brushcrazy']['flags'][] = RegistrationMapper::FLAG_BUYER_MISSING;
                }

                $participant = $this->unknownGuest();
            }

            $written[] = $participations[$key] = $this->upsertParticipation(
                $versionId,
                $participant,
                $group,
                $participations[$key] ?? null
            );
        }

        // Only rows carrying a mirror participation key are here; the site's never are.
        $this->retire(array_diff_key($participations, $groups));

        foreach ($kanvasOrders as $orderUuid => $live) {
            foreach ($siteParticipations[$orderUuid] ?? [] as $participation) {
                $this->applyMirroredDeletion($participation, ! $live);
            }
        }

        return $written;
    }

    /**
     * Studios' People and Participant ids for the batch's buyers in one query per map, and the
     * Participant rows themselves, instead of several queries per participation.
     *
     * @param  array<int, string>  $calendarableKeys
     */
    protected function preload(Client $client, array $calendarableKeys): void
    {
        $buyerIds = $this->readBuyerIds($client, $calendarableKeys);

        $this->buyerRows = [];
        $this->goneBuyerIds = [];
        $this->resolvedBuyerIds = [];

        $this->preloadMirroredIds(EventVersion::class, CustomFieldEnum::BRUSHCRAZY_CALENDARABLE_KEY, $calendarableKeys);
        $this->preloadMirroredIds(People::class, CustomFieldEnum::BRUSHCRAZY_USER_ID, $buyerIds);
        $this->preloadMirroredIds(Participant::class, CustomFieldEnum::BRUSHCRAZY_USER_ID, $buyerIds);

        $participantIds = array_values($this->mirroredIdMaps[Participant::class . '|' . CustomFieldEnum::BRUSHCRAZY_USER_ID->value]);
        $this->participants = $participantIds === []
            ? []
            : $this->tenantParticipants()->whereIn('id', $participantIds)->get()->keyBy('id')->all();

        $missing = $this->resolveBuyers($client, $buyerIds);

        if ($missing !== []) {
            $this->customers->preload($missing);
        }
    }

    /**
     * Loads the source `users` row of every buyer with no People here yet, once per buyer. A buyer
     * who appeared between the batch read and the class's own read is resolved here too.
     *
     * @param  array<int, int>  $buyerIds
     * @return array<int, int> the buyers that had no People here
     */
    protected function resolveBuyers(Client $client, array $buyerIds): array
    {
        $people = $this->mirroredIdMaps[People::class . '|' . CustomFieldEnum::BRUSHCRAZY_USER_ID->value] ?? [];
        $missing = [];

        foreach ($buyerIds as $buyerId) {
            if (isset($this->resolvedBuyerIds[$buyerId])) {
                continue;
            }

            $this->resolvedBuyerIds[$buyerId] = true;

            if (! isset($people[(string) $buyerId])) {
                $missing[] = $buyerId;
            }
        }

        if ($missing === []) {
            return [];
        }

        $users = $this->readUsers($client, $missing);

        foreach ($missing as $buyerId) {
            if (isset($users[$buyerId])) {
                $this->buyerRows[$buyerId] = $users[$buyerId];
            } else {
                $this->goneBuyerIds[$buyerId] = true;
            }
        }

        return $missing;
    }

    /**
     * @param  iterable<object>  $registrations
     * @return array<int, int>
     */
    protected function buyerIdsOf(iterable $registrations): array
    {
        $buyerIds = [];

        foreach ($registrations as $registration) {
            $buyerId = RegistrationMapper::buyerId($registration);

            if ($buyerId !== null) {
                $buyerIds[$buyerId] = true;
            }
        }

        return array_keys($buyerIds);
    }

    /**
     * Keyed by the participation-key custom field, falling back to the key the mapper writes into
     * metadata on the same insert: a set() that failed after the insert would otherwise read as
     * "new" and seat the buyer a second time. The fallback heals the custom field.
     *
     * Rows sharing a key (written before the lock existed) come back as duplicates; the lowest id
     * is the one kept. Rows with no key are the site's; those tied to an order come back by its uuid.
     *
     * EVP custom fields are written with companies_id = 0 and apps_custom_fields has no apps_id, so
     * the participation key is only ever read back for this version's own EVP ids — a global value
     * lookup could resolve another app's row that mirrors the same BrushCrazy database.
     *
     * @return array{
     *     0: array<string, EventVersionParticipant>,
     *     1: array<int, EventVersionParticipant>,
     *     2: array<string, array<int, EventVersionParticipant>>
     * }
     */
    protected function participationsFor(int $versionId): array
    {
        $rows = EventVersionParticipant::withTrashed()
            ->where('event_version_id', $versionId)
            ->orderBy('id')
            ->get()
            ->keyBy('id');

        $keys = $this->idMaps->loadReverse(
            0,
            EventVersionParticipant::class,
            CustomFieldEnum::BRUSHCRAZY_PARTICIPATION_KEY,
            $rows->keys()->all(),
        );

        $byKey = [];
        $duplicates = [];
        $byOrder = [];

        foreach ($rows as $id => $row) {
            $metadata = is_array($row->metadata) ? $row->metadata : [];
            $key = $keys[$id] ?? $metadata['brushcrazy']['participation_key'] ?? null;

            if ($key === null) {
                if (is_string($metadata['order_uuid'] ?? null) && $metadata['order_uuid'] !== '') {
                    $byOrder[strtolower($metadata['order_uuid'])][] = $row;
                }

                continue;
            }

            if (isset($byKey[$key])) {
                $duplicates[] = $row;

                continue;
            }

            $byKey[$key] = $row;

            if (! isset($keys[$id])) {
                $row->disableWorkflows();
                $row->set(CustomFieldEnum::BRUSHCRAZY_PARTICIPATION_KEY->value, $key);
            }
        }

        return [$byKey, $duplicates, $byOrder];
    }

    /**
     * @param  array<string, mixed>  $group
     */
    protected function upsertParticipation(
        int $versionId,
        Participant $participant,
        array $group,
        ?EventVersionParticipant $existing
    ): EventVersionParticipant {
        $attributes = [
            'event_version_id' => $versionId,
            'participant_id' => $participant->getId(),
            'participant_type_id' => $this->participantTypeId(),
        ] + $group['participation'];
        $sourceDeleted = (bool) $attributes['is_deleted'];
        unset($attributes['is_deleted']);

        return EventVersionParticipant::withoutEvents(function () use ($existing, $attributes, $group, $sourceDeleted) {
            if ($existing !== null) {
                // Only the `brushcrazy` block is the mirror's; keys Kanvas wrote beside it stay.
                $attributes['metadata'] = array_replace(
                    is_array($existing->metadata) ? $existing->metadata : [],
                    $attributes['metadata']
                );
                $existing->fill($attributes);
                $this->applyMirroredDeletion($existing, $sourceDeleted);

                return $existing;
            }

            $participation = new EventVersionParticipant();
            $participation->fill($attributes);
            $participation->is_deleted = $sourceDeleted;

            if ($group['created_at'] !== null) {
                $participation->created_at = $group['created_at'];
            }

            $participation->saveQuietly();
            $participation->disableWorkflows();
            $participation->set(CustomFieldEnum::BRUSHCRAZY_PARTICIPATION_KEY->value, $group['key']);
            $this->recordMirrorDeletion($participation);

            return $participation;
        });
    }

    /**
     * @param  array<array-key, EventVersionParticipant>  $participations
     */
    protected function retire(array $participations): void
    {
        foreach ($participations as $participation) {
            $this->applyMirroredDeletion($participation, true);
        }
    }

    /**
     * The no-resurrection rule of upsertMirrored(), for a model with no apps_id/companies_id: a
     * participation deleted in Kanvas (staff, or the site releasing a hold) stays deleted; one the
     * mirror deleted comes back when the source does.
     */
    protected function applyMirroredDeletion(EventVersionParticipant $participation, bool $sourceDeleted): void
    {
        $deleted = $this->mirroredDeletedState($participation, $sourceDeleted);

        // Compared as bool: assigning `false` over a stored "0" would dirty the row on every run.
        if ($deleted !== (bool) $participation->is_deleted) {
            $participation->is_deleted = $deleted;
        }

        if ($participation->isDirty()) {
            $participation->saveQuietly();
        }

        $participation->disableWorkflows();
        $this->recordMirrorDeletion($participation);
    }

    /**
     * Null when the buyer has no People here and no row at the source either (a hard-deleted
     * user): the caller seats them as the unknown guest.
     */
    protected function buyerParticipant(int $bcUserId): ?Participant
    {
        $peopleId = $this->mirroredId(People::class, CustomFieldEnum::BRUSHCRAZY_USER_ID, (string) $bcUserId);
        $people = null;

        if ($peopleId === null) {
            $userRow = $this->buyerRows[$bcUserId] ?? null;

            if ($userRow === null) {
                return null;
            }

            $people = $this->customers->fromRow($userRow);
            $peopleId = (int) $people->getId();
            $this->rememberMirroredId(
                People::class,
                CustomFieldEnum::BRUSHCRAZY_USER_ID,
                (string) $bcUserId,
                $peopleId
            );
            $this->createdPeople[] = $people;
        }

        return $this->participantFor(
            $peopleId,
            CustomFieldEnum::BRUSHCRAZY_USER_ID,
            (string) $bcUserId,
            $people?->name
        );
    }

    /**
     * Resolved by external id first, then by people_id — Kanvas keeps one Participant per People
     * per company (SyncPeopleWithParticipantAction), and the People here was itself resolved by a
     * BrushCrazy id, so adopting its participant cannot reach a Kanvas-native person.
     *
     * Events are off, so the uuid and the slug ParticipantObserver would derive from the People
     * are set here, slug with the observer's formula.
     */
    protected function participantFor(
        int $peopleId,
        CustomFieldEnum $field,
        string $externalId,
        ?string $peopleName = null
    ): Participant {
        $participantId = $this->mirroredId(Participant::class, $field, $externalId);

        return Participant::withoutEvents(function () use ($participantId, $peopleId, $field, $externalId, $peopleName) {
            $participant = $participantId !== null
                ? ($this->participants[$participantId] ?? $this->tenantParticipants()
                    ->where('id', $participantId)
                    ->first())
                : null;

            if ($participant === null) {
                $participant = $this->tenantParticipants()->where('people_id', $peopleId)->first()
                    ?? $this->newParticipant($peopleId, $peopleName);

                $participant->set($field->value, $externalId);
                $this->rememberMirroredId(
                    Participant::class,
                    $field,
                    $externalId,
                    (int) $participant->getId()
                );
            }

            $this->participants[(int) $participant->getId()] = $participant;

            // The seats are still this buyer's, and EventVersionParticipant.participant is
            // non-null in GraphQL, so a participant deleted in Kanvas comes back.
            if ($participant->is_deleted) {
                $participant->forceFill(['is_deleted' => false])->saveQuietly();
            }

            return $participant;
        });
    }

    protected function tenantParticipants(): EloquentBuilder
    {
        return Participant::withTrashed()
            ->where('apps_id', $this->app->getId())
            ->where('companies_id', $this->context->companyId());
    }

    protected function newParticipant(int $peopleId, ?string $peopleName): Participant
    {
        $name = $peopleName ?? People::withTrashed()->whereKey($peopleId)->value('name');

        $participant = new Participant();
        $participant->forceFill([
            'uuid' => Str::uuid()->toString(),
            'apps_id' => $this->app->getId(),
            'companies_id' => $this->context->companyId(),
            'users_id' => $this->user->getId(),
            'people_id' => $peopleId,
            'theme_area_id' => $this->context->themeAreaId(),
            'participant_status_id' => 1,
            'slug' => Str::slug("{$name} {$peopleId}"),
        ]);
        $participant->saveQuietly();

        return $participant;
    }

    /**
     * One placeholder per company for registrations with no resolvable buyer (~4.4% at the
     * source), so their seats still count against capacity.
     */
    protected function unknownGuest(): Participant
    {
        if ($this->unknownGuest !== null) {
            return $this->unknownGuest;
        }

        $people = new ResolveBrushCrazyUnknownGuestAction(
            $this->app,
            $this->user,
            $this->context,
            $this->idMaps
        )->execute();

        return $this->unknownGuest = $this->participantFor(
            (int) $people->getId(),
            CustomFieldEnum::BRUSHCRAZY_UNKNOWN_GUEST,
            ResolveBrushCrazyUnknownGuestAction::EXTERNAL_ID
        );
    }

    protected function participantTypeId(): int
    {
        return $this->participantTypeId ??= $this->idMaps->lookup(
            $this->context->companyId(),
            ParticipantType::class,
            'participant-type:attendee'
        );
    }

    /**
     * @param  array<string, array<int, int>>  $byMorph
     */
    protected static function matchesCalendarable(array $byMorph, string $typeColumn, string $idColumn): Closure
    {
        return function (Builder $query) use ($byMorph, $typeColumn, $idColumn) {
            foreach ($byMorph as $morph => $ids) {
                $query->orWhere(
                    fn (Builder $pair) => $pair->where($typeColumn, $morph)->whereIn($idColumn, $ids)
                );
            }
        };
    }
}
