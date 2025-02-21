<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Recombee\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Connectors\Recombee\Services\RecombeeUserRecommendationService;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\Messages\Models\UserMessage;
use Kanvas\Connectors\Recombee\Enums\ConfigurationEnum;
use Kanvas\Users\Models\Users;

class RecommendUsersToFollowByInterestsAction
{
    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company,
        protected UserInterface $user,
        protected bool $cleanUserFeed = false
    ) {}

    public function execute(int $pageSize = 350): array
    {
        $recommendationService = new RecombeeUserRecommendationService(
            $this->app,
            $this->app->get(ConfigurationEnum::FOLLOWS_ENGINE_RECOMBEE_DATABASE->value),
            $this->app->get(ConfigurationEnum::FOLLOWS_ENGINE_RECOMBEE_API_KEY->value)
        );

        $response = $recommendationService->getUsersFromSimilarInterestByFollow($this->user, $pageSize, 'user-folllow-suggetion-similar-interests');

        $entityIds = collect($response)
            ->pluck('values.entity_id')
            ->unique()
            ->filter()
            ->toArray();

        if (empty($entityIds)) {
            return [];
        }

        $usersToFollow = Users::whereIn('id', $entityIds)->get();
        return $usersToFollow->toArray();
    }
}
