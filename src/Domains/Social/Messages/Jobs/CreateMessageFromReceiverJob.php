<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Jobs;

use Exception;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\MessagesTypes\Actions\CreateMessageTypeAction;
use Kanvas\Social\MessagesTypes\DataTransferObject\MessageTypeInput;
use Kanvas\Social\MessagesTypes\Repositories\MessagesTypesRepository;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;

class CreateMessageFromReceiverJob extends ProcessWebhookJob
{
    public function execute(): array
    {
        $payload = $this->webhookRequest->payload;

        if (! isset($payload['message'])) {
            throw new Exception('Cant create message without message');
        }
        if (! isset($payload['message_verb'])) {
            throw new Exception('Cant create message without verb');
        }

        try {
            $messageType = MessagesTypesRepository::getByVerb($payload['message_verb'], $this->receiver->app);
        } catch (ModelNotFoundException $e) {
            $messageTypeDto = MessageTypeInput::from([
                'apps_id' => $this->receiver->app->getId(),
                'name' => $payload['message_verb'],
                'verb' => $payload['message_verb'],
            ]);
            $messageType = (new CreateMessageTypeAction($messageTypeDto))->execute();
        }

        $createMessage = new CreateMessageAction(
            new MessageInput(
                app: $this->receiver->app,
                company: $this->receiver->company,
                user: $this->receiver->user,
                type: $messageType,
                message: $payload['message']
            )
        );

        $message = $createMessage->execute();

        return [
            'message' => 'Message created successfully from receiver with id ' . $message->getId(),
            'message_id' => $message->getId(),
            'message_verb' => $messageType->verb,
        ];
    }
}
