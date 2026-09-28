<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mailgun\Actions;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Connectors\Mailgun\Enums\ReceiverConfigurationEnum;
use Kanvas\Connectors\Mailgun\Services\MailgunAttachmentService;
use Kanvas\Connectors\Mailgun\Services\MailgunPayloadService;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Enums\ConfigurationEnum as IntelligenceConfigurationEnum;
use Kanvas\Intelligence\Sessions\Services\SessionChannelService;
use Kanvas\Social\Channels\Actions\CreateChannelAction;
use Kanvas\Social\Channels\DataTransferObject\Channel as ChannelDto;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\DataTransferObject\AiChatMessagePayload;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Services\MessageTypeService;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Models\ReceiverWebhookCall;

/**
 * Turns one email delivered to an agent's own mailbox into a Message on a per-sender channel.
 *
 * Distinct from CreateMessageFromEmailAction (the shared lead inbox) in what it keys on: there the
 * channel belongs to the lead, here it belongs to the agent↔sender pair. Two agents with mailboxes
 * writing to the same person must not land in one thread and read each other's history.
 */
class CreateMessageFromAgentInboxAction
{
    public function __construct(
        protected readonly ReceiverWebhookCall $webhookRequest,
        protected readonly Agent $agent,
        protected readonly Model $sender,
    ) {
    }

    public function execute(): Message
    {
        $receiver = $this->webhookRequest->receiverWebhook;
        $app = $receiver->app;
        $company = $receiver->company;
        $payload = new MailgunPayloadService($this->webhookRequest->payload);

        $from = $payload->sender();
        $channel = $this->resolveChannel($from);

        $messageInput = new MessageInput(
            app: $app,
            company: $company,
            user: $this->sender instanceof Users ? $this->sender : $receiver->user,
            message: [
                ...AiChatMessagePayload::from([
                    'content' => $payload->text(),
                    'from_me' => false,
                    'from_ia' => false,
                    'raw_data' => $payload->text(),
                    'message_id' => $payload->messageId(),
                    'chat_jid' => SessionChannelService::createCanalId('email', $from),
                ])->toArray(),
                'from_email' => $from,
                'subject' => $payload->subject(),
                // Kept so the reply can carry In-Reply-To/References and thread in the sender's client.
                'email_message_id' => $payload->messageId(),
                'email_references' => $payload->references(),
            ],
            type: MessageTypeService::getOrCreate($app, 'mailgun-email'),
            is_public: 1,
        );

        $message = new CreateMessageAction(
            $messageInput,
            SystemModulesRepository::getByModelName($this->sender::class, $app),
            $this->sender->getId(),
        )->execute();

        if ($this->sender instanceof Lead && $this->sender->people !== null) {
            // People-keyed history loaders (SalesAssistKanvasMessageHistory) find the turn only
            // through this attachment.
            $message->addEntity($this->sender->people);
        }

        $channel->addMessage($message);
        $message->addTag('engagement');
        $channel->addCategory(
            'ai-agent',
            $app,
            $receiver->user,
            $company
        );

        new MailgunAttachmentService($this->webhookRequest)->attachTo($message);

        return $message;
    }

    private function resolveChannel(string $from): Channel
    {
        $receiver = $this->webhookRequest->receiverWebhook;

        $channel = new CreateChannelAction(
            new ChannelDto(
                apps: $receiver->app,
                companies: $receiver->company,
                users: $this->sender instanceof Users ? $this->sender : $receiver->user,
                entity_id: $this->sender->getId(),
                entity_namespace: $this->sender::class,
                name: 'Email ' . $this->agent->name . ' — ' . $from,
                description: 'Email conversation with ' . $this->agent->name,
                slug: 'agent-' . (int) $this->agent->getId() . '-'
                    . SessionChannelService::createChannelSlug('email', $from),
            ),
        )->execute();

        $channel->set(IntelligenceConfigurationEnum::AGENT_CHANNEL_TYPE->value, 'EMAIL');
        $channel->set(ReceiverConfigurationEnum::AGENT_ID->value, $this->agent->getId());

        return $channel;
    }
}
