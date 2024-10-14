<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Google\Services;

use Baka\Users\Contracts\UserInterface;
use Google\Cloud\DiscoveryEngine\V1\Client\RecommendationServiceClient;
use Google\Cloud\DiscoveryEngine\V1\RecommendRequest;
use Google\Cloud\DiscoveryEngine\V1\RecommendResponse;
use Google\Cloud\DiscoveryEngine\V1\UserEvent;
use Kanvas\Connectors\Google\Enums\UserEventEnum;
use Kanvas\Social\Messages\Models\Message;

class DiscoveryUserRecommendationService extends DiscoveryEngineService
{
    public function getRecommendation(
        UserInterface $user,
        UserEventEnum $userEventEventType,
        bool $validateOnly = false
    ): RecommendResponse {
        $formattedServingConfig = RecommendationServiceClient::servingConfigName(
            $this->googleRecommendationConfig['projectId'],
            $this->googleRecommendationConfig['location'],
            $this->googleRecommendationConfig['dataSource'],
            $this->googleRecommendationConfig['servingConfig']
        );

        // Prepare the request message.
        $userEvent = (new UserEvent())
            ->setEventType($userEventEventType->value)
            ->setUserPseudoId((string) $user->getId());

        $request = (new RecommendRequest())
            ->setServingConfig($formattedServingConfig)
            ->setUserEvent($userEvent)

            /**
             * Recommendation model is not ready. You can set 'validateOnly' to true in RecommendRequest for
             * integration purposes, which will return arbitrary documents from your
             * DataStore (please DO NOT use this for production traffic).
             *  If this is production traffic and you think your model should be in ready state, please contact Google Support
             */
            ->setValidateOnly($validateOnly);

        return $this->client->recommend($request);
    }
}
