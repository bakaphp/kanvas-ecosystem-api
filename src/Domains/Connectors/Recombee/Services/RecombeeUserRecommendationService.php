<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Recombee\Services;

use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Connectors\Recombee\Client;
use Kanvas\Connectors\Recombee\Enums\ConfigurationEnum;
use Recombee\RecommApi\Client as RecommApiClient;
use Recombee\RecommApi\Requests\RecommendItemsToUser;

class RecombeeUserRecommendationService
{
    protected RecommApiClient $client;

    public function __construct(protected AppInterface $app)
    {
        $this->client = (new Client($app))->getClient();
    }

    public function getUserForYouFeed(UserInterface $user, int $count = 100, string $scenario = 'for-you-feed'): array
    {
        return $this->getUserRecommendation($user, $count, $scenario, [
            'rotationRate' => 0.0,
          //  'rotationTime' => $this->app->get(ConfigurationEnum::RECOMBEE_ROTATION_TIME->value) ??  7200.0,
        ]);
    }

    public function getUserRecommendation(
        UserInterface $user,
        int $count = 100,
        string $scenario = 'for-you-feed',
        array $additionalOptions = []
    ): array {
        $options = array_merge([
            'scenario' => $scenario,
            //'filter' => "not ('itemId' in  user_interactions(context_user[\"userId\"], {\"detail_views\",\"ratings\"})) ",
        ], $additionalOptions);

        return $this->client->send(new RecommendItemsToUser($user->getId(), $count, $options))['recomms'] ?? [];
    }
}
