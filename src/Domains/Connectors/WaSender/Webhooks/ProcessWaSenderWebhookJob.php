<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WaSender\Webhooks;

use Illuminate\Support\Facades\Cache;
use Kanvas\Connectors\WaSender\Actions\CreateDirectAgentMessageAction;
use Kanvas\Connectors\WaSender\Actions\CreateGroupMessageAction;
use Kanvas\Connectors\WaSender\Actions\CreateLeadMessageAction;
use Kanvas\Connectors\WaSender\Actions\CreatePeopleFromJidAction;
use Kanvas\Connectors\WaSender\DataTransferObject\InboundMessage;
use Kanvas\Connectors\WaSender\Enums\ConversationTypeEnum;
use Kanvas\Connectors\WaSender\Enums\MessageTypeEnum;
use Kanvas\Connectors\WaSender\Enums\WebhookEventEnum;
use Kanvas\Connectors\WaSender\Services\ConversationChannelService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\DataTransferObject\AiChatMessagePayload;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Override;

#[WorkflowAction(
    name: 'WhatsApp Inbound Webhook',
    description: 'Receiver for WhatsApp: files inbound messages, edits, deletions and reactions against the '
        . 'right channel and lead, downloads any media, and notifies the lead\'s stakeholders. This is '
        . 'how WhatsApp traffic ARRIVES — it replies to nobody. Attach a responder or an agent step to '
        . 'the resulting message if something should happen with it.',
    integration: IntegrationsEnum::WASENDER,
)]
class ProcessWaSenderWebhookJob extends ProcessWebhookJob
{
    private const int DEDUPE_TTL_SECONDS = 600;

    protected bool $hijackSession = false;
    private ?ConversationChannelService $channelService = null;

    #[Override]
    public function execute(): array
    {
        $payload = $this->webhookRequest->payload;
        $headers = $this->webhookRequest->headers;

        $signature = $headers['x-webhook-signature'] ?? null;

        if ($signature) {
            $this->verifySignature(is_array($signature) ? $signature[0] : $signature);
        }

        $eventType = $payload['event'] ?? 'unknown';

        //hijack session
        if ($this->receiver->company->get('allow_session_hijack', false)
            && $this->receiver->company->get('overwrite_phone_number') !== null
            && isset($payload['data']['messages']['remoteJid'])) {
            $overwriteConfig = $this->receiver->company->get('overwrite_phone_number');
            $originalRemoteJid = $payload['data']['messages']['remoteJid'];

            if (isset($overwriteConfig[$originalRemoteJid])) {
                $newPhone = $overwriteConfig[$originalRemoteJid];
                $this->hijackSession = true;
                // Override phone number in both locations
                $payload['data']['messages']['remoteJid'] = $newPhone;
                $payload['data']['messages']['key']['remoteJid'] = $newPhone;
            }
        }
        $result = match ($eventType) {
            WebhookEventEnum::MESSAGES_UPSERT->value => $this->handleMessageUpsert($payload),
            WebhookEventEnum::MESSAGES_UPDATE->value => $this->handleMessageUpdate($payload),
            WebhookEventEnum::MESSAGES_DELETE->value => $this->handleMessageDelete($payload),
            WebhookEventEnum::MESSAGES_REACTION->value => $this->handleMessageReaction($payload),
            WebhookEventEnum::MESSAGE_RECEIPT_UPDATE->value => $this->handleMessageReceiptUpdate($payload),
            WebhookEventEnum::MESSAGE_SENT->value => $this->handleMessageSent($payload),

            WebhookEventEnum::MESSAGES_RECEIVED->value,
            WebhookEventEnum::MESSAGES_GROUP_RECEIVED->value,
            WebhookEventEnum::MESSAGES_PERSONAL_RECEIVED->value,
            WebhookEventEnum::MESSAGES_NEWSLETTER_RECEIVED->value => $this->handleDuplicateMessageEvent($eventType),

            WebhookEventEnum::CHATS_UPSERT->value => $this->handleChatUpsert($payload),
            WebhookEventEnum::CHATS_UPDATE->value => $this->handleChatUpdate($payload),
            WebhookEventEnum::CHATS_DELETE->value => $this->handleChatDelete($payload),

            WebhookEventEnum::GROUPS_UPSERT->value => $this->handleGroupUpsert($payload),
            WebhookEventEnum::GROUPS_UPDATE->value => $this->handleGroupUpdate($payload),
            WebhookEventEnum::GROUP_PARTICIPANTS_UPDATE->value => $this->handleGroupParticipantsUpdate($payload),

            WebhookEventEnum::CONTACTS_UPSERT->value => $this->handleContactUpsert($payload),
            WebhookEventEnum::CONTACTS_UPDATE->value => $this->handleContactUpdate($payload),

            WebhookEventEnum::SESSION_STATUS->value => $this->handleSessionStatus($payload),
            WebhookEventEnum::QRCODE_UPDATED->value => $this->handleQRCodeUpdated($payload),

            default => $this->handleUnknownEvent($payload),
        };

        return [
            'message' => 'WaSender webhook processed successfully',
            'event_type' => $eventType,
            'result' => $result,
        ];
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

        return Cache::add('wasender:msg:' . $messageId, true, self::DEDUPE_TTL_SECONDS);
    }

