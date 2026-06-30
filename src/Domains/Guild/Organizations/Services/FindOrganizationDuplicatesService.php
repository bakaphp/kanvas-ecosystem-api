<?php

declare(strict_types=1);

namespace Kanvas\Guild\Organizations\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Kanvas\Guild\Organizations\DataTransferObject\OrganizationDuplicateGroup;

/**
 * Returns groups of Organizations that are likely duplicates within a single tenant, so an
 * operator can merge them in batches via `MergeOrganizationsAction`.
 *
 * Two SQL dimensions, run independently:
 *   1. exact_name  — GROUP BY LOWER(TRIM(name)) HAVING COUNT > 1
 *   2. email_match — GROUP BY LOWER(TRIM(email)) HAVING COUNT > 1   (organizations.email column)
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
            $this->groupsByExactName($appId, $companyId),
        );
        $groups = array_merge(
            $groups,
            $this->groupsByEmailMatch($appId, $companyId),
        );

        // Stable dedup: an Org can appear in multiple groups (same name AND same email). For each
        // member-set signature, keep only the first reason that surfaced it.
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
