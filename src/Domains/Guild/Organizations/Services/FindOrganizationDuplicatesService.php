<?php

declare(strict_types=1);

namespace Kanvas\Guild\Organizations\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Kanvas\Connectors\Contracts\Enums\ThirdPartyOrganizationIdFieldEnum;
use Kanvas\Guild\Organizations\DataTransferObject\OrganizationDuplicateGroup;
use Kanvas\Guild\Organizations\Models\Organization;

/**
 * Returns groups of Organizations that are likely duplicates within a single tenant, so an
 * operator can merge them in batches via `MergeOrganizationsAction`.
 *
 * Dimensions, run independently, in descending order of confidence:
 *   1. external_id_conflict — same exact name, but each member carries its OWN distinct
 *                             third-party id (Salesforce Account Id, Intras Company Id, etc.) —
 *                             the only reason an agent may auto-merge without human approval.
 *   2. exact_name           — GROUP BY LOWER(TRIM(name)) HAVING COUNT > 1
 *   3. email_match          — GROUP BY LOWER(TRIM(email)) HAVING COUNT > 1 (organizations.email)
 *
 * Each dimension's HAVING-clause queries are O(n) in the number of Organizations. We do NOT do
 * O(n²) fuzzy name matching here — the ingest-time vendor resolver already does that and
 * collapses obvious near-misses before they create duplicates. This service exists for the cases
 * that slipped through (same name spelled differently across imports, same email but renamed Org,
 * tax-id collision, etc.).
 *
 * Returns at most `$maxGroups` groups (default 100). Operator UI calls this on demand, not on
 * every page render.
 */
class FindOrganizationDuplicatesService
{
    /**
     * Trailing legal-entity suffixes stripped before grouping. POSIX/ICU regex for MySQL 8's
     * REGEXP_REPLACE — `i` match-type makes it case-insensitive; the column's accent-insensitive
     * collation folds accents on top.
     */
    private const string LEGAL_SUFFIX_PATTERN = '[[:space:],]+(s\\.?\\s?a\\.?|srl|sas|eirl|llc|inc\\.?|corp\\.?)\\.?\\s*$';

