<?php

declare(strict_types=1);

namespace App\GraphQL\Guild\Mutations\Peoples;

use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Actions\MergePeopleAction;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Customers\Services\FindPeopleDuplicatesService;
use Kanvas\Guild\Duplicates\Actions\UpsertDuplicateReviewGroupAction;
use Kanvas\Guild\Duplicates\Enums\DuplicateReviewStatusEnum;
use Kanvas\Guild\Duplicates\Models\DuplicateReviewGroup;

class PeopleMergeMutation
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
            ->where('entity_type', People::class)
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

        $groups = new FindPeopleDuplicatesService()->generate(
            app: $app,
            company: $company,
            maxGroups: (int) ($request['max_groups'] ?? 100),
        );

        $upserter = new UpsertDuplicateReviewGroupAction(People::class, $app->getId(), $company->getId());
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

    private function sampleNameFor(int $peopleId): string
    {
        $people = People::query()->where('id', $peopleId)->first();

        return $people ? trim($people->firstname . ' ' . $people->lastname) : '';
    }

    /**
     * @param  array<string, mixed>  $rootValue
     * @return array<int, People>
     */
    public function peoples(array $rootValue): array
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        return People::query()
            ->fromApp($app)
            ->fromCompany($company)
            ->whereIn('id', $rootValue['member_ids'])
            ->get()
            ->all();
    }

    public function merge(mixed $rootValue, array $request): People
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        /** @var People $target */
        $target = People::getByIdFromCompanyApp(
            (int) $request['target_id'],
            $company,
            $app,
        );

        foreach ($request['source_ids'] as $sourceId) {
            /** @var People $source */
            $source = People::getByIdFromCompanyApp((int) $sourceId, $company, $app);

            $target = new MergePeopleAction(
                source: $source,
                target: $target,
                user: $user,
            )->execute();
        }

        return $target;
    }
}
