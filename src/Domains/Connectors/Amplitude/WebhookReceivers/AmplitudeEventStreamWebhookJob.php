<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Amplitude\WebhookReceivers;

use Kanvas\Social\Enums\InteractionEnum;
use Kanvas\Social\Interactions\Actions\CreateInteraction;
use Kanvas\Social\Interactions\Actions\CreateUserInteractionAction;
use Kanvas\Social\Interactions\DataTransferObject\Interaction;
use Kanvas\Social\Interactions\DataTransferObject\UserInteraction;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\Messages\Models\UserMessage;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Repositories\UsersRepository;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;

class AmplitudeEventStreamWebhookJob extends ProcessWebhookJob
{
    public function execute(): array
    {
        $payload = $this->webhookRequest->payload;

        //create the user interaction so its uses the workflow to stream to the diff distribution source
        $allowInteractions = [
          'View Explore' => InteractionEnum::VIEW_HOME_PAGE->getValue(),
        ];

        $eventType = $payload['event_type'] ?? null;

        if (! $eventType) {
            return [
              'message' => 'Event type not found',
            ];
        }

        if (! isset($payload['user_id'])) {
            return [
              'message' => 'User not found',
            ];
        }

        $user = Users::getById($payload['user_id']);

        UsersRepository::belongsToThisApp($user, $this->receiver->app);

        if (! isset($allowInteractions[$eventType])) {
            return [
              'message' => 'Event not allowed to be streamed ' . $eventType,
            ];
        }

        $pageNumber = 1; // Replace with the page number you want (starting from 1)
        $userMessages = UserMessage::getFirstMessageFromPage($user, $this->receiver->app, $pageNumber);

        $internalEventName = $allowInteractions[$eventType];
        $interaction = (new CreateInteraction(
            new Interaction(
                $internalEventName,
                $this->receiver->app,
                $internalEventName,
            )
        ))->execute();
        $createUserInteraction = new CreateUserInteractionAction(
            new UserInteraction(
                $user,
                $interaction,
                (string) $userMessages->messages_id,
                Message::class,
            )
        );

        $createUserInteraction->execute(
            allowDuplicate: true,
            addToCache: false
        );

        return [
          'message' => 'Event streamed successfully',
          'event' => $eventType,
          'data' => [
            'user' => $user->getId(),
            'interaction' => $internalEventName,
            'message' => $userMessages->toArray(),
          ],
        ];
    }
}
