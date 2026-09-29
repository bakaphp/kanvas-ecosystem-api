<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Intras\Actions;

use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Facades\DB;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Intras\Client;
use Kanvas\Connectors\Intras\Enums\CustomFieldEnum;
use Kanvas\Connectors\Intras\Mappers\EntitlementMapper;
use Kanvas\Guild\Organizations\Models\Organization;

/**
 * Company plan entitlements and courtesy-pass pools, as JSON on the Organization.
 *
 * Neither had any Kanvas home, which left ~8 Empresas filters and the whole Cortesía tab with
 * nothing to read. An organization holds several of each over time, so they cannot be flat custom
 * fields; the arrays flatten to one row each in `rpt_empresa_plan` / `rpt_cortesia`.
 *
 * Runs after organizations — it resolves the company through its legacy-id map.
 */
class PullEntitlementsFromIntrasAction
{
    public function __construct(
        protected AppInterface $app,
        protected Companies $company,
        protected UserInterface $user,
        protected ?int $agencyId = null
    ) {
    }

    /**
     * @return array{plans: int, courtesy_pools: int}
     */
    public function execute(): array
    {
        $client = new Client($this->app);
        $counts = ['plans' => 0, 'courtesy_pools' => 0];

        $organizationIdMap = $this->organizationIdMap();

        if ($organizationIdMap === []) {
            return $counts;
        }

        $planNames = $this->namesFrom($client, 'plans');
        $tierNames = $this->namesFrom($client, 'plans_details');
        $eventNames = $this->namesFrom($client, 'events');

        $this->writeGrouped(
            $this->groupByCompany($client, 'companies_plans', $organizationIdMap),
            'planes',
            fn ($row) => EntitlementMapper::planFromIntras($row, $planNames, $tierNames),
            $counts['plans'],
        );

        $this->writeGrouped(
            $this->groupByCompany($client, 'companies_courtsey_passes', $organizationIdMap),
            'cortesias',
            fn ($row) => EntitlementMapper::courtesyPoolFromIntras($row, $eventNames),
            $counts['courtesy_pools'],
        );

        return $counts;
    }

    /**
     * @param array<int, list<object>> $byOrganization kanvas organization id => legacy rows
     */
    protected function writeGrouped(array $byOrganization, string $field, callable $map, int &$count): void
    {
        foreach ($byOrganization as $organizationId => $rows) {
            /** @var Organization|null $organization */
            $organization = Organization::where('id', $organizationId)
                ->fromApp($this->app)
                ->fromCompany($this->company)
                ->notDeleted()
                ->first();

            if ($organization === null) {
                continue;
            }

            // Replaced wholesale rather than appended — a re-import must not stack duplicates of
            // the same pool.
            $organization->set($field, array_map($map, $rows));

            $count += count($rows);
        }
    }

    /**
     * @param array<int, int> $organizationIdMap legacy companies.id => kanvas organization id
     *
     * @return array<int, list<object>>
     */
    protected function groupByCompany(Client $client, string $table, array $organizationIdMap): array
    {
        $query = $client->table($table)->where('is_deleted', 0);

        if ($this->agencyId !== null) {
            $query->where('agencies_id', $this->agencyId);
        }

        $grouped = [];

        foreach ($query->orderBy('id')->get() as $row) {
            $organizationId = $organizationIdMap[(int) ($row->companies_id ?? 0)] ?? null;

            if ($organizationId === null) {
                continue;
            }

            $grouped[$organizationId][] = $row;
        }

        return $grouped;
    }

    /**
     * @return array<int, string>
     */
    protected function namesFrom(Client $client, string $table): array
    {
        return $client->table($table)
            ->pluck('name', 'id')
            ->map(fn ($name) => trim((string) $name))
            ->all();
    }

    /**
     * @return array<int, int>
     */
    protected function organizationIdMap(): array
    {
        return DB::connection('ecosystem')
            ->table('apps_custom_fields')
            ->where('companies_id', $this->company->getId())
            ->where('model_name', Organization::class)
            ->where('name', CustomFieldEnum::INTRAS_COMPANY_ID->value)
            ->where('is_deleted', 0)
            ->pluck('entity_id', 'value')
            ->mapWithKeys(fn ($organizationId, $legacyId) => [(int) $legacyId => (int) $organizationId])
            ->all();
    }
}
