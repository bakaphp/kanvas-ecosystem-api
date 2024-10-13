<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Google\Services;

use Google\Cloud\DiscoveryEngine\V1\Client\UserEventServiceClient;
use Google\Cloud\DiscoveryEngine\V1\DocumentInfo;
use Google\Cloud\DiscoveryEngine\V1\UserEvent;
use Google\Cloud\DiscoveryEngine\V1\WriteUserEventRequest;
use Google\Protobuf\Timestamp;
use Kanvas\Connectors\Google\Enums\UserEventEnum;
use Kanvas\Social\Interactions\Models\UsersInteractions;
use Kanvas\Social\Messages\Models\Message;

class DiscoveryEngineUserEventService extends DiscoveryEngineService
{
    public function createUserEvent(UsersInteractions $userInteraction): ?WriteUserEventRequest
    {
        $UserEventServiceClient = UserEventServiceClient::dataStoreName(
            $this->googleRecommendationConfig['projectId'],
            $this->googleRecommendationConfig['location'],
            $this->googleRecommendationConfig['dataSource'],
        );

        if (! $userInteraction->entityData()) {
            return null;
        }

        $eventType = UserEventEnum::getEventFromInteraction($userInteraction->interaction);

        if (! $eventType) {
            return null;
        }

        $document = new DocumentInfo();
        $document->setId($userInteraction->entityData()->id);

        $eventTime = new Timestamp();
        $eventTime->fromDateTime($userInteraction->created_at);

        // Prepare the request message.
        $userEvent = (new UserEvent())
            ->setEventType($eventType)
            ->setUserPseudoId((string) $userInteraction->users_id)
            ->setDocuments([$document])
            ->setEventTime($eventTime);

        return (new WriteUserEventRequest())
            ->setParent($UserEventServiceClient)
            ->setUserEvent($userEvent);
    }
}
