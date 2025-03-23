<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Recombee\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
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

    public function execute(
        UserInterface $user,
        int $page = 1,
        int $pageSize = 25,
        string $scenario = 'for-you-feed'
    ): LengthAwarePaginator {
        // Create a cache key based on user, page, and scenario
        $cacheKey = "feed:{$user->getId()}:{$scenario}:{$page}:{$pageSize}";
        
        // Set cache duration based on scenario
        $cacheDuration = $scenario === 'for-you-feed' 
            ? now()->addSeconds(30) 
            : now()->addMinutes(15);
            
        return Cache::remember($cacheKey, $cacheDuration, function() use ($user, $page, $pageSize, $scenario) {
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
            }
    
            $recommendation = $response['recomms'];
            $recommendationId = $response['recommId'];
            $user->set(CustomFieldEnum::USER_FOR_YOU_FEED_RECOMM_ID->value, $recommendationId);
    
            $entityIds = collect($recommendation)
                ->pluck('id')
                ->unique()
                ->filter()
                ->toArray();
    
            $totalRecords = $this->app->get('social-user-message-filter-total-records') ?? 500;
            if (empty($entityIds)) {
                return new LengthAwarePaginator(
                    UserMessage::getForYouFeed($user, $this->app)->forPage($page, $pageSize)->get(),
                    $totalRecords,
                    $pageSize,
                    $page
                );
            }
    
            $messageTypeId = $this->app->get('social-user-message-filter-message-type');
            $builder = Message::fromApp($this->app)
                ->whereIn('id', $entityIds)
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
        });
    }
    
    /**
     * Clear the cache for a user's feed
     */
    public function clearUserFeedCache(UserInterface $user, ?string $scenario = null): void
    {
        if ($scenario !== null) {
            // Clear specific scenario cache
            $pattern = "feed:{$user->getId()}:{$scenario}:*";
        } else {
            // Clear all scenarios for this user
            $pattern = "feed:{$user->getId()}:*";
        }
        
        // Using Redis to clear by pattern
        $keys = app('redis')->keys(config('cache.prefix') . ':' . $pattern);
        foreach ($keys as $key) {
            Cache::forget(str_replace(config('cache.prefix') . ':', '', $key));
        }
    }
}