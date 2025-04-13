<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Recombee\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Database\Eloquent\Builder;
use Kanvas\Connectors\Recombee\Services\RecombeeUserRecommendationService;
use Kanvas\Users\Models\Users;
use Kanvas\Connectors\Recombee\Enums\ScenariosEnum;

class GenerateWhoToFollowRecommendationsAction
{
    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company
    ) {
    }

    public function execute(UserInterface $user, int $pageSize = 10, string $scenario = ScenariosEnum::USER_FOLLOW_SUGGESTIONS_SIMILAR_INTERESTS->value): Builder
    {
        $recommendationService = new RecombeeUserRecommendationService($this->app);

        $response = $recommendationService->getUserToUserRecommendation($user, $pageSize, $scenario);

        $entityIds = collect($response['recomms'])
            ->pluck('id')
            ->unique()
            ->filter()
            ->toArray();

        return Users::query()
                ->whereIn('id', $entityIds)
                ->where('is_deleted', 0);
    }
}
