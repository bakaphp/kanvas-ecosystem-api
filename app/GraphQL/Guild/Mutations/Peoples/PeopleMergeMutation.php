<?php

declare(strict_types=1);

namespace App\GraphQL\Guild\Mutations\Peoples;

use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Actions\MergePeopleAction;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Customers\Services\FindPeopleDuplicatesService;

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

        $groups = new FindPeopleDuplicatesService()->generate(
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
