<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Microsoft\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Guild\Leads\Enums\ConfigurationEnum as LeadsConfigurationEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Sessions\Services\SessionChannelService;
use Kanvas\Social\Channels\Actions\CreateChannelAction;
use Kanvas\Social\Channels\DataTransferObject\Channel as ChannelDto;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Actions\CreateMessageTypeAction;
use Kanvas\Social\MessagesTypes\DataTransferObject\MessageTypeInput;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Kanvas\Workflow\Enums\WorkflowEnum;

/**
 * Creates a Kanvas social message from a Microsoft Graph email response.
 *
 * Mirrors the Mailgun CreateMessageFromEmailAction pattern:
 * 1. Creates a 'microsoft-email' message type
 * 2. Stores the Graph message ID and conversationId for reply threading
 * 3. If a lead is found: creates/gets a channel tied to the lead, links the message
 *    via AppModuleMessage, and fires AFTER_ADDING_MESSAGE_TO_CHANNEL workflow
 * 4. If no lead: creates a standalone message
 *
 * The $emailData param expects a Microsoft Graph message object (from Client::getMessage()).
 *
 * @see \Kanvas\Connectors\Mailgun\Actions\CreateMessageFromEmailAction
 */
class CreateMessageFromMicrosoftEmailAction
{
    /**
     * @param array<string, mixed> $emailData Microsoft Graph message object
     */
    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company,
        protected UserInterface $user,
        protected array $emailData,
        protected ?Lead $lead = null,
    ) {
    }

    public function execute(): Message
    {
        $from = $this->extractFrom();
        $bodyContent = $this->extractBody();
        $conversationId = $this->emailData['conversationId'] ?? '';

        $messageType = $this->getOrCreateMessageType();
        $parentMessage = $this->findParentMessage($conversationId);
        $messageInput = $this->buildMessageInput($messageType, $parentMessage, $from, $bodyContent);

        $channel = null;
        if ($this->lead !== null) {
            $channel = $this->createLeadChannel();
            $newMessage = $this->createMessageWithLead($messageInput);
        } else {
            $newMessage = new CreateMessageAction($messageInput)->execute();
        }

        $this->storeGraphCustomFields($newMessage);

        if ($channel !== null) {
            $this->attachMessageToChannel($channel, $newMessage, $bodyContent);
        }

        return $newMessage;
    }

    private function extractFrom(): string
    {
        return $this->emailData['from']['emailAddress']['address'] ?? '';
    }

    private function extractBody(): string
    {
        return $this->emailData['body']['content'] ?? $this->emailData['bodyPreview'] ?? '';
    }

    private function getOrCreateMessageType(): MessageType
    {
        return new CreateMessageTypeAction(
            new MessageTypeInput(
                $this->app->getId(),
                0,
                'microsoft-email',
                'microsoft-email',
            )
        )->execute();
    }

    /**
     * Find the first message in the same Outlook conversation thread to use as parent.
     */
    private function findParentMessage(string $conversationId): ?Message
    {
        if ($conversationId === '') {
            return null;
        }

        return Message::where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->where('message->conversation_id', $conversationId)
            ->where('is_deleted', 0)
            ->orderBy('id', 'asc')
            ->first();
    }

    private function buildMessageInput(
        MessageType $messageType,
        ?Message $parentMessage,
        string $from,
        string $bodyContent
    ): MessageInput {
        return new MessageInput(
            app: $this->app,
            company: $this->company,
            user: $this->user,
            type: $messageType,
            message: [
                'content' => strip_tags($bodyContent),
                'raw_data' => $bodyContent,
                'message_id' => $this->emailData['id'] ?? '',
                'conversation_id' => $this->emailData['conversationId'] ?? '',
                'chat_jid' => SessionChannelService::createChannelSlug('email', $from),
                'from_email' => $from,
                'subject' => $this->emailData['subject'] ?? '',
                'from_me' => false,
            ],
            is_public: 1,
            parent_id: $parentMessage?->getId(),
            parent_unique_id: $parentMessage?->uuid,
        );
    }

    /**
     * Create or get the email channel tied to the lead.
     */
    private function createLeadChannel(): Channel
    {
        $this->lead->set(LeadsConfigurationEnum::AGENT_COMMUNICATION_CHANNEL->value, 'email');

        $channelDto = ChannelDto::from([
            'apps' => $this->lead->app,
            'companies' => $this->lead->company,
            'users' => $this->lead->user,
            'entity_id' => $this->lead->getId(),
            'entity_namespace' => Lead::class,
            'name' => 'Email ' . $this->lead->getId(),
            'slug' => SessionChannelService::createChannelSlug(
                $this->lead->get(LeadsConfigurationEnum::AGENT_COMMUNICATION_CHANNEL->value),
                $this->lead->email
            ),
        ]);

        return new CreateChannelAction($channelDto)->execute();
    }

    /**
     * Create the message linked to the lead via AppModuleMessage.
     */
    private function createMessageWithLead(MessageInput $messageInput): Message
    {
        $leadSystemModule = SystemModulesRepository::getByModelName(
            get_class($this->lead),
            $this->lead->app
        );

        return new CreateMessageAction(
            $messageInput,
            $leadSystemModule,
            $this->lead->getId()
        )->execute();
    }

    /**
     * Store Graph IDs as custom fields for easy querying outside the message JSON.
     */
    private function storeGraphCustomFields(Message $message): void
    {
        $graphMessageId = $this->emailData['id'] ?? '';
        $conversationId = $this->emailData['conversationId'] ?? '';

        if ($graphMessageId !== '') {
            $message->set('microsoft_graph_message_id', $graphMessageId);
        }
        if ($conversationId !== '') {
            $message->set('microsoft_conversation_id', $conversationId);
        }
    }

    /**
     * Add the message to the channel, tag it, and fire the workflow.
     */
    private function attachMessageToChannel(Channel $channel, Message $message, string $bodyContent): void
    {
        $channel->addMessage($message);
        $message->addTag('engagement');

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
                'communication_channel' => 'email',
                'text' => strip_tags($bodyContent),
            ]
        );
    }
}
