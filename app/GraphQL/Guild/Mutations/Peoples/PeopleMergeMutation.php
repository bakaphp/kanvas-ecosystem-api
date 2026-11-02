<?php

declare(strict_types=1);

namespace App\GraphQL\Guild\Mutations\Peoples;

use App\GraphQL\Concerns\ResolvesActingContext;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Actions\MergePeopleAction;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Customers\Services\PeopleDuplicateQueueService;
use Kanvas\Guild\Duplicates\Models\DuplicateReviewGroup;

class PeopleMergeMutation
{
    use ResolvesActingContext;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findDuplicates(mixed $rootValue, array $request): array
    {
        $ctx = $this->actingContext();

        $groups = new PeopleDuplicateQueueService($ctx->app, $ctx->company)->pending((int) ($request['max_groups'] ?? 100));

        return array_map(
            fn (DuplicateReviewGroup $group): array => [
                'canonical_id' => $group->canonical_id,
                'member_ids' => $group->member_ids,
                'reason' => $group->reason,
                'sample_name' => $this->sampleNameFor($group->canonical_id),
            ],
            $groups,
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function detectDuplicates(mixed $rootValue, array $request): array
    {
        $ctx = $this->actingContext();

        $groups = new PeopleDuplicateQueueService($ctx->app, $ctx->company)->detect((int) ($request['max_groups'] ?? 100));

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
