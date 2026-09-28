<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Intras\Actions;

use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Intras\Client;
use Kanvas\Connectors\Intras\Enums\CustomFieldEnum;
use Kanvas\Connectors\Intras\Mappers\OrganizationMapper;
use Kanvas\Connectors\Intras\Mappers\ParticipantMapper;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Locations\Models\Countries;
use Throwable;

class PullOrganizationsFromIntrasAction
{
    /** @var array<string, int>|null lowercased country name => kanvas countries.id */
    protected ?array $countryIdByName = null;

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

        // companies has no agencies_id. Scope by "companies whose employees attended
        // at least one event in this agency": companies → participants → evp → ev.
        // Same chain-subquery pattern used for participants.
        $query = $client->table('companies as co')
            ->where('co.is_deleted', 0)
            ->select('co.*');

        if ($this->agencyId !== null) {
            $agencyId = $this->agencyId;
            $query->whereIn('co.id', function (QueryBuilder $sub) use ($agencyId) {
                $sub->select('p.companies_id')
                    ->from('participants as p')
                    ->join('events_versions_participants as evp', 'evp.participants_id', '=', 'p.id')
                    ->join('events_versions as ev', 'ev.id', '=', 'evp.events_versions_id')
                    ->where('ev.agencies_id', $agencyId)
                    ->where('evp.is_deleted', 0)
                    ->whereNotNull('p.companies_id');
            });
        }

        if ($this->lastSyncAt !== null) {
            $query->where('co.updated_at', '>=', $this->lastSyncAt);
        }

        $count = 0;

        $lookupNames = self::loadLookupNames($client);
        // "Vinculación Empresarial" is a self-FK, so the parent's name is resolved from the same
        // table. One pluck rather than a lookup per row.
        $companyNames = $client->table('companies')
            ->pluck('name', 'id')
            ->map(fn ($name) => trim((string) $name))
            ->all();

        $query->orderBy('co.id')->chunk(500, function ($rows) use (&$count, $client, $lookupNames, $companyNames) {
            $legacyIds = array_map(fn ($r) => (int) $r->id, $rows->all());
            $customFieldMap = self::loadCompanyCustomFields($client, $legacyIds);
            $officeMap = self::loadOfficesByCompany($client, $legacyIds);

            foreach ($rows as $row) {
                $mapped = OrganizationMapper::fromIntras(
                    $row,
                    $customFieldMap[(int) $row->id] ?? [],
                    $lookupNames,
                    $companyNames,
                );

                $org = Organization::firstOrCreate([
                    'name' => $mapped['name'],
                    'companies_id' => $this->company->getId(),
                    'apps_id' => $this->app->getId(),
                ], [
                    'users_id' => $this->user->getId(),
                ]);

                $org->set(CustomFieldEnum::INTRAS_COMPANY_ID->value, $row->id);

                foreach ($mapped['custom_fields'] as $key => $value) {
                    if ($value !== null) {
                        $org->set($key, $value);
                    }
                }

                $office = $officeMap[(int) $row->id] ?? null;
                $address = ParticipantMapper::addressFromOffice($office, $lookupNames);

                if ($address !== null) {
                    $this->attachAddressToOrganization($org, $address);
                }

                $count++;
            }
        });

        return $count;
    }

    /**
     * Write the company's own office as its address.
     *
     * Same `companies_offices` source the participants use — it is the only address anywhere in
     * SIPGO. A company can have several offices; the first is written as the organization's
     * address and re-imports update it rather than appending.
     *
     * @param array{address: ?string, city: ?string, country: ?string, sector: ?string} $address
     */
    protected function attachAddressToOrganization(Organization $organization, array $address): void
    {
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
            $existing = $organization->addresses()->first();

            if ($existing !== null) {
                $existing->update($columns);
            } else {
                $organization->addresses()->create($columns);
            }
        }

        // No slot for a Dominican sector on organizations_address — see addressFromOffice().
        if ($address['sector'] !== null) {
            $organization->set('sector_geografico', $address['sector']);
        }

        // Kept regardless of whether the FK resolved — see the participant side.
        if ($address['country'] !== null) {
            $organization->set('pais', $address['country']);
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
     * Resolve every catalog behind a company FK to [id => name].
     *
     * A catalog can be missing on an agency's install, so a failed read drops that one field
     * rather than the whole import — same contract as the participant lookups.
     *
     * @return array<string, array<int, string>>
     */
    public static function loadLookupNames(Client $client): array
    {
        $names = [];

        $tables = [
            ...OrganizationMapper::lookupTables(),
            // countries / cities / districts, for the office address below.
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
     * First `companies_offices` row per company, for a batch.
     *
     * A company can have several; the Gestor's Empresas address filters do not distinguish them,
     * so the first is taken as the organization's address.
     *
     * @param list<int> $companyIds
     *
     * @return array<int, object>
     */
    public static function loadOfficesByCompany(Client $client, array $companyIds): array
    {
        if ($companyIds === []) {
            return [];
        }

        $offices = [];

        $rows = $client->table('companies_offices')
            ->whereIn('companies_id', $companyIds)
            ->where('is_deleted', 0)
            ->orderBy('id')
            ->get();

        foreach ($rows as $office) {
            $companyId = (int) $office->companies_id;
            $offices[$companyId] ??= $office;
        }

        return $offices;
    }

    /**
     * Bulk-load `companies_custom_fields` for the names we care about, resolved by
     * `custom_fields.name` — the ids are install-specific.
     *
     * @param list<int> $companyIds
     *
     * @return array<int, array<string, string>>
     */
    public static function loadCompanyCustomFields(Client $client, array $companyIds): array
    {
        if ($companyIds === []) {
            return [];
        }

        $rows = $client->table('companies_custom_fields as ccf')
            ->join('custom_fields as cf', 'cf.id', '=', 'ccf.custom_fields_id')
            ->whereIn('ccf.companies_id', $companyIds)
            ->whereIn('cf.name', OrganizationMapper::customFieldNames())
            ->whereNotNull('ccf.value')
            ->where('ccf.value', '!=', '')
            ->select('ccf.companies_id', 'cf.name', 'ccf.value')
            ->get();

        $map = [];

        foreach ($rows as $row) {
            $map[(int) $row->companies_id][(string) $row->name] = (string) $row->value;
        }

        return $map;
    }
}
