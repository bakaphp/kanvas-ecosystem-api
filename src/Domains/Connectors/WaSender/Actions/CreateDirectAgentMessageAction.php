<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WaSender\Actions;

use Kanvas\Connectors\WaSender\DataTransferObject\InboundMessage;
use Kanvas\Connectors\WaSender\Enums\BurstConfigEnum;
use Kanvas\Connectors\WaSender\Enums\DirectConfigEnum;
use Kanvas\Connectors\WaSender\Enums\DirectConversationModeEnum;
use Kanvas\Connectors\WaSender\Enums\MessageTypeEnum;
use Kanvas\Connectors\WaSender\Services\ConversationChannelService;
use Kanvas\Connectors\WaSender\Services\GroupSpeakerService;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\DataTransferObject\AiChatMessagePayload;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Actions\CreateMessageTypeAction;
use Kanvas\Social\MessagesTypes\DataTransferObject\MessageTypeInput;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Override;

/**
 * Files one assistant-mode 1:1 message: the group flow with a single speaker. No Lead, no lead
 * source, no stakeholder notification — the conversation entity is the Channel, the counterparty
 * resolves to People, and the burst runs the agent once with `should_reply` on by default.
 */
class CreateDirectAgentMessageAction extends BaseInboundMessageAction
{
    /**
     * Whether this 1:1 conversation gets assistant treatment instead of the lead flow. Checked on
     * the conversation, not the sender — an outbound message to an assistant contact must divert
     * too, or our own replies would open a lead for the counterparty.
     */
    public static function appliesTo(ReceiverWebhook $receiver, InboundMessage $inbound): bool
    {
        $mode = DirectConversationModeEnum::tryFromValue(
            DirectConfigEnum::DIRECT_CONVERSATION_MODE->get($receiver)
        );

        if ($mode === DirectConversationModeEnum::ASSISTANT) {
            return true;
        }

        // Bare forms on both sides: an operator may allow-list a full JID, a phone, or a lid.
        $allowed = array_map(
            InboundMessage::toBareId(...),
            DirectConfigEnum::ASSISTANT_CONTACT_JIDS->getList($receiver)
        );

        if ($allowed === []) {
            return false;
        }

        $candidates = array_filter([
            InboundMessage::toBareId($inbound->conversationJid),
            $inbound->senderPhone,
            $inbound->senderLid,
        ]);

        return array_intersect($candidates, $allowed) !== [];
    }

    #[Override]
    public function execute(): ?Message
    {
        $content = $this->messageData['message'] ?? [];
        $messageType = MessageTypeEnum::getMessageType($content);
        $text = MessageTypeEnum::extractText($content);

        if ($text === null && MessageTypeEnum::mediaKey($content) === null) {
            return null;
        }

        $people = $this->inbound->isFromMe
            ? null
            : new GroupSpeakerService($this->receiver, $this->inbound)->resolve();

        $messageTypeModel = new CreateMessageTypeAction(
            new MessageTypeInput(
                $this->receiver->app->getId(),
                0,
                $messageType->value,
                $messageType->value,
            )
        )->execute();

        $message = new CreateMessageAction(
            new MessageInput(
                app: $this->receiver->app,
                company: $this->receiver->company,
                user: $this->receiver->user,
                type: $messageTypeModel,
                message: [
                    ...AiChatMessagePayload::from([
                        'content' => $text,
                        'from_me' => $this->inbound->isFromMe,
                        'from_ia' => false,
                        'raw_data' => $this->messageData,
                        'message_id' => $this->inbound->messageId,
                        'chat_jid' => $this->inbound->conversationJid,
                    ])->toArray(),
                    'conversation_type' => $this->inbound->conversationType->value,
                    'sender_jid' => $this->inbound->senderJid,
                    'sender_lid' => $this->inbound->senderLid,
                    'sender_phone' => $this->inbound->senderPhone,
                    'sender_name' => $this->inbound->pushName,
                    'sender_identity' => $this->inbound->senderIdentity(),
                    'album_id' => $this->inbound->albumId,
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
            SystemModulesRepository::getByModelName(Channel::class, $this->receiver->app),
            $this->channel->getId(),
        )->execute();

        if ($people !== null) {
            $message->people_id = $people->getId();
            $message->saveOrFail();
        }

        $this->channel->addMessage($message);

        // `messages.upsert` echoes outgoing messages back; arming a burst on one would have the
        // agent answer itself, forever.
        if ($this->inbound->isFromMe) {
            $this->attachMedia($message, $messageType);

            return $message;
        }

        $this->fileIntoBurst($message, $messageType);

        return $message;
    }

    /**
     * A 1:1 is addressed by definition, so both windows are the short one — the sender expects an
     * answer, not a 30-second wait.
     */
    #[Override]
    protected function chainIdleSeconds(): int
    {
        return BurstConfigEnum::BURST_MENTION_IDLE_SECONDS->getInt($this->receiver);
    }

    #[Override]
    protected function closeIdleSeconds(): int
    {
        return $this->chainIdleSeconds();
    }
}
