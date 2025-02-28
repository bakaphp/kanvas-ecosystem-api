<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Recombee\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Database\Eloquent\Builder;
use Kanvas\Connectors\Recombee\Services\RecombeeUserRecommendationService;
use Kanvas\Users\Models\Users;

class GenerateRecommendUsersToFollowAction
{
    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company,
        protected UserInterface $user
    ) {
    }

    public function execute(int $pageSize = 10): Builder
    {
        $recommendationService = new RecombeeUserRecommendationService($this->app);

        $response = $recommendationService->getUserToUserRecommendation($this->user, $pageSize, 'user-follow-suggestion-similar-interests');

        $entityIds = collect($response)
            ->pluck('id')
            ->unique()
            ->filter()
            ->toArray();

        return Users::query()
                ->whereIn('id', $entityIds)
                ->where('is_deleted', 0);
    }
}
