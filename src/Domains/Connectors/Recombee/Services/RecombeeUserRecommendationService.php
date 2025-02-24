<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Recombee\Services;

use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Connectors\Recombee\Client;
use Kanvas\Connectors\Recombee\Enums\ConfigurationEnum;
use Recombee\RecommApi\Client as RecommApiClient;
use Recombee\RecommApi\Requests\GetUserValues;
use Recombee\RecommApi\Requests\RecommendItemsToUser;
use Recombee\RecommApi\Requests\AddRating;

class RecombeeUserRecommendationService
{
    protected RecommApiClient $client;

    public function __construct(protected AppInterface $app, protected string $recombeeDatabase, protected string $recombeeApiKey, $recombeeRegion = 'ca-east')
    {
        $this->client = (new Client(
            $app,
            $recombeeDatabase,
            $recombeeApiKey,
            $recombeeRegion
        ))->getClient();
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

    public function getUsersFromSimilarInterestByFollow(UserInterface $user, int $count = 100, string $scenario = '​user-folllow-suggetion-similar-interest'): array
    {
        $userData = $this->client->send(new GetUserValues($user->getId()));

        $likedCategories = $userData['liked_categories'] ?? [];

        if (empty($likedCategories)) {
            return [];
        }

        foreach ($likedCategories as $category) {
            $conditions[] = sprintf('"%s" IN \'entity_liked_categories\'', $category);
        }
        $filter = implode(" OR ", $conditions);

        $filter = sprintf(
            '\'entity_id\' != %d AND \'users_id\' != %d AND (%s)',
            $user->getId(),
            $user->getId(),
            $filter
        );

        $options = [
            'scenario' => $scenario,
            'filter' => $filter,
            'returnProperties' => true,
        ];

        return $this->client->send(new RecommendItemsToUser($user->getId(), $count, $options))['recomms'] ?? [];
    }

    public function getUsersFromSimilarPostsCategories(UserInterface $user, int $count = 100, string $scenario = 'user-folllow-suggetion-similar-posts-categories'): array
    {
        $userData = $this->client->send(new GetUserValues($user->getId()));

        $likedCategories = $userData['liked_categories'] ?? [];

        foreach ($likedCategories as $category) {
            $conditions[] = sprintf('"%s" IN \'entity_messages_posts_categories\'', $category);
        }
        $filter = implode(" OR ", $conditions);

        $filter = sprintf(
            '\'entity_id\' != %d AND \'users_id\' != %d AND (%s)',
            $user->getId(),
            $user->getId(),
            $filter
        );

        $options = [
            'scenario' => $scenario,
            'filter' => $filter,
            'returnProperties' => true,
        ];

        return $this->client->send(new RecommendItemsToUser($user->getId(), $count, $options))['recomms'] ?? [];
    }

    public function addRatingToItemFromUser(UserInterface $user, int $itemId, float $rating): string
    {
        return $this->client->send(new AddRating($user->getId(), $itemId, $rating));
    }
}
