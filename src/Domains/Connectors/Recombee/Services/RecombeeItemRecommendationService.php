<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Recombee\Services;

use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Connectors\Recombee\Client;
use Recombee\RecommApi\Client as RecommApiClient;
use Recombee\RecommApi\Requests\RecommendItemsToItem;

class RecombeeItemRecommendationService
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

    public function getItemRecommendation(
        UserInterface $user,
        Model $item,
        int $count = 25,
        string $scenario = 'for-you-feed',
        array $additionalOptions = []
    ): array {
        $options = array_merge([
            'scenario' => $scenario,
            'cascadeCreate' => true,
            'returnProperties' => true,
            //'filter' => "not ('itemId' in  user_interactions(context_user[\"userId\"], {\"detail_views\",\"ratings\"})) ",
        ], $additionalOptions);

        $recommendation = $this->client->send(
            new RecommendItemsToItem((string) $item->getKey(), (string) $user->getId(), $count, $options)
        );

        return $recommendation;
    }
}
