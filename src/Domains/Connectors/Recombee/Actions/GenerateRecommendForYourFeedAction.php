<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Recombee\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Kanvas\Connectors\Recombee\Enums\ConfigurationEnum;
use Kanvas\Connectors\Recombee\Enums\CustomFieldEnum;
use Kanvas\Connectors\Recombee\Services\RecombeeUserRecommendationService;
use Kanvas\Connectors\Recombee\Traits\HasChronologicalFeed;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\Messages\Models\UserMessage;

class GenerateRecommendForYourFeedAction
{
    use HasChronologicalFeed;

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company
    ) {
    }

    public function execute(
        UserInterface $user,
        int $page = 1,
        int $pageSize = 25,
        string $scenario = 'for-you-feed'
    ): LengthAwarePaginator {
        $messageTypeId = $this->app->get('social-user-message-filter-message-type');
        $totalRecords = $this->app->get('social-user-message-filter-total-records') ?? 500;

        if ((bool) $this->app->get('social-user-message-filter-chronological-order')) {
            return $this->chronologicalFeed(
                $this->app,
                $page,
                $pageSize,
                $totalRecords,
                $messageTypeId
            );
        }

        $recommendationService = new RecombeeUserRecommendationService($this->app);

        if ($page > 1) {
            $recommendationId = $user->get(CustomFieldEnum::USER_FOR_YOU_FEED_RECOMM_ID->value);
            $response = $recommendationService->getUserForYouFeed(
                user: $user,
                count: $pageSize,
                recommId: $recommendationId,
                scenario: $scenario
            );
        } else {
            $response = $recommendationService->getUserForYouFeed($user, $pageSize, $scenario);
            if (empty($response['recomms'])) {
                // you've seen it all? wtf , well lets go to fallback trending
                $response = $recommendationService->getUserForYouFeed($user, $pageSize, ConfigurationEnum::TRENDING_SCENARIO->value);
            }
        }

        $recommendation = $response['recomms'];
        //$recommendationId = $response['recommId'];
        // $user->set(CustomFieldEnum::USER_FOR_YOU_FEED_RECOMM_ID->value, $recommendationId);

        $entityIds = collect($recommendation)
            ->pluck('id')
            ->unique()
            ->filter()
            ->toArray();

        if (empty($entityIds)) {
            return new LengthAwarePaginator(
                collect([]),
                0,
                $pageSize,
                $page
            );
            /* return new LengthAwarePaginator(
                UserMessage::getForYouFeed($user, $this->app)->forPage($page, $pageSize)->get(),
                $totalRecords,
                $pageSize,
                $page
            ); */
        }

        $placeholders = implode(',', array_fill(0, count($entityIds), '?'));
        $builder = Message::fromApp($this->app)
            ->whereIn('id', $entityIds)
            ->where('is_public', 1)
            ->where('is_deleted', 0)
            ->when($messageTypeId !== null, function (Builder $query) use ($messageTypeId): Builder {
                return $query->where('messages.message_types_id', $messageTypeId);
            })
            ->orderByRaw("FIELD(id, {$placeholders})", array_map('intval', $entityIds));

        return new LengthAwarePaginator(
            $builder->get(),
            $totalRecords,
            $pageSize,
            $page
        );
    }
}
