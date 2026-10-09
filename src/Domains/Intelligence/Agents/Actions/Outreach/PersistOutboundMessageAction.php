<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Actions\Outreach;

use Kanvas\Connectors\Twilio\Actions\StoreMessageSidAction;
use Kanvas\Connectors\Twilio\Enums\MessageTypeEnum as TwilioMessageTypeEnum;
use Kanvas\Guild\Customers\Models\People;
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
 * Persist a message already delivered (by an agent tool or a campaign) on the matching protocol channel.
 *
 * Delivery happens first. This action records that exact outbound on the SMS/email channel of the
 * Lead or, for a person with no lead, of the People record, so the activity timeline and future agent
 * turns see one continuous protocol history. It does not create a Session or fire the CREATED delivery
 * workflow: the provider has already accepted the message and must never be called twice.
 */
class PersistOutboundMessageAction
{
    public function __construct(
        private readonly Lead|People $entity,
        private readonly Users $user,
        private readonly string $channelType,
        private readonly string $recipient,
        private readonly string $content,
        private readonly ?string $subject = null,
        private readonly ?Agent $agent = null,
        private readonly bool $fromAi = true,
        private readonly string $tag = 'agent-tool-delivery',
    ) {
    }

    /**
     * @param array<string, mixed> $providerResponse
     *
     * @return array{message: Message, channel: Channel}
     */
    public function execute(array $providerResponse): array
    {
        $people = $this->entity instanceof Lead ? $this->entity->people : $this->entity;
        $channel = $this->resolveProtocolChannel();
        $messageType = MessageTypeService::getOrCreate(
            $this->entity->app,
            match ($this->channelType) {
                ChannelCategoryEnum::SMS->value => TwilioMessageTypeEnum::SMS->value,
                ChannelCategoryEnum::EMAIL->value => ChannelCategoryEnum::MAILGUN->value,
                default => 'text',
            },
        );

        $createMessage = new CreateMessageAction(new MessageInput(
            app: $this->entity->app,
            company: $this->entity->company,
            user: $this->user,
            type: $messageType,
            message: AiChatMessagePayload::from([
                'content' => $this->content,
                'from_me' => true,
                'from_ia' => $this->fromAi,
                'agent_id' => $this->agent?->getId(),
                'raw_data' => $providerResponse,
                'message_id' => '--',
                'chat_jid' => $this->recipient,
            ])->toArray(),
            tags: [$this->recipient, $this->tag],
            is_public: 1,
            people: $people,
        ));
        $createMessage->runWorkflow = false;
        $message = $createMessage->execute();

        $message->set('communicationChannel', $this->channelType);
        if ($this->subject !== null && $this->subject !== '') {
            $message->set('title', $this->subject);
        }

        $channel->addMessage($message);
        $message->addEntity($this->entity);
        if ($this->entity instanceof Lead && $people !== null) {
            $message->addEntity($people);
        }

        if ($this->channelType === ChannelCategoryEnum::SMS->value) {
            new StoreMessageSidAction($message)->execute($providerResponse);
        }

        return ['message' => $message, 'channel' => $channel];
    }

    private function resolveProtocolChannel(): Channel
    {
        $name = $this->entity instanceof Lead
            ? ucfirst($this->channelType) . ' ' . $this->entity->getId()
            : ucfirst($this->channelType) . ' People ' . $this->entity->getId();

        return new CreateChannelAction(ChannelDto::from([
            'apps' => $this->entity->app,
            'companies' => $this->entity->company,
            'users' => $this->entity->user,
            'entity_id' => $this->entity->getId(),
            'entity_namespace' => $this->entity::class,
            'name' => $name,
            'slug' => SessionChannelService::createChannelSlug($this->channelType, $this->recipient),
        ]))->execute();
    }
}
