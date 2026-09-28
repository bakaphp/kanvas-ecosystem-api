<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Intras\Actions;

use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Intras\Client;
use Kanvas\Connectors\Intras\Enums\CustomFieldEnum;
use Kanvas\Connectors\Intras\Mappers\FacilitatorMapper;
use Kanvas\Event\Facilitators\Models\Facilitator;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Customers\Models\PeopleType;
use stdClass;
use Throwable;

class PullFacilitatorsFromIntrasAction
{
    /** @var array<int, int> intras facilitators.id => kanvas peoples_id, scoped to this company */
    protected array $facilitatorIdMap = [];

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

        $query = $client->table('facilitators')
            ->where('is_deleted', 0);

        if ($this->agencyId !== null) {
            $query->where('agencies_id', $this->agencyId);
        }

        if ($this->lastSyncAt !== null) {
            $query->where('updated_at', '>=', $this->lastSyncAt);
        }

        $facilitatorType = PeopleType::firstOrCreate([
            'name' => 'Facilitator',
            'apps_id' => $this->app->getId(),
            'companies_id' => $this->company->getId(),
        ], [
            'users_id' => $this->user->getId(),
        ]);

        $count = 0;

        $this->preloadFacilitatorIdMap();
        $lookupNames = self::loadLookupNames($client);

        $query->orderBy('id')->chunk(500, function ($rows) use (&$count, $client, $facilitatorType, $lookupNames) {
            $legacyIds = array_map(fn ($r) => (int) $r->id, $rows->all());
            $contactMap = self::loadFacilitatorContacts($client, $legacyIds);

            foreach ($rows as $row) {
                $mapped = FacilitatorMapper::fromIntras(
                    $row,
                    $contactMap[(int) $row->id] ?? [],
                    $lookupNames,
                );

                $people = $this->resolvePeople($row, $mapped, $facilitatorType);

                $people->set(CustomFieldEnum::INTRAS_FACILITATOR_ID->value, $row->id);

                foreach ($mapped['custom_fields'] as $key => $value) {
                    $people->set($key, $value);
                }

                PullParticipantsFromIntrasAction::attachContactsToPeople($people, $mapped['contacts']);

                // A Facilitator row and the event_version_facilitators pivot are what the Gestor's
                // whole facilitator filter set runs on. Only People were being created, so both
                // stayed empty and no event version ever had a facilitator.
                $this->resolveFacilitator($row, $people, $mapped);

                $count++;
            }
        });

        return $count;
    }

    /**
     * Same reasoning as the participant dedup: matching on firstname + lastname merged distinct
     * facilitators who happen to share a name, and the key is company-scoped because agency scope
     * is derived.
     */
    protected function resolvePeople(stdClass $row, array $mapped, ?PeopleType $peopleType): People
    {
        $legacyId = (int) $row->id;
        $peopleId = $this->facilitatorIdMap[$legacyId] ?? null;

        if ($peopleId !== null) {
            /** @var People|null $existing */
            // withTrashed(): the map says this legacy facilitator is that Kanvas row, so a row
            // soft-deleted on the Kanvas side has to be reused, not shadowed by a second one.
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
        $people->name = $mapped['firstname'] . ' ' . $mapped['lastname'];
        $people->people_types_id = $peopleType?->getId();

        if ($row->created_at !== null) {
            $people->created_at = $row->created_at;
        }

        // saveQuietly() skips UuidTrait's `creating` hook — see the participant importer.
        $people->generateUuidIfMissing()->saveQuietly();

        $this->facilitatorIdMap[$legacyId] = $people->getId();

        return $people;
    }

    /**
     * The `facilitators` row itself, which `event_version_facilitators` points at.
     */
    protected function resolveFacilitator(stdClass $row, People $people, array $mapped): Facilitator
    {
        // `facilitators.slug` is NOT NULL with no default and the model carries no SlugTrait, so
        // it has to be set explicitly or the insert fails. Suffixed with the People id because
        // two facilitators may legitimately share a name.
        /** @var Facilitator $facilitator */
        $facilitator = Facilitator::firstOrCreate([
            'apps_id' => $this->app->getId(),
            'companies_id' => $this->company->getId(),
            'people_id' => $people->getId(),
        ], [
            'users_id' => $this->user->getId(),
            'slug' => Str::slug($people->name . '-' . $people->getId()),
        ]);

        $columns = array_filter(
            [
                'identification' => $mapped['identification'],
                'resume' => $mapped['resume'],
            ],
            fn ($value) => $value !== null
        );

        if ($columns !== []) {
            $facilitator->update($columns);
        }

        $facilitator->set(CustomFieldEnum::INTRAS_FACILITATOR_ID->value, $row->id);

        return $facilitator;
    }

    protected function preloadFacilitatorIdMap(): void
    {
        $this->facilitatorIdMap = DB::connection('ecosystem')
            ->table('apps_custom_fields')
            ->where('companies_id', $this->company->getId())
            ->where('model_name', People::class)
            ->where('name', CustomFieldEnum::INTRAS_FACILITATOR_ID->value)
            ->where('is_deleted', 0)
            ->pluck('entity_id', 'value')
            ->mapWithKeys(fn ($peopleId, $legacyId) => [(int) $legacyId => (int) $peopleId])
            ->all();
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function loadLookupNames(Client $client): array
    {
        $names = [];

        foreach (FacilitatorMapper::lookupTables() as $table) {
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
     * @param list<int> $facilitatorIds
     *
     * @return array<int, array<string, string>>
     */
    public static function loadFacilitatorContacts(Client $client, array $facilitatorIds): array
    {
        if ($facilitatorIds === []) {
            return [];
        }

        $rows = $client->table('facilitators_custom_fields as fcf')
            ->join('custom_fields as cf', 'cf.id', '=', 'fcf.custom_fields_id')
            ->whereIn('fcf.facilitators_id', $facilitatorIds)
            ->whereIn('cf.name', FacilitatorMapper::contactFieldNames())
            ->whereNotNull('fcf.value')
            ->where('fcf.value', '!=', '')
            ->select('fcf.facilitators_id', 'cf.name', 'fcf.value')
            ->get();

        $map = [];

        foreach ($rows as $row) {
            $map[(int) $row->facilitators_id][(string) $row->name] = (string) $row->value;
        }

        return $map;
    }
}
