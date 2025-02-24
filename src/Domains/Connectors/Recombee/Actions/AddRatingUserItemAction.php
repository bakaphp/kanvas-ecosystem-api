<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Recombee\Actions;

use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Connectors\Recombee\Services\RecombeeUserRecommendationService;
use Kanvas\Connectors\Recombee\Enums\ConfigurationEnum;

class AddRatingUserItemAction
{
    public function __construct(
        protected AppInterface $app,
        protected UserInterface $user,
        protected int $itemId,
        protected float $rating
    ) {
    }

    public function execute(): string
    {
        $recommendationService = new RecombeeUserRecommendationService(
            $this->app,
            $this->app->get(ConfigurationEnum::FOLLOWS_ENGINE_RECOMBEE_DATABASE->value),
            $this->app->get(ConfigurationEnum::FOLLOWS_ENGINE_RECOMBEE_API_KEY->value)
        );

        $response = $recommendationService->addRatingToItemFromUser($this->user, $this->itemId, $this->rating);

        return $response;
    }
}