    public function generate(
        AppInterface $app,
        CompanyInterface $company,
        int $maxGroups = 100,
    ): array {
        $appId = $app->getId();
        $companyId = $company->getId();

        $groups = [];

        $groups = array_merge(
            $groups,
            $this->groupsByExternalIdConflict($appId, $companyId),
        );
        $groups = array_merge(
            $groups,
            $this->groupsByExactName($appId, $companyId),
        );
        $groups = array_merge(
            $groups,
            $this->groupsByEmailMatch($appId, $companyId),
        );

        // Stable dedup: an Org can appear in multiple groups (same name AND same email). Dimensions
        // are merged above in descending confidence order, so external_id_conflict wins ties.
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
     * Same 3 dimensions as generate() (normalized_name excluded — it's opt-in via
     * generateByNormalizedName), scoped to a single record instead of a full-table GROUP BY.
     *
     * @return list<OrganizationDuplicateGroup>
     */
    public function checkRecord(Organization $organization): array
    {
        $appId = (int) $organization->apps_id;
        $companyId = (int) $organization->companies_id;

        $groups = [];
        $groups = array_merge($groups, $this->externalIdConflictForRecord($organization, $appId, $companyId));
        $groups = array_merge($groups, $this->exactNameForRecord($organization, $appId, $companyId));
        $groups = array_merge($groups, $this->emailMatchForRecord($organization, $appId, $companyId));

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
     * @return list<OrganizationDuplicateGroup>
     */
    private function externalIdConflictForRecord(Organization $organization, int $appId, int $companyId): array
    {
        if (empty($organization->name)) {
            return [];
        }

        $ownValues = DB::connection('ecosystem')
            ->table('apps_custom_fields')
            ->where('entity_id', $organization->id)
            ->where('model_name', Organization::class)
            ->where('companies_id', $companyId)
            ->where('is_deleted', false)
            ->whereIn('name', ThirdPartyOrganizationIdFieldEnum::fieldNames())
            ->pluck('value')
            ->all();

        if (empty($ownValues)) {
            return [];
        }

        $normName = strtolower(trim($organization->name));

        $sameNameIds = DB::connection('crm')
            ->table('organizations')
            ->where('apps_id', $appId)
            ->where('companies_id', $companyId)
            ->where('is_deleted', false)
            ->where('id', '!=', $organization->id)
            ->whereRaw('LOWER(TRIM(name)) = ?', [$normName])
            ->pluck('id')
            ->all();

        if (empty($sameNameIds)) {
            return [];
        }

        $othersExternalIds = DB::connection('ecosystem')
            ->table('apps_custom_fields')
            ->whereIn('entity_id', $sameNameIds)
            ->where('model_name', Organization::class)
            ->where('companies_id', $companyId)
            ->where('is_deleted', false)
            ->whereIn('name', ThirdPartyOrganizationIdFieldEnum::fieldNames())
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

        $memberIds = array_map('intval', array_merge([$organization->id], $conflictIds));
        sort($memberIds);

        return [new OrganizationDuplicateGroup(
            canonical_id: $memberIds[0],
            member_ids: $memberIds,
            reason: 'external_id_conflict',
            sample_name: $organization->name,
        )];
    }

    /**
     * @return list<OrganizationDuplicateGroup>
     */
    private function exactNameForRecord(Organization $organization, int $appId, int $companyId): array
    {
        if (empty($organization->name)) {
            return [];
        }

        $normName = strtolower(trim($organization->name));

        $matchIds = DB::connection('crm')
            ->table('organizations')
            ->where('apps_id', $appId)
            ->where('companies_id', $companyId)
            ->where('is_deleted', false)
            ->where('id', '!=', $organization->id)
            ->whereRaw('LOWER(TRIM(name)) = ?', [$normName])
            ->pluck('id')
            ->all();

        if (empty($matchIds)) {
            return [];
        }

        $memberIds = array_map('intval', array_merge([$organization->id], $matchIds));
        sort($memberIds);

        return [new OrganizationDuplicateGroup(
            canonical_id: $memberIds[0],
            member_ids: $memberIds,
            reason: 'exact_name',
            sample_name: $organization->name,
        )];
    }

    /**
     * @return list<OrganizationDuplicateGroup>
     */
    private function emailMatchForRecord(Organization $organization, int $appId, int $companyId): array
    {
        if (empty($organization->email)) {
            return [];
        }

        $normEmail = strtolower(trim($organization->email));

        $matchIds = DB::connection('crm')
            ->table('organizations')
            ->where('apps_id', $appId)
            ->where('companies_id', $companyId)
            ->where('is_deleted', false)
            ->where('id', '!=', $organization->id)
            ->whereRaw('LOWER(TRIM(email)) = ?', [$normEmail])
            ->pluck('id')
            ->all();

        if (empty($matchIds)) {
            return [];
        }

        $memberIds = array_map('intval', array_merge([$organization->id], $matchIds));
        sort($memberIds);

        return [new OrganizationDuplicateGroup(
            canonical_id: $memberIds[0],
            member_ids: $memberIds,
            reason: 'email_match',
            sample_name: $organization->name,
        )];
    }

    /**
     * Groups by name that is identical AFTER stripping legal suffixes + casing + accents — so
     * "Leaderville" / "LEADERVILLE SRL" collapse to one group. Conservative on purpose: only
     * post-normalization-identical names group (no fuzzy/prefix matching, so "Alpha Industries"
     * and "Alpha Consulting" stay apart). This subsumes the exact-name dimension.
     *
     * @return list<OrganizationDuplicateGroup>
     */
    public function generateByNormalizedName(
        AppInterface $app,
        CompanyInterface $company,
        int $maxGroups = 5000,
    ): array {
        return array_slice(
            $this->groupsByNormalizedName($app->getId(), $company->getId()),
            0,
            $maxGroups,
        );
    }

    /**
     * @return list<OrganizationDuplicateGroup>
     */
    private function groupsByNormalizedName(int $appId, int $companyId): array
    {
        $rows = DB::connection('crm')
            ->table('organizations')
            ->selectRaw(
                "LOWER(TRIM(REGEXP_REPLACE(name, ?, '', 1, 0, 'i'))) as norm_name, GROUP_CONCAT(id ORDER BY id ASC) as ids, MIN(name) as sample_name",
                [self::LEGAL_SUFFIX_PATTERN],
            )
            ->where('apps_id', $appId)
            ->where('companies_id', $companyId)
            ->where('is_deleted', false)
            ->whereNotNull('name')
            ->where('name', '!=', '')
            ->groupBy('norm_name')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        return $this->mapRowsToGroups($rows, 'normalized_name');
    }

    /**
     * Two queries instead of a cross-connection JOIN — a JOIN from `crm` straight into
     * `ecosystem`'s apps_custom_fields is pinned to `crm`'s REPEATABLE READ snapshot, so a custom
     * field written earlier in the same transaction (on `ecosystem`) can be invisible to it.
     *
     * @return list<OrganizationDuplicateGroup>
     */
    private function groupsByExternalIdConflict(int $appId, int $companyId): array
    {
        $customFields = DB::connection('ecosystem')
            ->table('apps_custom_fields')
            ->select('entity_id', 'value')
            ->where('companies_id', $companyId)
            ->where('model_name', Organization::class)
            ->where('is_deleted', false)
            ->whereIn('name', ThirdPartyOrganizationIdFieldEnum::fieldNames())
            ->get();

        if ($customFields->isEmpty()) {
            return [];
        }

        $valuesByOrgId = [];
        foreach ($customFields as $row) {
            $valuesByOrgId[(int) $row->entity_id][] = (string) $row->value;
        }

        $organizations = DB::connection('crm')
            ->table('organizations')
            ->select('id', 'name')
            ->whereIn('id', array_keys($valuesByOrgId))
            ->where('apps_id', $appId)
            ->where('companies_id', $companyId)
            ->where('is_deleted', false)
            ->whereNotNull('name')
            ->where('name', '!=', '')
            ->get();

        $byName = [];
        foreach ($organizations as $row) {
            $normName = strtolower(trim($row->name));
            $byName[$normName]['ids'][] = (int) $row->id;
            $byName[$normName]['sample'] = $row->name;
        }

        $out = [];
        foreach ($byName as $data) {
            $ids = $data['ids'];
            if (count($ids) < 2) {
                continue;
            }

            $distinctValues = [];
            foreach ($ids as $id) {
                foreach ($valuesByOrgId[$id] ?? [] as $value) {
                    $distinctValues[$value] = true;
                }
            }
            if (count($distinctValues) < 2) {
                continue;
            }

            sort($ids);
            $out[] = new OrganizationDuplicateGroup(
                canonical_id: $ids[0],
                member_ids: $ids,
                reason: 'external_id_conflict',
                sample_name: $data['sample'],
            );
        }

        return $out;
    }

    /**
     * @return list<OrganizationDuplicateGroup>
     */
    private function groupsByExactName(int $appId, int $companyId): array
    {
        $rows = DB::connection('crm')
            ->table('organizations')
            ->selectRaw('LOWER(TRIM(name)) as norm_name, GROUP_CONCAT(id ORDER BY id ASC) as ids, MIN(name) as sample_name')
            ->where('apps_id', $appId)
            ->where('companies_id', $companyId)
            ->where('is_deleted', false)
            ->whereNotNull('name')
            ->where('name', '!=', '')
            ->groupBy('norm_name')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        return $this->mapRowsToGroups($rows, 'exact_name');
    }

    /**
     * @return list<OrganizationDuplicateGroup>
     */
    private function groupsByEmailMatch(int $appId, int $companyId): array
    {
        // organizations.email is a first-class column — no JOIN to people contacts needed.
        $rows = DB::connection('crm')
            ->table('organizations')
            ->selectRaw('LOWER(TRIM(email)) as norm_email, GROUP_CONCAT(id ORDER BY id ASC) as ids, MIN(name) as sample_name')
            ->where('apps_id', $appId)
            ->where('companies_id', $companyId)
            ->where('is_deleted', false)
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->groupBy('norm_email')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        return $this->mapRowsToGroups($rows, 'email_match');
    }

    /**
     * @param  Collection<int, object>  $rows
     * @return list<OrganizationDuplicateGroup>
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
            $out[] = new OrganizationDuplicateGroup(
                canonical_id: $memberIds[0],
                member_ids: $memberIds,
                reason: $reason,
                sample_name: (string) ($row->sample_name ?? ''),
            );
        }

        return $out;
    }
}
