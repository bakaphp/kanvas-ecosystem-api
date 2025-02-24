<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Recombee\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Exception;
use Kanvas\Connectors\Recombee\Services\RecombeeUserRecommendationService;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Connectors\Recombee\Enums\ConfigurationEnum;

class PopulateTrendingFeedAction
{
    protected UserInterface $user;

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company,
        protected bool $cleanUserFeed = false
    ) {
        $this->user = $this->company->user;
    }

    public function execute(int $pageSize = 350): int
    {
        $recommendationService = new RecombeeUserRecommendationService(
            $this->app,
            $this->app->get(ConfigurationEnum::RECOMBEE_DATABASE->value),
            $this->app->get(ConfigurationEnum::RECOMBEE_API_KEY->value)
        );
        $trendingSlug = 'trending';
        $userForYouFeed = $recommendationService->getUserRecommendation($this->user, $pageSize, $trendingSlug);

        Message::fromApp($this->app)->whereHas('tags', function ($query) use ($trendingSlug) {
            $query->where('slug', $trendingSlug);
        })->get()->each(function ($message) use ($trendingSlug) {
            $message->removeTag($trendingSlug);
        });

        foreach ($userForYouFeed as $index => $messageId) {
            $messageId = $messageId['id'];

            try {
                $message = Message::getById($messageId, $this->app);
                $message->addTag($trendingSlug, $this->app, $this->user, $this->company);
            } catch (Exception $e) {
                continue;
            }
        }

        return count($userForYouFeed);
    }
}
