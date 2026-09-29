<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Intras\Actions;

use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Apollo\Enums\ConfigurationEnum as ApolloConfigurationEnum;
use Kanvas\Connectors\Intras\Client;
use Kanvas\Connectors\Intras\Enums\CustomFieldEnum;
use Kanvas\Connectors\Intras\Mappers\ParticipantMapper;
use Kanvas\Event\Events\Models\Event;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Models\Contact;
use Kanvas\Guild\Customers\Models\ContactType;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Customers\Models\PeopleEmploymentHistory;
use Kanvas\Guild\Customers\Models\PeopleType;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Guild\Organizations\Models\OrganizationPeople;
use Kanvas\Locations\Models\Countries;
use stdClass;
use Throwable;

class PullParticipantsFromIntrasAction
{
    /** @var array<int|string, int> intras_company_id => kanvas_organization_id */
    protected array $organizationIdMap = [];

    /** @var array<int, int> intras participants.id => kanvas peoples_id, scoped to this company */
    protected array $participantIdMap = [];

    /** @var array<string, int>|null lowercased country name => kanvas countries.id */
    protected ?array $countryIdByName = null;

    /** @var array<int|string, int> intras events_id => kanvas event id */
    protected array $eventIdMap = [];

    public function __construct(
        protected AppInterface $app,
        protected Companies $company,
        protected UserInterface $user,
        protected ?string $lastSyncAt = null,
        protected ?int $agencyId = null
    ) {
    }

    public function execute(): int
    {
        $client = new Client($this->app);

        // participants has no agencies_id and its parent `companies` doesn't either,
        // so the only way to scope by agency is "people who have ever registered for
        // an event in this agency" — via events_versions_participants → events_versions.
        // Deleted participants are imported too, flagged rather than skipped.
        //
        // SIPGO keeps the registration when it soft-deletes the person, so skipping them drops
        // the attendance as well — 24 of agency 4's 798 registrations, ~3%. Historical counts
        // would then never reconcile against the legacy system, which is exactly the check
        // someone runs at cutover. The Gestor filters them out by default; reports can include
        // them.
        $query = $client->table('participants as p')
            ->select('p.*');

        if ($this->agencyId !== null) {
            $agencyId = $this->agencyId;
            $query->whereIn('p.id', function (QueryBuilder $sub) use ($agencyId) {
                $sub->select('evp.participants_id')
                    ->from('events_versions_participants as evp')
                    ->join('events_versions as ev', 'ev.id', '=', 'evp.events_versions_id')
                    ->where('ev.agencies_id', $agencyId)
                    ->where('evp.is_deleted', 0);
            });
        }

        if ($this->lastSyncAt !== null) {
            $query->where('p.updated_at', '>=', $this->lastSyncAt);
        }

        $participantType = PeopleType::firstOrCreate([
            'name' => 'Participant',
            'apps_id' => $this->app->getId(),
            'companies_id' => $this->company->getId(),
        ], [
            'users_id' => $this->user->getId(),
            'is_default' => true,
        ]);

        $keyContactType = PeopleType::firstOrCreate([
            'name' => 'Key Contact',
            'apps_id' => $this->app->getId(),
            'companies_id' => $this->company->getId(),
        ], [
            'users_id' => $this->user->getId(),
        ]);

        // Preload [intras_company_id => kanvas_organization_id] once. Replaces a
        // per-row whereHas subquery against apps_custom_fields. For 26k people
        // that's ~26k SQL queries collapsed into 1.
        $this->preloadOrganizationMap();

        // Same reason: the legacy-id → People map is what makes re-imports idempotent, and looking
        // it up per row would be another 26k queries.
        $this->preloadParticipantIdMap();

        // Events-of-interest are stored as Kanvas event ids, so the legacy ids need translating.
        $this->eventIdMap = DB::connection('ecosystem')
            ->table('apps_custom_fields')
            ->where('companies_id', $this->company->getId())
            ->where('model_name', Event::class)
            ->where('name', CustomFieldEnum::INTRAS_EVENT_ID->value)
            ->where('is_deleted', 0)
            ->pluck('entity_id', 'value')
            ->map(fn ($id) => (int) $id)
            ->all();

        $lookupNames = self::loadLookupNames($client);

        $count = 0;

        $query->orderBy('p.id')->chunk(500, function ($rows) use ($client, &$count, $participantType, $keyContactType, $lookupNames) {
            $participantIds = array_map(fn ($r) => (int) $r->id, $rows->all());
            $contactMap = self::loadParticipantContacts($client, $participantIds);

            $officeIds = array_values(array_unique(array_filter(array_map(
                fn ($r) => $r->companies_offices_id === null ? null : (int) $r->companies_offices_id,
                $rows->all()
            ))));
            $officeMap = self::loadOffices($client, $officeIds);
            $groupMap = self::loadGroupNames($client, $participantIds);
            $interestMap = self::loadInterestNames($client, $participantIds);
            $eventInterestMap = self::loadEventInterests($client, $participantIds, $this->eventIdMap);

            foreach ($rows as $row) {
                $contactRows = $contactMap[(int) $row->id] ?? [];
                $mapped = ParticipantMapper::fromIntras($row, $contactRows, $lookupNames);

                $people = $this->resolvePeople(
                    $row,
                    $mapped,
                    $row->is_key_participant ? $keyContactType : $participantType,
                );

                // A People column rather than a custom field, so it is written on the model.
                if (($mapped['dob'] ?? null) !== null && $people->dob !== $mapped['dob']) {
                    $people->dob = $mapped['dob'];
                    $people->saveQuietly();
                }

                $people->set(CustomFieldEnum::INTRAS_PARTICIPANT_ID->value, $row->id);

                foreach ($mapped['custom_fields'] as $key => $value) {
                    if ($value !== null) {
                        $people->set($key, $value);
                    }
                }

                self::attachContactsToPeople($people, $mapped['contacts']);

                $office = $officeMap[(int) ($row->companies_offices_id ?? 0)] ?? null;
                $address = ParticipantMapper::addressFromOffice($office, $lookupNames);

                if ($address !== null) {
                    $this->attachAddressToPeople($people, $address);
                }

                // Flat membership sets, so tags rather than their own grain. addTag() is
                // idempotent, which is what keeps re-imports from stacking duplicates.
                $tags = [
                    ...($groupMap[(int) $row->id] ?? []),
                    ...($interestMap[(int) $row->id] ?? []),
                ];

                if ($tags !== []) {
                    $people->addTags($tags, $this->app, $this->user, $this->company);
                }

                $eventInterest = $eventInterestMap[(int) $row->id] ?? null;

                if ($eventInterest !== null) {
                    if ($eventInterest['eventos'] !== []) {
                        $people->set('eventos_interes', $eventInterest['eventos']);
                    }

                    $people->set('referido', $eventInterest['referido']);
                }

                $position = isset($row->position) ? trim((string) $row->position) : null;
                $this->linkToOrganization($people, $row->companies_id, $position !== '' ? $position : null);

                $count++;
            }
        });

        return $count;
    }

