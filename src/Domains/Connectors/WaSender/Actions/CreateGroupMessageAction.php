<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WaSender\Actions;

use Kanvas\Connectors\WaSender\Enums\BurstConfigEnum;
use Kanvas\Connectors\WaSender\Enums\GroupConfigEnum;
use Kanvas\Connectors\WaSender\Enums\MessageTypeEnum;
use Kanvas\Connectors\WaSender\Services\ConversationChannelService;
use Kanvas\Connectors\WaSender\Services\GroupMentionService;
use Kanvas\Connectors\WaSender\Services\GroupSpeakerService;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\DataTransferObject\AiChatMessagePayload;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Actions\CreateMessageTypeAction;
use Kanvas\Social\MessagesTypes\DataTransferObject\MessageTypeInput;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Override;

/**
 * Files one inbound group message. Silent by design: no Lead, no lead source, no receiver, no
 * stakeholder notification. The conversation entity is the Channel — a room belongs to no single
 * person — and each speaker resolves to their own People so the agent can tell voices apart.
 *
 * Returns null when the group is not allow-listed, which is the normal case for the dozens of
 * groups a company phone sits in.
 */
class CreateGroupMessageAction extends BaseInboundMessageAction
{
    #[Override]
    public function execute(): ?Message
    {
        if (! $this->isAllowed()) {
            return null;
        }

        $app = $this->receiver->app;
        $company = $this->receiver->company;

        $content = $this->messageData['message'] ?? [];
        $messageType = MessageTypeEnum::getMessageType($content);
        $text = MessageTypeEnum::extractText($content);

        // Checked before resolving the speaker: a forwarded ad card carries neither text nor media
        // and must not leave a People row behind for a message we never file.
        if ($text === null && MessageTypeEnum::mediaKey($content) === null) {
            return null;
        }

        $people = new GroupSpeakerService($this->receiver, $this->inbound)->resolve();

        $messageTypeModel = new CreateMessageTypeAction(
            new MessageTypeInput(
                $app->getId(),
                0,
                $messageType->value,
                $messageType->value,
            )
        )->execute();

        $message = new CreateMessageAction(
            new MessageInput(
                app: $app,
                company: $company,
                user: $this->receiver->user,
                type: $messageTypeModel,
                message: [
                    ...AiChatMessagePayload::from([
                        'content' => $this->attribute($text, $people),
                        'from_me' => $this->inbound->isFromMe,
                        'from_ia' => false,
                        'raw_data' => $this->messageData,
                        'message_id' => $this->inbound->messageId,
                        'chat_jid' => $this->inbound->conversationJid,
                    ])->toArray(),
                    'conversation_type' => $this->inbound->conversationType->value,
                    'group_jid' => $this->inbound->conversationJid,
                    'sender_jid' => $this->inbound->senderJid,
                    'sender_lid' => $this->inbound->senderLid,
                    'sender_phone' => $this->inbound->senderPhone,
                    'sender_name' => $this->inbound->pushName,
                    'sender_identity' => $this->inbound->senderIdentity(),
                    'album_id' => $this->inbound->albumId,
                    'mentioned_jids' => $this->inbound->mentionedJids,
                    'quoted_message_id' => $this->inbound->quotedMessageId,
                    'quoted_participant' => $this->inbound->quotedParticipant,
                ],
                is_public: 1,
                slug: ConversationChannelService::messageSlug(
                    $this->inbound->messageId,
                    $this->inbound->conversationJid
                ),
                tags: [$this->inbound->conversationJid],
            ),
            SystemModulesRepository::getByModelName(Channel::class, $app),
            $this->channel->getId(),
        )->execute();

        $message->people_id = $people->getId();
        $message->saveOrFail();

        $this->channel->addMessage($message);

        // Our own message is the one moment WhatsApp discloses our lid, so it is filed and read —
        // but never burst. `messages.upsert` echoes outgoing messages back, so arming a burst on
        // one would have the agent answer itself, forever.
        if ($this->inbound->isFromMe) {
            new GroupMentionService($this->receiver, $this->channel)->rememberOwnLid($this->inbound);
            $this->attachMedia($message, $messageType);

            return $message;
        }

        $this->fileIntoBurst($message, $messageType);

        return $message;
    }

    #[Override]
    protected function chainIdleSeconds(): int
    {
        return BurstConfigEnum::BURST_IDLE_SECONDS->getInt($this->receiver);
    }

    /**
     * A mention shortens the wait instead of closing the burst outright — `@agent look at this
     * <3 photos>` must stay one turn, but the person asking should not wait the full idle window.
     */
    #[Override]
    protected function closeIdleSeconds(): int
    {
        return $this->inbound->mentionedJids !== [] || $this->inbound->quotedMessageId !== null
            ? BurstConfigEnum::BURST_MENTION_IDLE_SECONDS->getInt($this->receiver)
            : BurstConfigEnum::BURST_IDLE_SECONDS->getInt($this->receiver);
    }

    private function isAllowed(): bool
    {
        return in_array(
            $this->inbound->conversationJid,
            GroupConfigEnum::ALLOWED_GROUP_JIDS->getList($this->receiver),
            true
        );
    }

    private function attribute(?string $text, ?People $people): ?string
    {
        $name = $this->inbound->pushName ?? $people?->getName();

        if ($text === null || $name === null || trim($name) === '') {
            return $text;
        }

        return trim($name) . ': ' . $text;
    }
}
