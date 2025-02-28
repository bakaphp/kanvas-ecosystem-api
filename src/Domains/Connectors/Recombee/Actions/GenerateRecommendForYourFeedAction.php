<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Recombee\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Database\Eloquent\Builder;
use Kanvas\Connectors\Recombee\Enums\CustomFieldEnum;
use Kanvas\Connectors\Recombee\Services\RecombeeUserRecommendationService;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\Messages\Models\UserMessage;

class GenerateRecommendForYourFeedAction
{
    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company
    ) {
    }

    public function execute(UserInterface $user, int $page = 1, int $pageSize = 25): Builder
    {
        $recommendationService = new RecombeeUserRecommendationService($this->app);

        if ($page > 1) {
            $recommendationId = $user->get(CustomFieldEnum::USER_FOR_YOU_FEED_RECOMM_ID->value);
            $response = $recommendationService->getUserForYouFeed(
                user: $user,
                count: $pageSize,
                recommId: $recommendationId
            );
        } else {
            $response = $recommendationService->getUserForYouFeed($user, $pageSize);
        }

        $recommendation = $response['recomms'];
        $recommendationId = $response['recommId'];
        $user->set(CustomFieldEnum::USER_FOR_YOU_FEED_RECOMM_ID->value, $recommendationId);

        $entityIds = collect($recommendation)
            ->pluck('id')
            ->unique()
            ->filter()
            ->toArray();

        if (empty($entityIds)) {
            return UserMessage::getUserFeed($user, $this->app);
        }

        return Message::fromApp($this->app)
                ->whereIn('id', $entityIds)
                ->where('is_deleted', 0);
    }
}
