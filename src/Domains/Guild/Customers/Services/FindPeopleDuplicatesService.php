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
     * Same 4 dimensions as generate(), but scoped to a single record instead of a full-table
     * GROUP BY — for checking one just-created People without re-scanning the whole tenant.
     *
     * @return list<PeopleDuplicateGroup>
     */
    public function checkRecord(People $person): array
    {
        $appId = (int) $person->apps_id;
        $companyId = (int) $person->companies_id;

        $groups = [];
        $groups = array_merge($groups, $this->externalIdConflictForRecord($person, $appId, $companyId));
        $groups = array_merge($groups, $this->exactNameForRecord($person, $appId, $companyId));
        $groups = array_merge($groups, $this->lastNameForRecord($person, $appId, $companyId));
        $groups = array_merge($groups, $this->emailMatchForRecord($person, $appId, $companyId));

        $deduped = [];
        foreach ($groups as $group) {
            $signature = implode('|', $group->member_ids);
            if (! isset($deduped[$signature])) {
                $deduped[$signature] = $group;
            }
        }

        return array_values($deduped);
    }

    /**
     * @return list<PeopleDuplicateGroup>
     */
    private function externalIdConflictForRecord(People $person, int $appId, int $companyId): array
    {
        if (empty($person->lastname)) {
            return [];
        }

        $ownValues = DB::connection('ecosystem')
            ->table('apps_custom_fields')
            ->where('entity_id', $person->id)
            ->where('model_name', People::class)
            ->where('companies_id', $companyId)
            ->where('is_deleted', false)
            ->whereIn('name', ThirdPartyPeopleIdFieldEnum::fieldNames())
            ->pluck('value')
            ->all();

        if (empty($ownValues)) {
            return [];
        }

        $normName = strtolower(trim($person->firstname . ' ' . $person->lastname));

        $sameNameIds = DB::connection('crm')
            ->table('peoples')
            ->where('apps_id', $appId)
            ->where('companies_id', $companyId)
            ->where('is_deleted', false)
            ->where('id', '!=', $person->id)
            ->whereRaw("LOWER(TRIM(CONCAT(firstname, ' ', lastname))) = ?", [$normName])
            ->pluck('id')
            ->all();

        if (empty($sameNameIds)) {
            return [];
        }

        $othersExternalIds = DB::connection('ecosystem')
            ->table('apps_custom_fields')
            ->whereIn('entity_id', $sameNameIds)
            ->where('model_name', People::class)
            ->where('companies_id', $companyId)
            ->where('is_deleted', false)
            ->whereIn('name', ThirdPartyPeopleIdFieldEnum::fieldNames())
            ->get(['entity_id', 'value']);

        $conflictIds = [];
        foreach ($othersExternalIds as $row) {
            if (! in_array($row->value, $ownValues, true)) {
                $conflictIds[] = (int) $row->entity_id;
            }
        }
        $conflictIds = array_unique($conflictIds);

        if (empty($conflictIds)) {
            return [];
        }

        $memberIds = array_map('intval', array_merge([$person->id], $conflictIds));
        sort($memberIds);

        return [new PeopleDuplicateGroup(
            canonical_id: $memberIds[0],
            member_ids: $memberIds,
            reason: 'external_id_conflict',
            sample_name: trim($person->firstname . ' ' . $person->lastname),
        )];
    }

    /**
     * @return list<PeopleDuplicateGroup>
     */
    private function exactNameForRecord(People $person, int $appId, int $companyId): array
    {
        if (empty($person->lastname)) {
            return [];
        }

        $normName = strtolower(trim($person->firstname . ' ' . $person->lastname));

        $matchIds = DB::connection('crm')
            ->table('peoples')
            ->where('apps_id', $appId)
            ->where('companies_id', $companyId)
            ->where('is_deleted', false)
            ->where('id', '!=', $person->id)
            ->whereRaw("LOWER(TRIM(CONCAT(firstname, ' ', lastname))) = ?", [$normName])
            ->pluck('id')
            ->all();

        if (empty($matchIds)) {
            return [];
        }

        $memberIds = array_map('intval', array_merge([$person->id], $matchIds));
        sort($memberIds);

        return [new PeopleDuplicateGroup(
            canonical_id: $memberIds[0],
            member_ids: $memberIds,
            reason: 'exact_name',
            sample_name: trim($person->firstname . ' ' . $person->lastname),
        )];
    }

    /**
     * @return list<PeopleDuplicateGroup>
     */
    private function lastNameForRecord(People $person, int $appId, int $companyId): array
    {
        if (empty($person->lastname)) {
            return [];
        }

        $normLastname = strtolower(trim($person->lastname));

        $matchIds = DB::connection('crm')
            ->table('peoples')
            ->where('apps_id', $appId)
            ->where('companies_id', $companyId)
            ->where('is_deleted', false)
            ->where('id', '!=', $person->id)
            ->whereRaw('LOWER(TRIM(lastname)) = ?', [$normLastname])
            ->pluck('id')
            ->all();

        if (empty($matchIds)) {
            return [];
        }

        $memberIds = array_map('intval', array_merge([$person->id], $matchIds));
        sort($memberIds);

        return [new PeopleDuplicateGroup(
            canonical_id: $memberIds[0],
            member_ids: $memberIds,
            reason: 'lastname_match',
            sample_name: trim($person->firstname . ' ' . $person->lastname),
        )];
    }

    /**
     * @return list<PeopleDuplicateGroup>
     */
    private function emailMatchForRecord(People $person, int $appId, int $companyId): array
    {
        $emailTypeIds = [
            ContactTypeEnum::EMAIL->value,
            ContactTypeEnum::PRIMARY_EMAIL->value,
            ContactTypeEnum::SECONDARY_EMAIL->value,
        ];

        $ownEmails = DB::connection('crm')
            ->table('peoples_contacts')
            ->where('peoples_id', $person->id)
            ->whereIn('contacts_types_id', $emailTypeIds)
            ->where('is_deleted', false)
            ->pluck('value')
            ->map(fn ($value) => strtolower(trim((string) $value)))
            ->filter()
            ->unique();

        if ($ownEmails->isEmpty()) {
            return [];
        }

        $out = [];
        foreach ($ownEmails as $email) {
            $matchIds = DB::connection('crm')
                ->table('peoples_contacts')
                ->join('peoples', 'peoples.id', '=', 'peoples_contacts.peoples_id')
                ->whereIn('peoples_contacts.contacts_types_id', $emailTypeIds)
                ->where('peoples_contacts.is_deleted', false)
                ->where('peoples.apps_id', $appId)
                ->where('peoples.companies_id', $companyId)
                ->where('peoples.is_deleted', false)
                ->where('peoples.id', '!=', $person->id)
                ->whereRaw('LOWER(TRIM(peoples_contacts.value)) = ?', [$email])
                ->pluck('peoples.id')
                ->unique()
                ->all();

            if (empty($matchIds)) {
                continue;
            }

            $memberIds = array_map('intval', array_merge([$person->id], $matchIds));
            sort($memberIds);

            $out[] = new PeopleDuplicateGroup(
                canonical_id: $memberIds[0],
                member_ids: $memberIds,
                reason: 'email_match',
                sample_name: trim($person->firstname . ' ' . $person->lastname),
            );
        }

        return $out;
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
