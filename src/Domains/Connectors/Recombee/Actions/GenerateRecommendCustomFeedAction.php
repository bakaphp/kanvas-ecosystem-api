<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Recombee\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Kanvas\Connectors\Recombee\Enums\ConfigurationEnum;
use Kanvas\Connectors\Recombee\Services\RecombeeUserRecommendationService;
use Kanvas\Social\Messages\Models\Message;

class GenerateRecommendCustomFeedAction
{
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
        $recommendationService = new RecombeeUserRecommendationService($this->app);
        $recommIdName = $scenario . '-recomm-id';

        if ($page > 1) {
            $response = $recommendationService->getUserCustomScenarioRecommendation(
                user: $user,
                count: $pageSize,
                scenario: $scenario
            );
        } else {
            $response = $recommendationService->getUserCustomScenarioRecommendation($user, $pageSize, $scenario);
            if (empty($response['recomms'])) {
                // you've seen it all? wtf , well lets go to fallback trending
                $response = $recommendationService->getUserCustomScenarioRecommendation($user, $pageSize, ConfigurationEnum::TRENDING_SCENARIO->value);
            }
        }

        $recommendation = $response['recomms'];
        $entityIds = collect($recommendation)
            ->pluck('id')
            ->unique()
            ->filter()
            ->toArray();

        $totalRecords = $this->app->get('social-user-message-filter-total-records') ?? 500;
        if (empty($entityIds)) {
            return new LengthAwarePaginator(
                collect([]),
                0,
                $pageSize,
                $page
            );
        }

        $messageTypeId = $this->app->get('social-user-message-filter-message-type');
        $builder = Message::fromApp($this->app)
            ->whereIn('id', $entityIds)
            ->where('is_public', 1)
            ->where('is_deleted', 0)
            ->when($messageTypeId !== null, function ($query) use ($messageTypeId) {
                return $query->where('messages.message_types_id', $messageTypeId);
            })
            ->orderByRaw('FIELD(id, ' . implode(',', $entityIds) . ')');

        return new LengthAwarePaginator(
            $builder->get(),
            $totalRecords,
            $pageSize,
            $page
        );
    }
}
