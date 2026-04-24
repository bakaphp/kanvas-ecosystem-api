<?php

declare(strict_types=1);

namespace Kanvas\Connectors\RespondIO\Actions;

use Baka\Support\Str;
use Kanvas\Connectors\RespondIO\BaseRespondIOAction;
use Kanvas\Connectors\RespondIO\Enums\MessageTypeEnum;
use Kanvas\Connectors\RespondIO\Traits\HasChannelProcessing;
use Kanvas\Connectors\RespondIO\Traits\HasMessageProcessing;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Actions\CreateMessageTypeAction;
use Kanvas\Social\MessagesTypes\DataTransferObject\MessageTypeInput;
use Override;

class ProcessOutgoingMessageAction extends BaseRespondIOAction
{
    use HasChannelProcessing;
    use HasMessageProcessing;

    #[Override]
    public function execute(): array
    {
        /** @var array<string, mixed> $contact */
        $contact = $this->payload['contact'] ?? [];
        /** @var array<string, mixed> $messageData */
        $messageData = $this->payload['message'] ?? [];
        /** @var array<string, mixed> $senderData */
        $senderData = $this->payload['sender'] ?? [];

        $identifier = $this->getContactIdentifier($contact);

        if ($identifier === null) {
            return ['error' => 'Missing contact identifier'];
        }

        $messageId = (string) ($messageData['messageId'] ?? Str::uuid()->toString());
        $messageContent = $messageData['message'] ?? [];
        $messageType = MessageTypeEnum::getMessageType($messageContent);
        $text = $this->extractMessageText($messageData, $messageType);

        $messageSlug = $this->createMessageSlug($messageId, $identifier);
        $channel = $this->getOrCreateChannel($this->app, $this->company, $identifier);

        $existingMessage = Message::where('uuid', $messageSlug)
            ->where('companies_id', $this->company->getId())
            ->where('apps_id', $this->app->getId())
            ->first();

        if (! $existingMessage) {
            $messageTypeModel = new CreateMessageTypeAction(
                new MessageTypeInput(
                    $this->app->getId(),
                    0,
                    $messageType->value,
                    $messageType->value,
                )
            )->execute();

            $messageInput = new MessageInput(
                app: $this->app,
                company: $this->company,
                user: $this->user,
                type: $messageTypeModel,
                message: [
                    'content' => $text,
                    'raw_data' => $this->payload,
                    'message_id' => $messageId,
                    'chat_jid' => $identifier,
                    'from_me' => true,
                    'respondio_source' => $senderData['source'] ?? null,
                    'respondio_user_id' => $senderData['userId'] ?? null,
                ],
                is_public: 1,
                slug: $messageSlug,
                tags: [$identifier]
            );

            $existingMessage = new CreateMessageAction($messageInput)->execute();
        }

        $channel->addMessage($existingMessage);

        return [
            'message_id' => $existingMessage->getId(),
            'uuid' => $existingMessage->uuid,
            'channel_id' => $channel->getId(),
            'is_from_me' => true,
        ];
    }
}
