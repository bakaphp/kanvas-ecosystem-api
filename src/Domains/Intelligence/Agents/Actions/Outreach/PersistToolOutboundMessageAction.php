<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Actions\Outreach;

use Kanvas\Connectors\Twilio\Actions\StoreMessageSidAction;
use Kanvas\Connectors\Twilio\Enums\MessageTypeEnum as TwilioMessageTypeEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Sessions\Services\SessionChannelService;
use Kanvas\Social\Channels\Actions\CreateChannelAction;
use Kanvas\Social\Channels\DataTransferObject\Channel as ChannelDto;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Enums\ChannelCategoryEnum;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\DataTransferObject\AiChatMessagePayload;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Services\MessageTypeService;
use Kanvas\Users\Models\Users;

/**
 * Persist a message already delivered by an agent tool on the matching protocol channel.
 *
 * Tool delivery happens first. This action records that exact outbound on the SMS/email channel
 * used by inbound responders, so future turns can load one continuous protocol history. It does
 * not create a Session or fire the CREATED delivery workflow: the provider has already accepted
 * the message and must never be called twice.
 */
class PersistToolOutboundMessageAction
{
    public function __construct(
        private readonly Lead $lead,
        private readonly Users $user,
        private readonly string $channelType,
        private readonly string $recipient,
        private readonly string $content,
        private readonly ?string $subject = null,
        private readonly ?Agent $agent = null,
    ) {
    }

    /**
     * @param array<string, mixed> $providerResponse
     *
     * @return array{message: Message, channel: Channel}
     */
    public function execute(array $providerResponse): array
    {
        $channel = $this->resolveProtocolChannel();
        $messageType = MessageTypeService::getOrCreate(
            $this->lead->app,
            match ($this->channelType) {
                ChannelCategoryEnum::SMS->value => TwilioMessageTypeEnum::SMS->value,
                ChannelCategoryEnum::EMAIL->value => ChannelCategoryEnum::MAILGUN->value,
                default => 'text',
            },
        );

        $createMessage = new CreateMessageAction(new MessageInput(
            app: $this->lead->app,
            company: $this->lead->company,
            user: $this->user,
            type: $messageType,
            message: AiChatMessagePayload::from([
                'content' => $this->content,
                'from_me' => true,
                'from_ia' => true,
                'agent_id' => $this->agent?->getId(),
                'raw_data' => $providerResponse,
                'message_id' => '--',
                'chat_jid' => $this->recipient,
            ])->toArray(),
            tags: [$this->recipient, 'agent-tool-delivery'],
            is_public: 1,
            people: $this->lead->people,
        ));
        $createMessage->runWorkflow = false;
        $message = $createMessage->execute();

        $message->set('communicationChannel', $this->channelType);
        if ($this->subject !== null && $this->subject !== '') {
            $message->set('title', $this->subject);
        }

        $channel->addMessage($message);
        $message->addEntity($this->lead);
        if ($this->lead->people !== null) {
            $message->addEntity($this->lead->people);
        }

        if ($this->channelType === ChannelCategoryEnum::SMS->value) {
            new StoreMessageSidAction($message)->execute($providerResponse);
        }

        return ['message' => $message, 'channel' => $channel];
    }

    private function resolveProtocolChannel(): Channel
    {
        return new CreateChannelAction(ChannelDto::from([
            'apps' => $this->lead->app,
            'companies' => $this->lead->company,
            'users' => $this->lead->user,
            'entity_id' => $this->lead->getId(),
            'entity_namespace' => Lead::class,
            'name' => ucfirst($this->channelType) . ' ' . $this->lead->getId(),
            'slug' => SessionChannelService::createChannelSlug($this->channelType, $this->recipient),
        ]))->execute();
    }
}
