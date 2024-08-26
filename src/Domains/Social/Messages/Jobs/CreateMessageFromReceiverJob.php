<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Jobs;

use Exception;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\Actions\DistributeChannelAction;
use Kanvas\Social\Messages\Actions\DistributeToUsers;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\Messages\Enums\DistributionTypeEnum;
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

        $user = $this->receiver->user;
        $payload['message']['ip_address'] = $this->webhookRequest->headers['x-real-ip'] ?? null;
        $createMessage = new CreateMessageAction(
            new MessageInput(
                app: $this->receiver->app,
                company: $this->receiver->company,
                user: $user,
                type: $messageType,
                message: $payload['message']
            )
        );

        $message = $createMessage->execute();

        /**
         * @todo refactor this logic here and in message mutation to avoid duplication
         */
        if (key_exists('distribution', $payload)) {
            $distributionType = DistributionTypeEnum::from($payload['distribution']['distributionType']);

            if ($distributionType->value == DistributionTypeEnum::ALL->value) {
                $channels = key_exists('channels', $payload['distribution']) ? $payload['distribution']['channels'] : [];
                (new DistributeChannelAction($channels, $message, $user))->execute();
                (new DistributeToUsers($message))->execute();
            } elseif ($distributionType->value == DistributionTypeEnum::Channels->value) {
                $channels = key_exists('channels', $payload['distribution']) ? $payload['distribution']['channels'] : [];
                (new DistributeChannelAction($channels, $message, $user))->execute();
            } elseif ($distributionType->value == DistributionTypeEnum::Followers->value) {
                (new DistributeToUsers($message))->execute();
            }
        }

        return [
            'message' => 'Message created successfully from receiver with id ' . $message->getId(),
            'message_id' => $message->getId(),
            'message_verb' => $messageType->verb,
            'distribution' => $payload['distribution'] ?? null,
        ];
    }
}
