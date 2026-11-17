<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WaSender\Actions;

use Illuminate\Support\Facades\Cache;
use Kanvas\Connectors\WaSender\DataTransferObject\InboundMessage;
use Kanvas\Connectors\WaSender\Enums\ConversationTypeEnum;
use Kanvas\Connectors\WaSender\Enums\MessageTypeEnum;
use Kanvas\Connectors\WaSender\Enums\WebhookEventEnum;
use Kanvas\Connectors\WaSender\Services\ConversationChannelService;
use Kanvas\Connectors\WaSender\WaSenderInbound;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\DataTransferObject\AiChatMessagePayload;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Services\MessageTypeService;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Throwable;

/**
 * Everything a WaSender webhook can carry, routed by its event: messages (group room, assistant
 * 1:1, lead 1:1), edits, deletions, reactions, receipts, our own sends echoed back, and the chat,
 * group and contact directory. The communication webhook hands the payload here once the
 * signature is checked; the three conversation shapes and what each needs are in this connector's
 * CLAUDE.md.
 */
class ProcessWaSenderEventAction
{
    private const int DEDUPE_TTL_SECONDS = 600;

    protected bool $hijackSession = false;
    private ?ConversationChannelService $channelService = null;

    public function __construct(
        protected readonly ReceiverWebhook $receiver,
        protected array $payload
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(): array
    {
        $eventType = (string) ($this->payload['event'] ?? 'unknown');
        $this->standInForAnotherNumber();

        $data = (array) ($this->payload['data'] ?? []);

        $result = match ($eventType) {
            WebhookEventEnum::MESSAGES_UPSERT->value => $this->handleMessageUpsert(),
            WebhookEventEnum::MESSAGES_UPDATE->value => $this->handleMessageUpdate(),
            WebhookEventEnum::MESSAGES_DELETE->value => $this->handleMessageDelete(),
            WebhookEventEnum::MESSAGES_REACTION->value => $this->handleMessageReaction(),
            WebhookEventEnum::MESSAGE_RECEIPT_UPDATE->value => $this->handleMessageReceiptUpdate(),
            WebhookEventEnum::MESSAGE_SENT->value => $this->handleMessageSent(),

            // WaSender mirrors every inbound message across these on top of messages.upsert, the
            // one event that is ingested.
            WebhookEventEnum::MESSAGES_RECEIVED->value,
            WebhookEventEnum::MESSAGES_GROUP_RECEIVED->value,
            WebhookEventEnum::MESSAGES_PERSONAL_RECEIVED->value,
            WebhookEventEnum::MESSAGES_NEWSLETTER_RECEIVED->value => [
                'processed' => false,
                'reason' => 'Duplicate of messages.upsert, ignored by design',
                'type' => $eventType,
            ],

            WebhookEventEnum::CHATS_UPSERT->value => $this->handleChatUpsert(),
            WebhookEventEnum::CHATS_UPDATE->value => $this->handleChatUpdate(),
            WebhookEventEnum::CHATS_DELETE->value => $this->handleChatDelete(),

            WebhookEventEnum::GROUPS_UPSERT->value => $this->handleGroupUpsert(),
            WebhookEventEnum::GROUPS_UPDATE->value => $this->handleGroupUpdate(),
            WebhookEventEnum::GROUP_PARTICIPANTS_UPDATE->value => $this->handleGroupParticipantsUpdate(),

            WebhookEventEnum::CONTACTS_UPSERT->value => $this->handleContactUpsert(),
            WebhookEventEnum::CONTACTS_UPDATE->value => $this->handleContactUpdate(),

            WebhookEventEnum::SESSION_STATUS->value => ['status' => $data['status'] ?? 'unknown'],
            WebhookEventEnum::QRCODE_UPDATED->value => ['has_qr' => ! empty($data['qr'])],

            default => [
                'processed' => false,
                'reason' => 'Unknown event type',
                'type' => $eventType,
            ],
        };

        return [
            'message' => 'WaSender webhook processed successfully',
            'event_type' => $eventType,
            'result' => $result,
        ];
    }

    /**
     * A test session standing in for someone else's number: the company maps a counterpart's JID
     * onto another phone, and the message is filed as if it came from there.
     */
    private function standInForAnotherNumber(): void
    {
        $company = $this->receiver->company;
        $remoteJid = $this->payload['data']['messages']['remoteJid'] ?? null;

        if ($remoteJid === null || ! $company->get('allow_session_hijack', false)) {
            return;
        }

        $standIn = $company->get('overwrite_phone_number')[$remoteJid] ?? null;

        if ($standIn === null) {
            return;
        }

        $this->hijackSession = true;
        $this->payload['data']['messages']['remoteJid'] = $standIn;
        $this->payload['data']['messages']['key']['remoteJid'] = $standIn;
    }

    /**
     * The entries an event carries. WaSender sends one as an object and several as a list, and
     * some events nest the object under a name (`data.messages`, `data.chats`).
     *
     * @return array<array-key, mixed>
     */
    private function entries(): array
    {
        $data = (array) ($this->payload['data'] ?? []);

        return isset($data['key']) ? [$data] : $data;
    }

    /**
     * WaSender documents no delivery or ordering semantics, so a retry is on us to absorb — and
     * with the reply running inline for groups, a second delivery is a second answer.
     */
    protected function isFirstDelivery(string $messageId): bool
    {
        if ($messageId === '') {
            return true;
        }

        return Cache::add(self::deliveryKey($messageId), true, self::DEDUPE_TTL_SECONDS);
    }

    private static function deliveryKey(string $messageId): string
    {
        return 'wasender:msg:' . $messageId;
    }

    protected function handleMessageUpsert(): array
    {
        $processedMessages = [];
        $skippedMessages = [];

        foreach ($this->entries() as $messageData) {
            $messageData = (array) $messageData;
            $inbound = InboundMessage::fromWebhookMessage($messageData);

            // An unroutable payload is an expected condition, not a fault worth reporting.
            if ($inbound === null) {
                $skippedMessages[] = ['reason' => 'no routable conversation jid'];

                continue;
            }

            // Newsletters are broadcast-only; nothing to file and nobody to answer.
            if ($inbound->conversationType === ConversationTypeEnum::NEWSLETTER) {
                $skippedMessages[] = [
                    'message_id' => $inbound->messageId,
                    'conversation_type' => $inbound->conversationType->value,
                    'reason' => 'conversation type not ingested',
                ];

                continue;
            }

            // A send that went through the ledger was filed when it left.
            if ($inbound->isFromMe && WaSenderInbound::deliveryOf($this->receiver, $messageData) !== null) {
                $skippedMessages[] = [
                    'message_id' => $inbound->messageId,
                    'reason' => 'our own send, already on the ledger',
                ];

                continue;
            }

            if (! $this->isFirstDelivery($inbound->messageId)) {
                $skippedMessages[] = [
                    'message_id' => $inbound->messageId,
                    'reason' => 'duplicate delivery',
                ];

                continue;
            }

            try {
                [$processed, $skipped] = $this->fileByShape($inbound, $messageData);
            } catch (Throwable $e) {
                // The key was spent before the work: a retry of this webhook has to be let through.
                Cache::forget(self::deliveryKey($inbound->messageId));

                throw $e;
            }

            if ($processed !== null) {
                $processedMessages[] = $processed;
            } else {
                $skippedMessages[] = $skipped;
            }
        }

        return [
            'messages' => $processedMessages,
            'skipped' => $skippedMessages,
        ];
    }

    /**
     * Group room, assistant 1:1 or lead 1:1, in that order.
     *
     * @return array{0: array<string, mixed>|null, 1: array<string, mixed>|null} the filed entry, or why nothing was filed
     */
    private function fileByShape(InboundMessage $inbound, array $messageData): array
    {
        if ($inbound->isGroup()) {
            $channel = $this->channels()->getOrCreateChannel($inbound->conversationJid);
            $message = new CreateGroupMessageAction(
                $this->receiver,
                $channel,
                $inbound,
                $messageData
            )->execute();

            return $message !== null
                ? [$this->channelEntry($message, $channel, $inbound), null]
                : [null, [
                    'message_id' => $inbound->messageId,
                    'conversation_type' => $inbound->conversationType->value,
                    'reason' => 'group not allow-listed or message has no content',
                ]];
        }

        $nothingToFile = [
            'message_id' => $inbound->messageId,
            'reason' => 'no content to file',
        ];

        if (CreateDirectAgentMessageAction::appliesTo($this->receiver, $inbound)) {
            $channel = $this->channels()->getOrCreateChannel(
                $inbound->conversationJid,
                $inbound->isFromMe ? null : $inbound->pushName
            );
            $message = new CreateDirectAgentMessageAction(
                $this->receiver,
                $channel,
                $inbound,
                $messageData
            )->execute();

            return $message !== null
                ? [[...$this->channelEntry($message, $channel, $inbound), 'mode' => 'assistant'], null]
                : [null, $nothingToFile];
        }

        $processed = new CreateLeadMessageAction(
            $this->receiver,
            $inbound,
            $messageData,
            $this->hijackSession
        )->execute();

        return $processed !== null ? [$processed, null] : [null, $nothingToFile];
    }

    /**
     * @return array<string, mixed>
     */
    private function channelEntry(Message $message, Channel $channel, InboundMessage $inbound): array
    {
        return [
            'message_id' => $message->getId(),
            'uuid' => $message->uuid,
            'channel_id' => $channel->getId(),
            'chat_jid' => $inbound->conversationJid,
            'parent_id' => $message->parent_id,
            'people_id' => $message->people_id,
            'is_from_me' => $inbound->isFromMe,
            'conversation_type' => $inbound->conversationType->value,
        ];
    }

    /**
     * The message a status-style event refers to.
     *
     * Resolved through InboundMessage rather than raw `key.remoteJid`: under lid addressing the raw
     * field holds the lid form while the message was filed under the phone form (`remoteJidAlt`),
     * so a direct slug build silently matches nothing and the update is lost.
     */
    private function resolveMessageForKey(array $eventData): ?Message
    {
        $inbound = InboundMessage::fromWebhookMessage($eventData);

        if ($inbound === null) {
            return null;
        }

        return $this->channels()->findMessageBySlug($inbound->messageId, $inbound->conversationJid);
    }

    protected function handleMessageUpdate(): array
    {
        $processedUpdates = [];

        foreach ($this->entries() as $updateData) {
            $message = $this->resolveMessageForKey((array) $updateData);

            if ($message === null) {
                continue;
            }

            $status = $updateData['update']['status'] ?? null;

            $content = $message->message;
            $content['status'] = $status;
            $content['raw_data_update'] = $updateData;

            $message->message = $content;
            $message->save();

            $processedUpdates[] = [
                'message_id' => $message->getId(),
                'uuid' => $message->uuid,
                'status' => $status,
            ];
        }

        return [
            'updates' => $processedUpdates,
        ];
    }

    protected function handleMessageDelete(): array
    {
        $processedDeletes = [];

        foreach ($this->payload['data']['keys'] ?? [] as $key) {
            $message = $this->resolveMessageForKey(['key' => (array) $key]);

            if ($message === null) {
                continue;
            }

            $message->is_deleted = true;
            $message->save();

            $processedDeletes[] = [
                'message_id' => $message->getId(),
                'uuid' => $message->uuid,
            ];
        }

        return [
            'deleted' => $processedDeletes,
        ];
    }

    protected function handleMessageReaction(): array
    {
        $processedReactions = [];

        foreach ($this->entries() as $reactionData) {
            $emoji = $reactionData['reaction']['text'] ?? null;

            if ($emoji === null) {
                continue;
            }

            $message = $this->resolveMessageForKey((array) $reactionData);

            if ($message === null) {
                continue;
            }

            $content = $message->message;
            $content['reaction'] = $emoji;
            $content['raw_data_reaction'] = $reactionData;

            $message->message = $content;
            $message->reactions_count = ($message->reactions_count ?? 0) + 1;
            $message->save();

            $processedReactions[] = [
                'message_id' => $message->getId(),
                'uuid' => $message->uuid,
                'reaction' => $emoji,
            ];
        }

        return [
            'reactions' => $processedReactions,
        ];
    }

    protected function handleMessageReceiptUpdate(): array
    {
        $processedReceipts = [];

        foreach ($this->entries() as $receiptData) {
            $receipt = $receiptData['receipt'] ?? [];
            $status = $receipt['status'] ?? null;

            if ($status === null) {
                continue;
            }

            $message = $this->resolveMessageForKey((array) $receiptData);

            if ($message === null) {
                continue;
            }

            $content = $message->message;
            $content['receipt_status'] = $status;
            $content['receipt_timestamp'] = $receipt['t'] ?? time();
            $content['raw_data_receipt'] = $receiptData;

            $message->message = $content;
            $message->save();

            $processedReceipts[] = [
                'message_id' => $message->getId(),
                'uuid' => $message->uuid,
                'receipt_status' => $status,
            ];
        }

        return [
            'receipts' => $processedReceipts,
        ];
    }

    protected function handleMessageSent(): array
    {
        $data = (array) ($this->payload['data'] ?? []);

        // Through InboundMessage, like the upsert: raw `key.remoteJid` is the lid under lid
        // addressing, which is another channel slug than the one the conversation is filed under.
        $inbound = InboundMessage::fromWebhookMessage($data);

        if ($inbound === null) {
            return [
                'error' => 'Missing chat JID',
            ];
        }

        $chatJid = $inbound->conversationJid;
        $messageId = $inbound->messageId;
        $status = $data['status'] ?? 'sent';

        // A send that went through the ledger was filed when it left; its status moves there.
        if (WaSenderInbound::deliveryOf($this->receiver, $data) !== null) {
            return [
                'chat_jid' => $chatJid,
                'status' => $status,
                'filed' => false,
            ];
        }

        $channel = $this->channels()->getOrCreateChannel($chatJid);
        $message = $this->channels()->findMessageBySlug($messageId, $chatJid);

        if ($message === null) {
            $messageContent = (array) ($data['message'] ?? []);

            $message = new CreateMessageAction(
                new MessageInput(
                    app: $this->receiver->app,
                    company: $this->receiver->company,
                    user: $this->receiver->user,
                    type: MessageTypeService::getOrCreate(
                        $this->receiver->app,
                        MessageTypeEnum::getMessageType($messageContent)->value
                    ),
                    message: [
                        ...AiChatMessagePayload::from([
                            'content' => MessageTypeEnum::extractText($messageContent),
                            'from_me' => true,
                            'from_ia' => false,
                            'raw_data' => $data,
                            'message_id' => $messageId,
                            'chat_jid' => $chatJid,
                        ])->toArray(),
                        'status' => $status,
                    ],
                    is_public: 1,
                    slug: ConversationChannelService::messageSlug($messageId, $chatJid),
                    tags: [$chatJid]
                )
            )->execute();
        } else {
            $message->message = [...$message->message, 'status' => $status];
            $message->save();
        }

        $channel->addMessage($message, $this->receiver->user);

        return [
            'message_id' => $message->getId(),
            'uuid' => $message->uuid,
            'channel_id' => $channel->getId(),
            'chat_jid' => $chatJid,
            'status' => $status,
        ];
    }

    protected function handleChatUpsert(): array
    {
        $processedChats = [];

        foreach ($this->payload['data'] ?? [] as $chatData) {
            $jid = $chatData['id'] ?? null;
            $name = $chatData['name'] ?? null;

            if (! $jid) {
                continue;
            }

            $channel = $this->channels()->getOrCreateChannel($jid, $name);
            $this->syncContact($jid, $name);

            $processedChats[] = [
                'channel_id' => $channel->getId(),
                'jid' => $jid,
                'name' => $channel->name,
                'is_group' => ConversationChannelService::isGroupJid($jid),
            ];
        }

        return [
            'chats' => $processedChats,
        ];
    }

    protected function handleChatUpdate(): array
    {
        $processedUpdates = [];

        foreach ($this->payload['data'] ?? [] as $updateData) {
            $jid = $updateData['id'] ?? null;

            if (! $jid) {
                continue;
            }

            $channel = $this->channels()->findChannel($jid);

            if ($channel === null) {
                $processedUpdates[] = [
                    'channel_id' => $this->channels()->getOrCreateChannel($jid)->getId(),
                    'jid' => $jid,
                    'status' => 'created',
                ];

                continue;
            }

            $updateFields = [];

            if (isset($updateData['name'])) {
                $updateFields['name'] = $updateData['name'];
                $this->syncContact($jid, (string) $updateData['name']);
            }

            if (isset($updateData['unreadCount'])) {
                $updateFields['metadata'] = [
                    'channel_id' => $channel->getId(),
                    'unread_count' => $updateData['unreadCount'],
                ];
            }

            if ($updateFields !== []) {
                $channel->update($updateFields);
            }

            $processedUpdates[] = [
                'channel_id' => $channel->getId(),
                'jid' => $jid,
                'updates' => $updateFields,
            ];
        }

        return [
            'updates' => $processedUpdates,
        ];
    }

    protected function handleChatDelete(): array
    {
        $processedDeletes = [];

        foreach ($this->payload['data'] ?? [] as $jid) {
            $channel = $this->channels()->findChannel($jid);

            if ($channel === null) {
                $processedDeletes[] = [
                    'jid' => $jid,
                    'status' => 'not_found',
                ];

                continue;
            }

            $channel->is_deleted = true;
            $channel->save();

            $processedDeletes[] = [
                'channel_id' => $channel->getId(),
                'jid' => $jid,
            ];
        }

        return [
            'deleted' => $processedDeletes,
        ];
    }

    protected function handleGroupUpsert(): array
    {
        // The live event nests the list under data.groups (capture 2026-08-19); older fixtures
        // put it straight on data.
        $data = $this->payload['data']['groups'] ?? $this->payload['data'] ?? [];

        $processedGroups = [];

        foreach ($data as $groupData) {
            $jid = $groupData['jid'] ?? null;
            $subject = $groupData['subject'] ?? null;

            if (! $jid) {
                continue;
            }

            $processedGroups[] = [
                'channel_id' => $this->channels()->getOrCreateChannel($jid, $subject)->getId(),
                'jid' => $jid,
                'subject' => $subject,
            ];
        }

        return [
            'groups' => $processedGroups,
        ];
    }

    protected function handleGroupUpdate(): array
    {
        $processedUpdates = [];

        foreach ($this->payload['data'] ?? [] as $updateData) {
            $jid = $updateData['jid'] ?? null;
            $channel = $jid ? $this->channels()->findChannel($jid) : null;

            if ($channel === null) {
                continue;
            }

            $updateFields = array_filter([
                'name' => $updateData['subject'] ?? null,
                'description' => $updateData['desc'] ?? null,
            ], static fn (mixed $value): bool => $value !== null);

            if ($updateFields !== []) {
                $channel->update($updateFields);
            }

            $processedUpdates[] = [
                'channel_id' => $channel->getId(),
                'jid' => $jid,
                'updates' => $updateFields,
            ];
        }

        return [
            'updates' => $processedUpdates,
        ];
    }

    protected function handleGroupParticipantsUpdate(): array
    {
        $data = $this->payload['data'] ?? [];

        $jid = $data['jid'] ?? null;
        $participants = $data['participants'] ?? [];
        $action = $data['action'] ?? null;

        if (! $jid || ! $action) {
            return ['error' => 'Missing group JID or action'];
        }

        $channel = $this->channels()->findChannel($jid)
            ?? $this->channels()->getOrCreateChannel($jid);

        foreach ($participants as $participantJid) {
            $this->syncContact((string) $participantJid);
        }

        return [
            'channel_id' => $channel->getId(),
            'group_jid' => $jid,
            'action' => $action,
            'participants' => $participants,
        ];
    }

    protected function handleContactUpsert(): array
    {
        $processedContacts = [];

        foreach ($this->payload['data'] ?? [] as $contactData) {
            $jid = $contactData['jid'] ?? null;
            $name = $contactData['name'] ?? null;
            $entry = [
                'jid' => $jid,
                'name' => $name ?? $contactData['notify'] ?? null,
            ];

            if ($jid && ConversationChannelService::isDirectJid($jid)) {
                $entry['channel_id'] = $this->channels()->getOrCreateChannel($jid, $name)->getId();
                $entry['people_id'] = $this->syncContact($jid, $name)?->getId();
            }

            $processedContacts[] = $entry;
        }

        return [
            'contacts' => $processedContacts,
        ];
    }

    protected function handleContactUpdate(): array
    {
        $processedUpdates = [];

        foreach ($this->payload['data'] ?? [] as $updateData) {
            $jid = $updateData['jid'] ?? null;
            $name = $updateData['name'] ?? null;
            $channel = $jid && $name && ConversationChannelService::isDirectJid($jid)
                ? $this->channels()->findChannel($jid)
                : null;

            if ($channel === null) {
                $processedUpdates[] = [
                    'jid' => $jid,
                    'updates' => $updateData,
                ];

                continue;
            }

            $peopleRecord = $this->syncContact((string) $jid, (string) $name);
            $channel->update(['name' => $name]);

            $processedUpdates[] = [
                'channel_id' => $channel->getId(),
                'jid' => $jid,
                'name' => $name,
                'people_id' => $peopleRecord?->getId(),
            ];
        }

        return [
            'updates' => $processedUpdates,
        ];
    }

    /**
     * Keeps the People record behind a 1:1 JID current. Group and newsletter JIDs stand for a room,
     * not a person, so they resolve to nobody.
     */
    private function syncContact(string $jid, ?string $name = null): ?People
    {
        if (! ConversationChannelService::isDirectJid($jid)) {
            return null;
        }

        return new CreatePeopleFromJidAction($this->receiver, $jid, $name)->execute();
    }

    private function channels(): ConversationChannelService
    {
        return $this->channelService ??= new ConversationChannelService($this->receiver);
    }
}