    /**
     * Resolve every lookup table behind a participant profile FK to [id => name].
     * They're all small catalogs, so one query each up front beats a join per chunk.
     *
     * A table can be missing on an agency's install — the catalogs were added to
     * SIPGO over time — so a failed read drops that field rather than the import.
     *
     * @return array<string, array<int, string>> lookup table => [id => name]
     */
    public static function loadLookupNames(Client $client): array
    {
        $names = [];

        $tables = [
            ...ParticipantMapper::lookupTables(),
            ...ParticipantMapper::officeLookupTables(),
        ];

        foreach ($tables as $table) {
            try {
                $names[$table] = $client->table($table)
                    ->pluck('name', 'id')
                    ->map(fn ($name) => trim((string) $name))
                    ->all();
            } catch (Throwable) {
                $names[$table] = [];
            }
        }

        return $names;
    }

    /**
     * Bulk-load participants_custom_fields for the contact fields we care about,
     * joined to custom_fields to resolve by name (legacy custom_fields_id values
     * are install-specific; name is stable).
     *
     * @param list<int> $participantIds
     *
     * @return array<int, array<string, string>> participant_id => [field_name => value]
     */
    public static function loadParticipantContacts(Client $client, array $participantIds): array
    {
        if ($participantIds === []) {
            return [];
        }

        $rows = $client->table('participants_custom_fields as pcf')
            ->join('custom_fields as cf', 'cf.id', '=', 'pcf.custom_fields_id')
            ->whereIn('pcf.participants_id', $participantIds)
            ->whereIn('cf.name', ParticipantMapper::contactFieldNames())
            ->whereNotNull('pcf.value')
            ->where('pcf.value', '!=', '')
            ->select('pcf.participants_id', 'cf.name', 'pcf.value')
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row->participants_id][$row->name] = (string) $row->value;
        }

        return $map;
    }

    /**
     * @param list<array{type: ContactTypeEnum, value: string, weight: int}> $contacts
     */
    public static function attachContactsToPeople(People $people, array $contacts): void
    {
        foreach ($contacts as $contact) {
            $typeId = ContactType::getByName($contact['type']->getName())->getId();
            $value = Contact::normalizeValue($contact['value'], $typeId);
            if ($value === '') {
                continue;
            }

            // `is_opt_out` is deliberately absent: it is consent, not imported data. An opt-out
            // sets it to 1 on every Contact the person has, and `updateOrCreate` applies its
            // second argument on update as well as create — so listing it here re-enabled
            // outreach to anyone who had opted out, every time the importer ran. The column
            // defaults to 0, so a genuinely new contact still lands opted in.
            Contact::updateOrCreate(
                [
                    'peoples_id' => $people->getId(),
                    'value' => $value,
                    'contacts_types_id' => $typeId,
                ],
                [
                    'weight' => $contact['weight'],
                ]
            );
        }
    }

    protected function linkToOrganization(People $people, ?int $intrasCompanyId, ?string $position = null): void
    {
        if ($intrasCompanyId === null) {
            return;
        }

        // Once Apollo has enriched this person it owns the current-employer relationship
        // (it prunes the org pivot to where they actually work now). Re-adding the Intras
        // employer link + status=1 row here would undo that, so we defer to Apollo and
        // leave already-enriched people untouched.
        if ($people->get(ApolloConfigurationEnum::APOLLO_DATA_ENRICHMENT_CUSTOM_FIELDS->value)) {
            return;
        }

        $organizationId = $this->organizationIdMap[$intrasCompanyId] ?? null;
        if ($organizationId === null) {
            return;
        }

        // Inline the OrganizationPeople::addPeopleToOrganization() pivot insert so
        // we never need to instantiate the Organization model just to get its id.
        OrganizationPeople::firstOrCreate([
            'organizations_id' => $organizationId,
            'peoples_id' => $people->getId(),
        ], [
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        // Intras only knows the current employer/role (no historical roles), so we
        // record a single status=1 row keyed on (app, person, org). updateOrCreate
        // keeps the title in sync with Intras and shares the same table Apollo
        // enrichment writes its full history into.
        //
        // position and start_date are NOT NULL in the schema, but Intras has no role
        // title for some participants and never a start date. We store '' for a
        // missing title and anchor start_date to when we first recorded the person
        // (a stable proxy, so re-syncs don't churn the row).
        $startDate = $people->created_at
            ? Carbon::parse($people->created_at)->format('Y-m-d')
            : date('Y-m-d');

        PeopleEmploymentHistory::updateOrCreate(
            [
                'apps_id' => $this->app->getId(),
                'peoples_id' => $people->getId(),
                'organizations_id' => $organizationId,
                'status' => 1,
            ],
            [
                'position' => $position ?? '',
                'start_date' => $startDate,
            ]
        );
    }

    /**
     * Preload [intras_company_id => kanvas_organization_id]. Single index-backed
     * query against apps_custom_fields for INTRAS_COMPANY_ID rows on Organization.
     */
    /**
     * Resolve the Kanvas People row for a legacy participant, keyed on the SIPGO id.
     *
     * Matching used to be `firstname + lastname`, which merged distinct same-name participants into
     * one row and left only the last-imported legacy id — erasing exactly the duplicates the
     * duplicate-profile report exists to find.
     *
     * The key is scoped to the company on purpose. `participants` carries no `agencies_id`; agency
     * scope is derived as "registered for an event in this agency" (see execute()), so one legacy
     * participant active in two agencies is legitimately two People rows in two companies. A
     * company-wide key would collapse them and silently empty the smaller agencies.
     */
    protected function resolvePeople(stdClass $row, array $mapped, ?PeopleType $peopleType): People
    {
        $legacyId = (int) $row->id;
        $name = $mapped['firstname'] . ' ' . $mapped['lastname'];

        $peopleId = $this->participantIdMap[$legacyId] ?? null;

        if ($peopleId !== null) {
            /** @var People|null $existing */
            // withTrashed() is required, not optional: a participant deleted in SIPGO is imported
            // flagged, and People carries a global `is_deleted = 0` scope, so without it the map
            // finds the id but the query returns null and every run creates another duplicate.
            $existing = People::withTrashed()
                ->where('id', $peopleId)
                ->fromApp($this->app)
                ->fromCompany($this->company)
                ->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        $people = new People();
        $people->apps_id = $this->app->getId();
        $people->companies_id = $this->company->getId();
        $people->users_id = $this->user->getId();
        $people->firstname = $mapped['firstname'];
        $people->lastname = $mapped['lastname'];
        $people->name = $name;
        $people->people_types_id = $peopleType?->getId();
        $people->is_deleted = (int) ($row->is_deleted ?? 0);

        if ($row->created_at !== null) {
            $people->created_at = $row->created_at;
        }

        // saveQuietly() keeps 26k imported people off the Scout index and the LightHouse cache,
        // but it also skips UuidTrait's `creating` hook, so the uuid has to be asked for.
        $people->generateUuidIfMissing()->saveQuietly();

        $this->participantIdMap[$legacyId] = $people->getId();

        return $people;
    }

    /**
     * Events a participant registered interest in, plus whether someone referred them.
     *
     * The Gestor drives two filters off this one table: "Eventos de Interés" on `events_id`, and
     * the Referido / Interés pair on whether `participants_referral_id` is non-zero. Stored as a
     * custom field rather than a pivot — no new Kanvas tables — with Kanvas event ids so the UI
     * filter matches on the same value it sends.
     *
     * @param list<int> $participantIds
     * @param array<int|string, int> $eventIdMap intras events_id => kanvas event id
     *
     * @return array<int, array{eventos: list<int>, referido: bool}>
     */
    public static function loadEventInterests(Client $client, array $participantIds, array $eventIdMap): array
    {
        if ($participantIds === []) {
            return [];
        }

        $rows = $client->table('participants_events_interests')
            ->whereIn('participants_id', $participantIds)
            ->where('is_deleted', 0)
            ->select('participants_id', 'events_id', 'participants_referral_id')
            ->get();

        $interests = [];

        foreach ($rows as $row) {
            $participantId = (int) $row->participants_id;
            $interests[$participantId] ??= ['eventos' => [], 'referido' => false];

            $kanvasEventId = $eventIdMap[(int) $row->events_id] ?? null;

            if ($kanvasEventId !== null && ! in_array($kanvasEventId, $interests[$participantId]['eventos'], true)) {
                $interests[$participantId]['eventos'][] = $kanvasEventId;
            }

            if ((int) ($row->participants_referral_id ?? 0) !== 0) {
                $interests[$participantId]['referido'] = true;
            }
        }

        return $interests;
    }

    /**
     * Group names per participant, for a batch.
     *
     * Groups are a flat membership set — the Gestor only ever tests one at a time — so they become
     * tags rather than their own grain. Same for interests below.
     *
     * @param list<int> $participantIds
     *
     * @return array<int, list<string>>
     */
    public static function loadGroupNames(Client $client, array $participantIds): array
    {
        if ($participantIds === []) {
            return [];
        }

        $rows = $client->table('participants_groups as pg')
            ->join('groups as g', 'g.id', '=', 'pg.groups_id')
            ->whereIn('pg.participants_id', $participantIds)
            ->where('pg.is_deleted', 0)
            ->select('pg.participants_id', 'g.name')
            ->get();

        return self::groupNamesByParticipant($rows);
    }

    /**
     * Interest names per participant, for a batch.
     *
     * `participants_interests.name` is free text on the row itself, not an FK — the Gestor filters
     * it by name (`participantsinterests.name`), which is why it is matched that way here.
     *
     * @param list<int> $participantIds
     *
     * @return array<int, list<string>>
     */
    public static function loadInterestNames(Client $client, array $participantIds): array
    {
        if ($participantIds === []) {
            return [];
        }

        $rows = $client->table('participants_interests')
            ->whereIn('participants_id', $participantIds)
            ->where('is_deleted', 0)
            ->whereNotNull('name')
            ->select('participants_id', 'name')
            ->get();

        return self::groupNamesByParticipant($rows);
    }

    /**
     * @return array<int, list<string>>
     */
    public static function groupNamesByParticipant(iterable $rows): array
    {
        $names = [];

        foreach ($rows as $row) {
            $name = trim((string) ($row->name ?? ''));

            if ($name === '') {
                continue;
            }

            $participantId = (int) $row->participants_id;

            if (! in_array($name, $names[$participantId] ?? [], true)) {
                $names[$participantId][] = $name;
            }
        }

        return $names;
    }

    /**
     * `companies_offices` rows for a batch of participants, keyed by office id.
     *
     * SIPGO keeps no address on the participant — the office is the only one there is — so this is
     * what feeds dirección / país / ciudad / sector.
     *
     * @param list<int> $officeIds
     *
     * @return array<int, stdClass>
     */
    public static function loadOffices(Client $client, array $officeIds): array
    {
        if ($officeIds === []) {
            return [];
        }

        $offices = [];

        foreach ($client->table('companies_offices')->whereIn('id', $officeIds)->get() as $office) {
            $offices[(int) $office->id] = $office;
        }

        return $offices;
    }

    /**
     * Write the office address onto People, replacing whatever a previous run wrote rather than
     * appending — re-importing must not leave a trail of stale addresses.
     *
     * @param array{address: ?string, city: ?string, country: ?string, sector: ?string} $address
     */
    protected function attachAddressToPeople(People $people, array $address): void
    {
        // peoples_address.countries_id is an FK, not a name — resolve it against the Kanvas
        // catalog and skip it when the legacy name has no match rather than inventing a row.
        $countryId = $address['country'] === null
            ? null
            : ($this->countryIdByName()[mb_strtolower($address['country'])] ?? null);

        $columns = array_filter(
            [
                'address' => $address['address'],
                'city' => $address['city'],
                'countries_id' => $countryId,
            ],
            fn ($value) => $value !== null
        );

        if ($columns !== []) {
            $existing = $people->address()->first();

            if ($existing !== null) {
                $existing->update($columns);
            } else {
                $people->address()->create($columns);
            }
        }

        // No slot for a Dominican sector on peoples_address — see addressFromOffice().
        if ($address['sector'] !== null) {
            $people->set('sector', $address['sector']);
        }

        // The legacy country name is kept whether or not it resolved to a Kanvas country.
        // Most of these are "REPUBLICA DOMINICANA", which the catalog does not carry under that
        // spelling — without this the País filter would be empty for nearly every record.
        if ($address['country'] !== null) {
            $people->set('pais', $address['country']);
        }
    }

    /**
     * [lowercased country name => kanvas countries.id], memoised for the run.
     *
     * @return array<string, int>
     */
    protected function countryIdByName(): array
    {
        if ($this->countryIdByName === null) {
            $this->countryIdByName = Countries::pluck('id', 'name')
                ->mapWithKeys(fn ($id, $name) => [mb_strtolower(trim((string) $name)) => (int) $id])
                ->all();
        }

        return $this->countryIdByName;
    }

    /**
     * [legacy participants.id => kanvas peoples_id] for this company, in one query.
     *
     * Same reasoning as preloadOrganizationMap: a per-row custom-field lookup would be 26k queries
     * (52k with the transaction-safe builder) for what one pluck answers.
     */
    protected function preloadParticipantIdMap(): void
    {
        $this->participantIdMap = DB::connection('ecosystem')
            ->table('apps_custom_fields')
            ->where('companies_id', $this->company->getId())
            ->where('model_name', People::class)
            ->where('name', CustomFieldEnum::INTRAS_PARTICIPANT_ID->value)
            ->where('is_deleted', 0)
            ->pluck('entity_id', 'value')
            ->mapWithKeys(fn ($peopleId, $legacyId) => [(int) $legacyId => (int) $peopleId])
            ->all();
    }

    protected function preloadOrganizationMap(): void
    {
        $this->organizationIdMap = DB::connection('ecosystem')
            ->table('apps_custom_fields')
            ->where('companies_id', $this->company->getId())
            ->where('model_name', Organization::class)
            ->where('name', CustomFieldEnum::INTRAS_COMPANY_ID->value)
            ->where('is_deleted', 0)
            ->pluck('entity_id', 'value')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
