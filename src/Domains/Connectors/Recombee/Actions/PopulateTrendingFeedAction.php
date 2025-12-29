<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Recombee\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Exception;
use Kanvas\Connectors\Recombee\Services\RecombeeUserRecommendationService;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Kanvas\Workflow\Enums\WorkflowEnum;

class PopulateTrendingFeedAction
{
    protected UserInterface $user;

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company,
        protected ?MessageType $messageType = null,
        protected bool $useCustomTrending = false,
        protected bool $cleanUserFeed = false
    ) {
        $this->user = $this->company->user;
    }

    public function execute(int $pageSize = 350): int
    {
        $trendingSlug = 'trending';

        $this->cleanExistingTrendingTags($trendingSlug);

        if ($this->useCustomTrending) {
            return $this->executeCustomTrending($trendingSlug);
        }

        return $this->executeRecombeeTrending($trendingSlug, $pageSize);
    }

    protected function cleanExistingTrendingTags(string $trendingSlug): void
    {
        Message::fromApp($this->app)->whereHas('tags', function ($query) use ($trendingSlug) {
            $query->where('slug', $trendingSlug)
                ->where('messages.is_public', 1)
                ->where('messages.is_deleted', 0);
        })->get()->each(function ($message) use ($trendingSlug) {
            $message->removeTag($trendingSlug);
        });
    }

    protected function executeRecombeeTrending(string $trendingSlug, int $pageSize): int
    {
        $recommendationService = new RecombeeUserRecommendationService($this->app);
        $userForYouFeed = $recommendationService->getUserRecommendation($this->user, $pageSize, $trendingSlug)['recomms'];

        foreach ($userForYouFeed as $messageId) {
            $message = Message::fromApp($this->app)
                    ->where('is_public', 1)
                    ->where('is_deleted', 0)
                    ->where('id', $messageId['id'])->first();

            if (! $message) {
                continue;
            }

            $this->addTrendingTag($message, $trendingSlug);
        }

        return count($userForYouFeed);
    }

    protected function executeCustomTrending(string $trendingSlug): int
    {
        $minTrendingScore = $this->app->get('trending_feed_min_score', 100);
        $timePeriod = $this->app->get('trending_feed_days_time_period', 30);
        $likesWeight = $this->app->get('trending_feed_likes_weight', 2);
        $remixWeight = $this->app->get('trending_feed_remix_weight', 1.5);
        $sharedWeight = $this->app->get('trending_feed_shared_weight', 1);

        $query = Message::fromApp($this->app)
            ->where('is_public', 1)
            ->where('is_deleted', 0)
            ->where('created_at', '>=', now()->subDays($timePeriod))
            ->selectRaw(
                'messages.*, 
                (total_liked * ? + total_shared * ? + (total_children - 1) * ? 
                + (? - DATEDIFF(NOW(), created_at)) * 0.5) as trending_score',
                [$likesWeight, $sharedWeight, $remixWeight, $timePeriod]
            )
            ->having('trending_score', '>=', $minTrendingScore)
            ->orderBy('trending_score', 'desc');

        if ($this->messageType) {
            $query->where('message_types_id', $this->messageType->getId());
        }

        $trendingMessages = $query->get();

        foreach ($trendingMessages as $message) {
            $this->addTrendingTag($message, $trendingSlug);
        }

        return count($trendingMessages);
    }

    protected function addTrendingTag(Message $message, string $trendingSlug): void
    {
        try {
            $message->addTag($trendingSlug, $this->app, $this->user, $this->company);
            $message->fireWorkflow(WorkflowEnum::UPDATED->value, true, ['app' => $message->app]);
        } catch (Exception $e) {
            // Skip failed messages
        }
    }
}
