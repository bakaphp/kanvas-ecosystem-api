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
use Override;

class AmplitudeEventStreamWebhookJob extends ProcessWebhookJob
{
    #[Override]
    public function execute(): array
    {
        $payload = $this->webhookRequest->payload;

        /**
         * create the user interaction so its uses the workflow to stream to the diff distribution source.
         *
         * @todo make this dynamic , cant be hardcoded
         */
        $allowInteractions = [
            'View Explore'               => InteractionEnum::VIEW_HOME_PAGE->getValue(),
            'Clicking Output Icon'       => InteractionEnum::VIEW_ITEM->getValue(),
            'Page Viewed'                => InteractionEnum::VIEW_ITEM->getValue(),
            'Clicking AI Nugget Preview' => InteractionEnum::VIEW_ITEM->getValue(),
            'Select Prompt'              => InteractionEnum::VIEW_ITEM->getValue(),
        ];

        $eventType = $payload['event_type'] ?? null;
        //$entityIdField = $this->receiver->app->get('amplitude_entity_id_field');
        $entityId = $payload['event_properties']['message_id'] ?? $payload['event_properties']['prompt_id'] ?? 0;

        if ($eventType !== null) {
            return [
                'message' => 'Event type not found',
            ];
        }

        if (!isset($payload['user_id'])) {
            return [
                'message' => 'User not found',
            ];
        }

        $user = Users::getById($payload['user_id']);

        UsersRepository::belongsToThisApp($user, $this->receiver->app);

        if (!isset($allowInteractions[$eventType])) {
            return [
                'message' => 'Event not allowed to be streamed '.$eventType,
            ];
        }

        $pageNumber = 1; // Replace with the page number you want (starting from 1)
        if ((int) $entityId > 0) {
            $userMessages = Message::fromApp($this->receiver->app)
                ->where('id', $entityId)
                ->first();
        } else {
            $userMessages = UserMessage::getFirstMessageFromPage($user, $this->receiver->app, $pageNumber)?->message;
        }

        if ($userMessages === null) {
            return [
                'message' => 'User message not found',
            ];
        }

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
                (string) $userMessages->getId(),
                Message::class,
            )
        );

        $createUserInteraction->execute(
            allowDuplicate: true,
            addToCache: false
        );

        return [
            'message' => 'Event streamed successfully',
            'event'   => $eventType,
            'data'    => [
                'user'        => $user->getId(),
                'interaction' => $internalEventName,
                'message'     => $userMessages->toArray(),
            ],
        ];
    }
}
