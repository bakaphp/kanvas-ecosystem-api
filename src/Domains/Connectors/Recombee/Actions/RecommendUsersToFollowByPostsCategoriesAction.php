<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Recombee\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Connectors\Recombee\Enums\ConfigurationEnum;
use Kanvas\Connectors\Recombee\Services\RecombeeUserRecommendationService;

class RecommendUsersToFollowByPostsCategoriesAction
{
    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company,
        protected UserInterface $user,
        protected bool $cleanUserFeed = false
    ) {
    }

    public function execute(int $pageSize = 350): array
    {
        $recommendationService = new RecombeeUserRecommendationService(
            $this->app,
            $this->app->get(ConfigurationEnum::FOLLOWS_ENGINE_RECOMBEE_DATABASE->value),
            $this->app->get(ConfigurationEnum::FOLLOWS_ENGINE_RECOMBEE_API_KEY->value)
        );

        $response = $recommendationService->getUsersFromSimilarPostsCategories($this->user, $pageSize, 'user-follow-suggestion-similar-interests');

        $entityIds = collect($response)
            ->pluck('values.entity_id')
            ->unique()
            ->filter()
            ->toArray();

        if (empty($entityIds)) {
            return [];
        }

        return $entityIds;
    }
}
