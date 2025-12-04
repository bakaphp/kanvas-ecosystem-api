<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Recombee\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Exception;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Kanvas\Workflow\Enums\WorkflowEnum;

class PopulateTrendingFeedAction
{
    protected UserInterface $user;

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company,
        protected MessageType $messageType,
        protected bool $cleanUserFeed = false
    ) {
        $this->user = $this->company->user;
    }

    public function execute(int $pageSize = 350): int
    {
        $minTrendingScore = $this->app->get('trending_feed_min_score', 100);
        $timePeriod = $this->app->get('trending_feed_days_time_period', 30);
        $likesWeight = $this->app->get('trending_feed_likes_weight', 2);
        $remixWeight = $this->app->get('trending_feed_remix_weight', 1.5);
        $sharedWeight = $this->app->get('trending_feed_shared_weight', 1);
        // $recommendationService = new RecombeeUserRecommendationService($this->app);
        $trendingSlug = 'trending';
        // $userForYouFeed = $recommendationService->getUserRecommendation($this->user, $pageSize, $trendingSlug)['recomms']; // This does not make sense to use for trending


        Message::fromApp($this->app)->whereHas('tags', function ($query) use ($trendingSlug) {
            $query->where('slug', $trendingSlug)
                ->where('messages.is_public', 0)
                ->where('messages.is_deleted', 0);
        })->get()->each(function ($message) use ($trendingSlug) {
            $message->removeTag($trendingSlug);
        });

        $trendingMessages = Message::fromApp($this->app)
            ->where('is_public', 1)
            ->where('is_deleted', 0)
            ->where('created_at', '>=', now()->subDays($timePeriod))
            ->where('message_types_id', $this->messageType->getId())
            ->selectRaw(
                'messages.*, 
        (total_liked * ? + total_shared * ? + (total_children - 1) * ? 
        + (? - DATEDIFF(NOW(), created_at)) * 0.5) as trending_score',
                [$likesWeight, $sharedWeight, $remixWeight, $timePeriod]
            )
            ->having('trending_score', '>=', $minTrendingScore)
            ->orderBy('trending_score', 'desc')
            ->get();
        foreach ($trendingMessages as $message) {
            try {
                $message->addTag($trendingSlug, $this->app, $this->user, $this->company);
                $message->fireWorkflow(WorkflowEnum::UPDATED->value, true, ['app' => $message->app]);
                //print_r('Added trending tag to message ID: ' . $message->getId() . PHP_EOL);
            } catch (Exception $e) {
                continue;
            }
        }

        return count($trendingMessages);
    }
}
