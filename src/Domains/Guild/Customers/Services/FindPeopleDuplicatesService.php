<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Support\Facades\DB;
use Kanvas\Connectors\Contracts\Enums\ThirdPartyPeopleIdFieldEnum;
use Kanvas\Guild\Customers\DataTransferObject\PeopleDuplicateGroup;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Duplicates\Concerns\BuildsDuplicateGroups;

/**
 * Same approach as FindOrganizationDuplicatesService: O(n) GROUP BY queries, no O(n²) fuzzy
 * matching. Operator UI calls this on demand. Returns at most $maxGroups groups (default 100).
 *
 * Dimensions, in descending order of confidence: external_id_conflict (each member has its own
 * distinct third-party id — the only reason an agent may auto-merge without human approval),
 * exact_name, lastname_match (lowest confidence — also catches unrelated people sharing a
 * surname; exists because Salesforce Contacts with no FirstName get firstname fabricated as a
 * copy of lastname by PullPeopleAction, so a real "Andres Pina" never matches exact_name against
 * a fabricated "Pina Pina"), email_match.
 */
class FindPeopleDuplicatesService
{
    use BuildsDuplicateGroups;

    private const array EMAIL_CONTACT_TYPE_IDS = [
        ContactTypeEnum::EMAIL->value,
        ContactTypeEnum::PRIMARY_EMAIL->value,
        ContactTypeEnum::SECONDARY_EMAIL->value,
    ];

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

        return array_slice($this->dedupeGroups($groups), 0, $maxGroups);
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

        $sameNameIds = empty($person->lastname)
            ? []
            : $this->idsMatchingNormalizedName($appId, $companyId, $person->id, $this->normalizedFullName($person));

        $groups = [];
        $groups = array_merge($groups, $this->externalIdConflictForRecord($person, $companyId, $sameNameIds));
        $groups = array_merge(
            $groups,
            $this->groupForRecord(PeopleDuplicateGroup::class, $person->id, $sameNameIds, 'exact_name', $this->displayName($person)),
        );
        $groups = array_merge($groups, $this->lastNameForRecord($person, $appId, $companyId));
        $groups = array_merge($groups, $this->emailMatchForRecord($person, $appId, $companyId));

        return $this->dedupeGroups($groups);
    }

    private function displayName(People $person): string
    {
        return trim($person->firstname . ' ' . $person->lastname);
    }

    private function normalizedFullName(People $person): string
    {
        return strtolower($this->displayName($person));
    }

    private function idsMatchingNormalizedName(int $appId, int $companyId, int $excludeId, string $normName): array
    {
        return DB::connection('crm')
            ->table('peoples')
            ->where('apps_id', $appId)
            ->where('companies_id', $companyId)
            ->where('is_deleted', false)
            ->where('id', '!=', $excludeId)
            ->whereRaw("LOWER(TRIM(CONCAT(firstname, ' ', lastname))) = ?", [$normName])
            ->pluck('id')
            ->all();
    }

    /**
     * @return list<PeopleDuplicateGroup>
     */
    private function externalIdConflictForRecord(People $person, int $companyId, array $sameNameIds): array
    {
        if (empty($sameNameIds)) {
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

        return $this->groupForRecord(
            PeopleDuplicateGroup::class,
            $person->id,
            array_unique($conflictIds),
            'external_id_conflict',
            $this->displayName($person),
        );
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

        return $this->groupForRecord(PeopleDuplicateGroup::class, $person->id, $matchIds, 'lastname_match', $this->displayName($person));
    }

    /**
     * @return list<PeopleDuplicateGroup>
     */
    private function emailMatchForRecord(People $person, int $appId, int $companyId): array
    {
        $ownEmails = DB::connection('crm')
            ->table('peoples_contacts')
            ->where('peoples_id', $person->id)
            ->whereIn('contacts_types_id', self::EMAIL_CONTACT_TYPE_IDS)
            ->where('is_deleted', false)
            ->pluck('value')
            ->map(fn ($value) => strtolower(trim((string) $value)))
            ->filter()
            ->unique();

        if ($ownEmails->isEmpty()) {
            return [];
        }

        $matches = DB::connection('crm')
            ->table('peoples_contacts')
            ->join('peoples', 'peoples.id', '=', 'peoples_contacts.peoples_id')
            ->selectRaw('LOWER(TRIM(peoples_contacts.value)) as norm_email, peoples.id as people_id')
            ->whereIn('peoples_contacts.contacts_types_id', self::EMAIL_CONTACT_TYPE_IDS)
            ->where('peoples_contacts.is_deleted', false)
            ->where('peoples.apps_id', $appId)
            ->where('peoples.companies_id', $companyId)
            ->where('peoples.is_deleted', false)
            ->where('peoples.id', '!=', $person->id)
            ->whereIn(DB::raw('LOWER(TRIM(peoples_contacts.value))'), $ownEmails->all())
            ->get();

        $matchIdsByEmail = [];
        foreach ($matches as $row) {
            $matchIdsByEmail[$row->norm_email][] = (int) $row->people_id;
        }

        $out = [];
        foreach ($matchIdsByEmail as $matchIds) {
            $out = array_merge(
                $out,
                $this->groupForRecord(PeopleDuplicateGroup::class, $person->id, array_unique($matchIds), 'email_match', $this->displayName($person)),
            );
        }

        return $out;
    }

    /**
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

        return $this->mapRowsToGroups(PeopleDuplicateGroup::class, $rows, 'exact_name');
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

        return $this->mapRowsToGroups(PeopleDuplicateGroup::class, $rows, 'lastname_match');
    }

    /**
     * @return list<PeopleDuplicateGroup>
     */
    private function groupsByEmailMatch(int $appId, int $companyId): array
    {
        $rows = DB::connection('crm')
            ->table('peoples_contacts')
            ->join('peoples', 'peoples.id', '=', 'peoples_contacts.peoples_id')
            ->selectRaw(
                'LOWER(TRIM(peoples_contacts.value)) as norm_email, '
                . 'GROUP_CONCAT(DISTINCT peoples.id ORDER BY peoples.id ASC) as ids, '
                . "MIN(CONCAT(peoples.firstname, ' ', peoples.lastname)) as sample_name",
            )
            ->whereIn('peoples_contacts.contacts_types_id', self::EMAIL_CONTACT_TYPE_IDS)
            ->where('peoples_contacts.is_deleted', false)
            ->where('peoples.apps_id', $appId)
            ->where('peoples.companies_id', $companyId)
            ->where('peoples.is_deleted', false)
            ->whereNotNull('peoples_contacts.value')
            ->where('peoples_contacts.value', '!=', '')
            ->groupBy('norm_email')
            ->havingRaw('COUNT(DISTINCT peoples.id) > 1')
            ->get();

        return $this->mapRowsToGroups(PeopleDuplicateGroup::class, $rows, 'email_match');
    }
}
