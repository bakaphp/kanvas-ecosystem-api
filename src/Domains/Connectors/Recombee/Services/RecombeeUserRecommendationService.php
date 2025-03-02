<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Recombee\Services;

use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Connectors\Recombee\Client;
use Kanvas\Connectors\Recombee\Enums\ConfigurationEnum;
use Kanvas\Connectors\Recombee\Enums\CustomFieldEnum;
use Recombee\RecommApi\Client as RecommApiClient;
use Recombee\RecommApi\Requests\RecommendItemsToUser;
use Recombee\RecommApi\Requests\RecommendNextItems;
use Recombee\RecommApi\Requests\RecommendUsersToUser;
use Throwable;

class RecombeeUserRecommendationService
{
    protected RecommApiClient $client;

    public function __construct(
        protected AppInterface $app,
        protected ?string $recombeeDatabase = null,
        protected ?string $recombeeApiKey = null,
        protected ?string $recombeeRegion = null
    ) {
        $this->client = (new Client(
            $app,
            $recombeeDatabase,
            $recombeeApiKey,
            $recombeeRegion
        ))->getClient();
    }

    public function getUserForYouFeed(UserInterface $user, int $count = 100, string $scenario = 'for-you-feed', ?string $recommId = null): array
    {
        $recommendationOptions = [
            'rotationRate' => 0.0,
            // Uncomment when ready to use configuration
            // 'rotationTime' => $this->config->get(ConfigurationEnum::RECOMBEE_ROTATION_TIME->value, 7200.0),
        ];

        try {
            if ($recommId !== null) {
                return $this->getUserForYouFeedPagination($user, $recommId, $count);
            }

            return $this->getUserRecommendation($user, $count, $scenario, $recommendationOptions);
        } catch (Throwable $e) {
            return $this->getUserRecommendation($user, $count, $scenario, $recommendationOptions);
        }
    }

    public function getUserForYouFeedPagination(UserInterface $user, string $recommId, int $limit): array
    {
        return $this->client->send(
            new RecommendNextItems($recommId, $limit)
        );
    }

    public function getUserRecommendation(
        UserInterface $user,
        int $count = 100,
        string $scenario = 'for-you-feed',
        array $additionalOptions = []
    ): array {
        $options = array_merge([
            'scenario' => $scenario,
            'cascadeCreate' => true,
            //'filter' => "not ('itemId' in  user_interactions(context_user[\"userId\"], {\"detail_views\",\"ratings\"})) ",
        ], $additionalOptions);

        $recommendation = $this->client->send(
            new RecommendItemsToUser((string) $user->getId(), $count, $options)
        );

        $user->set(
            CustomFieldEnum::USER_FOR_YOU_FEED_RECOMM_ID->value,
            $recommendation['recommId']
        );

        return $recommendation;
    }

    public function getUserToUserRecommendation(
        UserInterface $user,
        int $count = 10,
        string $scenario = 'user-follow-suggestion-similar-interests',
        array $additionalOptions = []
    ): array {
        $options = array_merge([
            'scenario' => $scenario,
            'cascadeCreate' => true,
            //'filter' => "not ('itemId' in  user_interactions(context_user[\"userId\"], {\"detail_views\",\"ratings\"})) ",
        ], $additionalOptions);

        $recommendation = $this->client->send(
            new RecommendUsersToUser((string) $user->getId(), $count, $options)
        );

        $user->set(
            CustomFieldEnum::USER_WHO_TO_FOLLOW_RECOMM_ID->value,
            (string) $recommendation['recommId']
        );

        return $recommendation;
    }
}
