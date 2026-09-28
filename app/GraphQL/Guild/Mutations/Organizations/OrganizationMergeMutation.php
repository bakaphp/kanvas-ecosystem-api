<?php

declare(strict_types=1);

namespace App\GraphQL\Guild\Mutations\Organizations;

use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Duplicates\Actions\UpsertDuplicateReviewGroupAction;
use Kanvas\Guild\Duplicates\Enums\DuplicateReviewStatusEnum;
use Kanvas\Guild\Duplicates\Models\DuplicateReviewGroup;
use Kanvas\Guild\Organizations\Actions\MergeOrganizationsAction;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Guild\Organizations\Services\FindOrganizationDuplicatesService;

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

        $groups = DuplicateReviewGroup::query()
            ->where('apps_id', $app->getId())
            ->where('companies_id', $company->getId())
            ->where('entity_type', Organization::class)
            ->where('status', DuplicateReviewStatusEnum::PENDING->value)
            ->where('is_deleted', false)
            ->orderByDesc('id')
            ->limit((int) ($request['max_groups'] ?? 100))
            ->get();

        return $groups->map(fn (DuplicateReviewGroup $group): array => [
            'canonical_id' => $group->canonical_id,
            'member_ids' => $group->member_ids,
            'reason' => $group->reason,
            'sample_name' => $this->sampleNameFor($group->canonical_id),
        ])->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function detectDuplicates(mixed $rootValue, array $request): array
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $groups = new FindOrganizationDuplicatesService()->generate(
            app: $app,
            company: $company,
            maxGroups: (int) ($request['max_groups'] ?? 100),
        );

        $upserter = new UpsertDuplicateReviewGroupAction(Organization::class, $app->getId(), $company->getId());
        foreach ($groups as $group) {
            $upserter->execute($group);
        }

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

    private function sampleNameFor(int $organizationId): string
    {
        $organization = Organization::query()->where('id', $organizationId)->first();

        return $organization?->name ?? '';
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
