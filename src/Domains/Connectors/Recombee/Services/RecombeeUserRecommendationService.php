<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Recombee\Services;

use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Connectors\Recombee\Client;
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
        $options = [
            'scenario' => $scenario,
        ];

        return $this->client->send(new RecommendItemsToUser($user->getId(), $count, $options))['recomms'] ?? [];
    }
}
