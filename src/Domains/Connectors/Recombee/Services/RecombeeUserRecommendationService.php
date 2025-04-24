<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Recombee\Services;

use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Connectors\Recombee\Client;
use Kanvas\Connectors\Recombee\Enums\ConfigurationEnum;
use Kanvas\Connectors\Recombee\Enums\CustomFieldEnum;
use Kanvas\Connectors\Recombee\Enums\ScenariosEnum;
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

    public function getUserForYouFeed(
        UserInterface $user,
        int $count = 100,
        string $scenario = 'for-you-feed',
        ?string $recommId = null
    ): array {
        $recommendationOptions = [
            'rotationRate' => $this->app->get(ConfigurationEnum::RECOMBEE_ROTATION_RATE->value ?? '0.2'),
            'booster' => $this->getUserSpecificBoosters($user),
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
        string $scenario = ScenariosEnum::USER_FOLLOW_SUGGETIONS_SIMILAR_INTERESTS->value,
        array $additionalOptions = []
    ): array {
        $options = array_merge([
            'scenario' => $scenario,
            'cascadeCreate' => true,
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

    public function getUserSpecificBoosters(UserInterface $user): string
    {
        $booster = '';
        // Get the booster queries from the app settings
        // The format should be: ['preference' => 'booster']
        // booster being the booster rule
        // 1.0 is the default value for the booster, so we need to replace it with the booster rule
        $recombeeUserContentPreferences = $this->app->get('recombee-user-content-preferences-boosters');
        foreach ($recombeeUserContentPreferences as $preference => $boosterRule) {
            if ($user->get($preference)) {
                if (str_contains($booster, '1.0')) {
                    $booster = str_replace('1.0', '('.addslashes($boosterRule).')', $booster);
                    continue;
                }
                $booster .= addslashes($boosterRule);
            }
        }

        return $booster;
    }
}
