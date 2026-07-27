<?php

declare(strict_types=1);

namespace App\GraphQL\Guild\Mutations\Organizations;

use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Organizations\Actions\MergeOrganizationsAction;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Guild\Organizations\Services\FindOrganizationDuplicatesService;

/**
 * Resolvers for the duplicate-cleanup pipeline that pairs with the auto-resolve-or-create-vendor
 * flow in `ProposeBillFromPdfAction` / `ProposeExpenseFromPdfAction`.
 *
 * - `merge` collapses two Organizations (source → target), rewriting every Scribe + Guild FK and
 *   soft-deleting the source.
 * - `findDuplicates` surfaces candidate clusters (exact name, shared email, shared tax id) so the
 *   operator can review and merge in batches rather than chasing per-document.
 */
class OrganizationMergeMutation
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function findDuplicates(mixed $rootValue, array $request): array
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $groups = new FindOrganizationDuplicatesService()->generate(
            app: $app,
            company: $company,
            maxGroups: (int) ($request['max_groups'] ?? 100),
        );

        return array_map(
            fn ($group): array => [
                'canonical_id' => $group->canonical_id,
                'member_ids' => $group->member_ids,
                'reason' => $group->reason,
                'sample_name' => $group->sample_name,
            ],
            $groups,
        );
    }

    /**
     * @param  array<string, mixed>  $rootValue
     * @return array<int, Organization>
     */
    public function organizations(array $rootValue): array
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        return Organization::query()
            ->fromApp($app)
            ->fromCompany($company)
            ->whereIn('id', $rootValue['member_ids'])
            ->get()
            ->all();
    }

    public function merge(mixed $rootValue, array $request): Organization
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        /** @var Organization $target */
        $target = Organization::getByIdFromCompanyApp(
            (int) $request['target_id'],
            $company,
            $app,
        );

        foreach ($request['source_ids'] as $sourceId) {
            /** @var Organization $source */
            $source = Organization::getByIdFromCompanyApp((int) $sourceId, $company, $app);

            $target = new MergeOrganizationsAction(
                source: $source,
                target: $target,
                user: $user,
            )->execute();
        }

        return $target;
    }
}