    protected function verifySignature(string $signature): void
    {
        $webhookSecret = $this->receiver->configuration['webhook_secret'] ?? null;

        if (empty($webhookSecret) || $signature !== $webhookSecret) {
            throw new ValidationException('Invalid webhook signature', 401);
        }
    }

    protected function handleMessageUpsert(array $payload): array
    {
        $data = $payload['data'] ?? [];

        $processedMessages = [];

        // If data is a direct message object, wrap it in an array
        if (isset($data['key'])) {
            $data = [$data];
        }
        $skippedMessages = [];

        foreach ($data as $messageData) {
            $inbound = InboundMessage::fromWebhookMessage((array) $messageData);

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

            if (! $this->isFirstDelivery($inbound->messageId)) {
                $skippedMessages[] = [
                    'message_id' => $inbound->messageId,
                    'reason' => 'duplicate delivery',
                ];

                continue;
            }

            if ($inbound->isGroup()) {
                $groupChannel = $this->channels()->getOrCreateChannel($inbound->conversationJid);

                $groupMessage = new CreateGroupMessageAction(
                    $this->receiver,
                    $groupChannel,
                    $inbound,
                    (array) $messageData
                )->execute();

                if ($groupMessage === null) {
                    $skippedMessages[] = [
                        'message_id' => $inbound->messageId,
                        'conversation_type' => $inbound->conversationType->value,
                        'reason' => 'group not allow-listed or message has no content',
                    ];

                    continue;
                }

                $processedMessages[] = [
                    'message_id' => $groupMessage->getId(),
                    'uuid' => $groupMessage->uuid,
                    'channel_id' => $groupChannel->getId(),
                    'chat_jid' => $inbound->conversationJid,
                    'parent_id' => $groupMessage->parent_id,
                    'people_id' => $groupMessage->people_id,
                    'is_from_me' => $inbound->isFromMe,
                    'conversation_type' => $inbound->conversationType->value,
                ];

                continue;
            }

            if (CreateDirectAgentMessageAction::appliesTo($this->receiver, $inbound)) {
                $directChannel = $this->channels()->getOrCreateChannel(
                    $inbound->conversationJid,
                    $inbound->isFromMe ? null : $inbound->pushName
                );

                $directMessage = new CreateDirectAgentMessageAction(
                    $this->receiver,
                    $directChannel,
                    $inbound,
                    (array) $messageData
                )->execute();

                if ($directMessage === null) {
                    $skippedMessages[] = [
                        'message_id' => $inbound->messageId,
                        'reason' => 'no content to file',
                    ];

                    continue;
                }

                $processedMessages[] = [
                    'message_id' => $directMessage->getId(),
                    'uuid' => $directMessage->uuid,
                    'channel_id' => $directChannel->getId(),
                    'chat_jid' => $inbound->conversationJid,
                    'parent_id' => $directMessage->parent_id,
                    'people_id' => $directMessage->people_id,
                    'is_from_me' => $inbound->isFromMe,
                    'conversation_type' => $inbound->conversationType->value,
                    'mode' => 'assistant',
                ];

                continue;
            }

            $processed = new CreateLeadMessageAction(
                $this->receiver,
                $inbound,
                (array) $messageData,
                $this->hijackSession
            )->execute();

            if ($processed === null) {
                $skippedMessages[] = [
                    'message_id' => $inbound->messageId,
                    'reason' => 'no content to file',
                ];

                continue;
            }

            $processedMessages[] = $processed;
        }

        return [
            'messages' => $processedMessages,
            'skipped' => $skippedMessages,
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

    protected function handleMessageUpdate(array $payload): array
    {
        $processedUpdates = [];

        foreach ($payload['data'] ?? [] as $updateData) {
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

    protected function handleMessageDelete(array $payload): array
    {
        $processedDeletes = [];

        foreach ($payload['data']['keys'] ?? [] as $key) {
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

    protected function handleMessageReaction(array $payload): array
    {
        $processedReactions = [];

        foreach ($payload['data'] ?? [] as $reactionData) {
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

    protected function handleMessageReceiptUpdate(array $payload): array
    {
        $processedReceipts = [];

        foreach ($payload['data'] ?? [] as $receiptData) {
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

    protected function handleMessageSent(array $payload): array
    {
        $data = $payload['data'] ?? [];

        $messageContent = $data['message'] ?? [];
        $status = $data['status'] ?? 'sent';

        $messageType = MessageTypeEnum::getMessageType($messageContent)->value;
        $text = MessageTypeEnum::extractText($messageContent);

        // Resolved the same way as the inbound path: reading raw `key.remoteJid` here while
        // upsert read `remoteJidAlt` built two different channel slugs under lid addressing, so
        // our own replies landed in a second channel.
        $inbound = InboundMessage::fromWebhookMessage((array) $data);

        if ($inbound === null) {
            return [
                'error' => 'Missing chat JID',
            ];
        }

        $chatJid = $inbound->conversationJid;
        $messageId = $inbound->messageId;
        $user = $this->receiver->user;

        $channel = $this->channels()->getOrCreateChannel($chatJid);
        $message = $this->channels()->findMessageBySlug($messageId, $chatJid);

        if (! $message) {
            $messageTypeModel = MessageType::where('verb', $messageType)
                ->where('apps_id', $this->receiver->app->getId())
                ->first();

            if (! $messageTypeModel) {
                $messageTypeModel = MessageType::where('apps_id', $this->receiver->app->getId())
                    ->firstOrFail();
            }

            $messageInput = new MessageInput(
                app: $this->receiver->app,
                company: $this->receiver->company,
                user: $this->receiver->user,
                type: $messageTypeModel,
                message: [
                    ...AiChatMessagePayload::from([
                        'content' => $text,
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
            );

            $createMessageAction = new CreateMessageAction($messageInput);
            $message = $createMessageAction->execute();
        } else {
            $messageContent = $message->message;
            $messageContent['status'] = $status;
            $message->message = $messageContent;
            $message->save();
        }

        $channel->addMessage($message, $user);

        return [
            'message_id' => $message->getId(),
            'uuid' => $message->uuid,
            'channel_id' => $channel->getId(),
            'chat_jid' => $chatJid,
            'status' => $status,
        ];
    }

    protected function handleChatUpsert(array $payload): array
    {
        $data = $payload['data'] ?? [];

        $processedChats = [];

        foreach ($data as $chatData) {
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

    protected function handleChatUpdate(array $payload): array
    {
        $processedUpdates = [];

        foreach ($payload['data'] ?? [] as $updateData) {
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

    protected function handleChatDelete(array $payload): array
    {
        $processedDeletes = [];

        foreach ($payload['data'] ?? [] as $jid) {
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

    protected function handleGroupUpsert(array $payload): array
    {
        // The live event nests the list under data.groups (capture 2026-08-19); older fixtures
        // put it straight on data.
        $data = $payload['data']['groups'] ?? $payload['data'] ?? [];

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

    protected function handleGroupUpdate(array $payload): array
    {
        $processedUpdates = [];

        foreach ($payload['data'] ?? [] as $updateData) {
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

    protected function handleGroupParticipantsUpdate(array $payload): array
    {
        $data = $payload['data'] ?? [];

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

    protected function handleContactUpsert(array $payload): array
    {
        $processedContacts = [];

        foreach ($payload['data'] ?? [] as $contactData) {
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

    protected function handleContactUpdate(array $payload): array
    {
        $processedUpdates = [];

        foreach ($payload['data'] ?? [] as $updateData) {
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

    protected function handleSessionStatus(array $payload): array
    {
        $data = $payload['data'] ?? [];
        $status = $data['status'] ?? 'unknown';

        return [
            'status' => $status,
        ];
    }

    protected function handleQRCodeUpdated(array $payload): array
    {
        $data = $payload['data'] ?? [];
        $qrCode = $data['qr'] ?? null;

        return [
            'has_qr' => ! empty($qrCode),
        ];
    }

    protected function handleUnknownEvent(array $payload): array
    {
        return [
            'processed' => false,
            'reason' => 'Unknown event type',
            'type' => $payload['type'] ?? 'unknown',
        ];
    }

    /**
     * WaSender mirrors every inbound message across messages.received / messages-group.received /
     * messages-personal.received on top of messages.upsert. We ingest from upsert only.
     */
    protected function handleDuplicateMessageEvent(string $eventType): array
    {
        return [
            'processed' => false,
            'reason' => 'Duplicate of messages.upsert, ignored by design',
            'type' => $eventType,
        ];
    }

    private function channels(): ConversationChannelService
    {
        return $this->channelService ??= new ConversationChannelService($this->receiver);
    }
}
