<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Kanvas\Connectors\Contracts\Enums\ThirdPartyPeopleIdFieldEnum;
use Kanvas\Guild\Customers\DataTransferObject\PeopleDuplicateGroup;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Models\People;

/**
 * Same approach as FindOrganizationDuplicatesService: O(n) GROUP BY queries, no O(n²) fuzzy
 * matching. Operator UI calls this on demand. Returns at most $maxGroups groups (default 100).
 */
class FindPeopleDuplicatesService
{
    public function generate(
        AppInterface $app,
        CompanyInterface $company,
        int $maxGroups = 100,
    ): array {
        $appId = $app->getId();
        $companyId = $company->getId();

        $groups = [];
        $groups = array_merge($groups, $this->groupsByExternalIdConflict($appId, $companyId));
        $groups = array_merge($groups, $this->groupsByExactName($appId, $companyId));
        $groups = array_merge($groups, $this->groupsByLastName($appId, $companyId));
        $groups = array_merge($groups, $this->groupsByEmailMatch($appId, $companyId));

        // Stable dedup: a People can appear in multiple groups. Dimensions are merged above in
        // descending confidence order, so external_id_conflict wins ties on the same member set.
        $deduped = [];
        foreach ($groups as $group) {
            $signature = implode('|', $group->member_ids);
            if (! isset($deduped[$signature])) {
                $deduped[$signature] = $group;
            }
        }

        return array_slice(array_values($deduped), 0, $maxGroups);
    }

    /**
     * Two queries instead of a cross-connection JOIN, same reason as
     * `HasCustomFields::getByCustomFieldBuilderTransactionSafe` — a JOIN from `crm` straight into
     * `ecosystem`'s apps_custom_fields is pinned to `crm`'s REPEATABLE READ snapshot, so a custom
     * field written earlier in the same test transaction (on `ecosystem`) can be invisible to it.
     *
     * @return list<PeopleDuplicateGroup>
     */
    private function groupsByExternalIdConflict(int $appId, int $companyId): array
    {
        $customFields = DB::connection('ecosystem')
            ->table('apps_custom_fields')
            ->select('entity_id', 'value')
            ->where('companies_id', $companyId)
            ->where('model_name', People::class)
            ->where('is_deleted', false)
            ->whereIn('name', ThirdPartyPeopleIdFieldEnum::fieldNames())
            ->get();

        if ($customFields->isEmpty()) {
            return [];
        }

        $valuesByPeopleId = [];
        foreach ($customFields as $row) {
            $valuesByPeopleId[(int) $row->entity_id][] = (string) $row->value;
        }

        $people = DB::connection('crm')
            ->table('peoples')
            ->select('id', 'firstname', 'lastname')
            ->whereIn('id', array_keys($valuesByPeopleId))
            ->where('apps_id', $appId)
            ->where('companies_id', $companyId)
            ->where('is_deleted', false)
            ->whereNotNull('lastname')
            ->where('lastname', '!=', '')
            ->get();

        $byName = [];
        foreach ($people as $row) {
            $normName = strtolower(trim($row->firstname . ' ' . $row->lastname));
            $byName[$normName]['ids'][] = (int) $row->id;
            $byName[$normName]['sample'] = trim($row->firstname . ' ' . $row->lastname);
        }

        $out = [];
        foreach ($byName as $data) {
            $ids = $data['ids'];
            if (count($ids) < 2) {
                continue;
            }

            $distinctValues = [];
            foreach ($ids as $id) {
                foreach ($valuesByPeopleId[$id] ?? [] as $value) {
                    $distinctValues[$value] = true;
                }
            }
            if (count($distinctValues) < 2) {
                continue;
            }

            sort($ids);
            $out[] = new PeopleDuplicateGroup(
                canonical_id: $ids[0],
                member_ids: $ids,
                reason: 'external_id_conflict',
                sample_name: $data['sample'],
            );
        }

        return $out;
    }

    /**
     * @return list<PeopleDuplicateGroup>
     */
    private function groupsByExactName(int $appId, int $companyId): array
    {
        $rows = DB::connection('crm')
            ->table('peoples')
            ->selectRaw(
                "LOWER(TRIM(CONCAT(firstname, ' ', lastname))) as norm_name, "
                . 'GROUP_CONCAT(id ORDER BY id ASC) as ids, '
                . "MIN(CONCAT(firstname, ' ', lastname)) as sample_name",
            )
            ->where('apps_id', $appId)
            ->where('companies_id', $companyId)
            ->where('is_deleted', false)
            ->whereNotNull('lastname')
            ->where('lastname', '!=', '')
            ->groupBy('norm_name')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        return $this->mapRowsToGroups($rows, 'exact_name');
    }

    /**
     * @return list<PeopleDuplicateGroup>
     */
    private function groupsByLastName(int $appId, int $companyId): array
    {
        $rows = DB::connection('crm')
            ->table('peoples')
            ->selectRaw(
                'LOWER(TRIM(lastname)) as norm_name, '
                . 'GROUP_CONCAT(id ORDER BY id ASC) as ids, '
                . "MIN(CONCAT(firstname, ' ', lastname)) as sample_name",
            )
            ->where('apps_id', $appId)
            ->where('companies_id', $companyId)
            ->where('is_deleted', false)
            ->whereNotNull('lastname')
            ->where('lastname', '!=', '')
            ->groupBy('norm_name')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        return $this->mapRowsToGroups($rows, 'lastname_match');
    }

    /**
     * @return list<PeopleDuplicateGroup>
     */
    private function groupsByEmailMatch(int $appId, int $companyId): array
    {
        $emailTypeIds = [
            ContactTypeEnum::EMAIL->value,
            ContactTypeEnum::PRIMARY_EMAIL->value,
            ContactTypeEnum::SECONDARY_EMAIL->value,
        ];

        $rows = DB::connection('crm')
            ->table('peoples_contacts')
            ->join('peoples', 'peoples.id', '=', 'peoples_contacts.peoples_id')
            ->selectRaw(
                'LOWER(TRIM(peoples_contacts.value)) as norm_email, '
                . 'GROUP_CONCAT(DISTINCT peoples.id ORDER BY peoples.id ASC) as ids, '
                . "MIN(CONCAT(peoples.firstname, ' ', peoples.lastname)) as sample_name",
            )
            ->whereIn('peoples_contacts.contacts_types_id', $emailTypeIds)
            ->where('peoples_contacts.is_deleted', false)
            ->where('peoples.apps_id', $appId)
            ->where('peoples.companies_id', $companyId)
            ->where('peoples.is_deleted', false)
            ->whereNotNull('peoples_contacts.value')
            ->where('peoples_contacts.value', '!=', '')
            ->groupBy('norm_email')
            ->havingRaw('COUNT(DISTINCT peoples.id) > 1')
            ->get();

        return $this->mapRowsToGroups($rows, 'email_match');
    }

    /**
     * @param  Collection<int, object>  $rows
     * @return list<PeopleDuplicateGroup>
     */
    private function mapRowsToGroups(Collection $rows, string $reason): array
    {
        $out = [];
        foreach ($rows as $row) {
            $memberIds = array_map('intval', explode(',', (string) $row->ids));
            sort($memberIds);
            if (count($memberIds) < 2) {
                continue;
            }
            $out[] = new PeopleDuplicateGroup(
                canonical_id: $memberIds[0],
                member_ids: $memberIds,
                reason: $reason,
                sample_name: (string) ($row->sample_name ?? ''),
            );
        }

        return $out;
    }
}
