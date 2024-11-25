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

        /*        {
                   "event_type": "Button Clicked",
                   "event_time": "2022-10-24T20:07:32.123Z",
                   "user_id": "user_one@example.com",
                   "device_id": "device123",
                   "user_properties": {
                     "email": "user_one@example.com"
                     // Additional user properties
                   },
                   "event_properties": {
                     "button_color": "red"
                     // Additional event properties
                   }
                   // Additional event fields
                 } */

        //create the user interaction so its uses the workflow to stream to the diff distribution source
        $allowInteractions = [
          'View Explore' => InteractionEnum::VIEW_HOME_PAGE->getValue(),
        ];
        $eventType = $payload['event_type'];

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

        $userMessages = UserMessage::fromApp($this->receiver->app)->where('users_id', $user->getId())->notDeleted()->orderBy('created_at', 'desc')->first();

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
            allowDuplicate: false,
            addToCache: false
        );

        return [];
    }
}
