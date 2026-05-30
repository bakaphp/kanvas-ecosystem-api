<?php

declare(strict_types=1);

namespace Kanvas\Connectors\RespondIO\Actions;

use Baka\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\RespondIO\BaseRespondIOAction;
use Kanvas\Connectors\RespondIO\Enums\MessageTypeEnum;
use Kanvas\Connectors\RespondIO\Traits\HasChannelProcessing;
use Kanvas\Connectors\RespondIO\Traits\HasLeadProcessing;
use Kanvas\Connectors\RespondIO\Traits\HasMessageProcessing;
use Kanvas\Guild\Leads\Enums\ConfigurationEnum as LeadsConfigurationEnum;
use Kanvas\Guild\Leads\Services\NotifyLeadStakeholdersService;
use Kanvas\Intelligence\Sessions\DataTransferObject\AiChatMessagePayload;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Actions\CreateMessageTypeAction;
use Kanvas\Social\MessagesTypes\DataTransferObject\MessageTypeInput;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Override;

class ProcessIncomingMessageAction extends BaseRespondIOAction
{
    use HasLeadProcessing;
    use HasChannelProcessing;
    use HasMessageProcessing;

    public function __construct(
        protected Apps $app,
        protected Companies $company,
        protected Users $user,
        protected array $payload,
        protected array $receiverConfiguration = [],
    ) {
    }

    #[Override]
    public function execute(): array
    {
        /** @var array<string, mixed> $contact */
        $contact = $this->payload['contact'] ?? [];
        /** @var array<string, mixed> $messageData */
        $messageData = $this->payload['message'] ?? [];
        /** @var array<string, mixed> $channelData */
        $channelData = $this->payload['channel'] ?? [];
        /** @var array<string, mixed> $senderData */
        $senderData = $this->payload['sender'] ?? [];

        $identifier = $this->getContactIdentifier($contact);

        if ($identifier === null) {
            return ['error' => 'Missing contact phone or ID in payload'];
        }

        $firstName = $contact['firstName'] ?? null;
        $lastName = $contact['lastName'] ?? null;
        $contactId = (string) ($contact['id'] ?? '');
        $messageId = (string) ($messageData['messageId'] ?? Str::uuid()->toString());
        $messageContent = $messageData['message'] ?? [];
        $messageType = MessageTypeEnum::getMessageType($messageContent);
        $text = $this->extractMessageText($messageData, $messageType);
        $displayName = $this->getContactDisplayName($contact, $identifier);

        $people = $this->processContactFromMessage(
            $this->app,
            $this->company,
            $this->user,
            $identifier,
            $firstName,
            $lastName
        );

        if (! $people) {
            return ['error' => 'Failed to process contact'];
        }

        $lead = $this->createLeadFromPeople($people, $this->receiverConfiguration);
        $lead->set(LeadsConfigurationEnum::AGENT_COMMUNICATION_CHANNEL->value, 'respondio');
        $lead->set(LeadsConfigurationEnum::IS_ENGAGEMENT->value, true);

        $messageSlug = $this->createMessageSlug($messageId, $identifier);
        $channel = $this->getOrCreateChannel(
            $this->app,
            $this->company,
            $identifier,
            $displayName,
            $lead
        );

        $existingMessage = Message::where('uuid', $messageSlug)
            ->where('companies_id', $this->company->getId())
            ->where('apps_id', $this->app->getId())
            ->first();

        if ($existingMessage) {
            $message = $existingMessage;
        } else {
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
                    ...AiChatMessagePayload::from([
                        'content' => $text,
                        'from_me' => false,
                        'from_ia' => false,
                        'raw_data' => $this->payload,
                        'message_id' => $messageId,
                        'chat_jid' => $identifier,
                    ])->toArray(),
                    'respondio_channel' => $channelData['name'] ?? null,
                    'respondio_contact_id' => $contactId,
                    'respondio_source' => $senderData['source'] ?? null,
                ],
                is_public: 1,
                slug: $messageSlug,
                tags: [$identifier]
            );

            $message = new CreateMessageAction($messageInput)->execute();
        }

        $message->addEntity($lead);
        $message->addTag('engagement');

        $channel->addMessage($message);

        new NotifyLeadStakeholdersService($lead)->onCustomerEngagement($message);

        $channel->addTags(
            ['respondio', 'ai-agent'],
            $this->app,
            $this->user,
            $this->company
        );

        $channel->addCategory(
            'ai-agent',
            $this->app,
            $this->user,
            $this->company
        );

        $channel->fireWorkflow(
            WorkflowEnum::AFTER_ADDING_MESSAGE_TO_CHANNEL->value,
            true,
            [
                'message' => $message,
                'user' => $message->user,
                'app' => $message->app,
                'company' => $message->company,
                'text' => $text,
                'communication_channel' => 'respondio',
            ]
        );

        return [
            'message_id' => $message->getId(),
            'uuid' => $message->uuid,
            'channel_id' => $channel->getId(),
            'chat_jid' => $identifier,
            'text' => $text,
            'is_from_me' => false,
            'type' => $messageType->value,
        ];
    }
}
