<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Google\Services;

use Baka\Users\Contracts\UserInterface;
use Google\Cloud\DiscoveryEngine\V1\Client\RecommendationServiceClient;
use Google\Cloud\DiscoveryEngine\V1\RecommendRequest;
use Google\Cloud\DiscoveryEngine\V1\RecommendResponse;
use Google\Cloud\DiscoveryEngine\V1\UserEvent;
use Google\Protobuf\Timestamp;
use Kanvas\Connectors\Google\Enums\UserEventEnum;
use Kanvas\Social\Messages\Models\Message;

class DiscoveryUserRecommendationService extends DiscoveryEngineService
{
    public function getRecommendation(
        UserInterface $user,
        UserEventEnum $userEventEventType,
        int $pageSize = 25,
        bool $validateOnly = false
    ): RecommendResponse {
        $formattedServingConfig = RecommendationServiceClient::servingConfigName(
            $this->googleRecommendationConfig['projectId'],
            $this->googleRecommendationConfig['location'],
            $this->googleRecommendationConfig['dataSource'],
            $this->googleRecommendationConfig['servingConfig']
        );

        // Add timestamp to make each request unique
        $currentTime = new Timestamp();
        $currentTime->setSeconds(time());

        // Prepare the request message.
        $userEvent = (new UserEvent())
            ->setEventType($userEventEventType->value)
            ->setUserPseudoId((string) $user->getId())
            ->setEventTime($currentTime); // Add timestamp

        $request = (new RecommendRequest())
            ->setPageSize($pageSize)
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
