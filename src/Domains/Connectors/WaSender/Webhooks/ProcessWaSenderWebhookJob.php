<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WaSender\Webhooks;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Kanvas\Connectors\WaSender\Actions\DownloadMessageFileAction;
use Kanvas\Connectors\WaSender\Enums\MessageTypeEnum;
use Kanvas\Connectors\WaSender\Enums\WebhookEventEnum;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Customers\Actions\CreatePeopleAction;
use Kanvas\Guild\Customers\DataTransferObject\Address;
use Kanvas\Guild\Customers\DataTransferObject\Contact;
use Kanvas\Guild\Customers\DataTransferObject\People as PeopleDTO;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Actions\CreateMessageTypeAction;
use Kanvas\Social\MessagesTypes\DataTransferObject\MessageTypeInput;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Override;
use Spatie\LaravelData\DataCollection;

class ProcessWaSenderWebhookJob extends ProcessWebhookJob
{
    protected int $timeThresholdInSeconds = 5;

    #[Override]
    public function execute(): array
    {
        // Extract webhook data
        $payload = $this->webhookRequest->payload;
        $headers = $this->webhookRequest->headers;

        // Verify webhook signature if available
        $signature = $headers['x-webhook-signature'] ?? null;

        if ($signature) {
            $this->verifySignature(is_array($signature) ? $signature[0] : $signature);
        }

        // Get event type from payload
        $eventType = $payload['event'] ?? 'unknown';
        $this->timeThresholdInSeconds = $this->receiver->configuration['time_threshold_in_seconds'] ?? $this->timeThresholdInSeconds;

        // Process based on event type
        $result = match ($eventType) {
            WebhookEventEnum::MESSAGES_UPSERT->value => $this->handleMessageUpsert($payload),
            WebhookEventEnum::MESSAGES_UPDATE->value => $this->handleMessageUpdate($payload),
            WebhookEventEnum::MESSAGES_DELETE->value => $this->handleMessageDelete($payload),
            WebhookEventEnum::MESSAGES_REACTION->value => $this->handleMessageReaction($payload),
            WebhookEventEnum::MESSAGE_RECEIPT_UPDATE->value => $this->handleMessageReceiptUpdate($payload),
            WebhookEventEnum::MESSAGE_SENT->value => $this->handleMessageSent($payload),

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
     * Verify webhook signature
     */
    protected function verifySignature(string $signature): void
    {
        $webhookSecret = $this->receiver->configuration['webhook_secret'] ?? null;

        if (empty($webhookSecret) || $signature !== $webhookSecret) {
            throw new ValidationException('Invalid webhook signature', 401);
        }
    }

    /**
     * Handle messages.upsert event (new messages)
     */
    protected function handleMessageUpsert(array $payload): array
    {
        $data = $payload['data'] ?? [];

        $processedMessages = [];

        // If data is a direct message object, wrap it in an array
        if (isset($data['key'])) {
            $data = [$data];
        }

        foreach ($data as $messageData) {
            $key = $messageData['key'] ?? [];
            $messageContent = $messageData['message'] ?? [];

            $messageType = $this->getMessageType($messageContent);
            $isDocument = MessageTypeEnum::isDocumentType($messageType);
            $text = $this->extractMessageText($messageContent, $messageType);
            $chatJid = $key['remoteJid'] ?? null;
            $isFromMe = $key['fromMe'] ?? false;
            $messageId = $key['id'] ?? Str::uuid()->toString();

            // Create the message slug
            $messageSlug = $this->createMessageSlug($messageId, $chatJid);

            // Get or create a channel for this conversation
            $channel = $this->getOrCreateChannel($chatJid);

            // Find existing message or create a new one using CreateMessageAction
            $existingMessage = Message::where('uuid', $messageSlug)
                ->where('companies_id', $this->receiver->company->getId())
                ->where('apps_id', $this->receiver->app->getId())
                ->first();

            if ($existingMessage) {
                $message = $existingMessage;
            } else {
                // Get the appropriate message type
                $messageTypeModel = (new CreateMessageTypeAction(
                    new MessageTypeInput(
                        $this->receiver->app->getId(),
                        0,
                        $messageType,
                        $messageType,
                    )
                ))->execute();

                // Create the message using the action
                $messageInput = new MessageInput(
                    app: $this->receiver->app,
                    company: $this->receiver->company,
                    user: $this->receiver->user,
                    type: $messageTypeModel,
                    message: [
                        'content' => $text,
                        'raw_data' => $messageData,
                        'message_id' => $messageId,
                        'chat_jid' => $chatJid,
                        'from_me' => $isFromMe,
                    ],
                    is_public: 1,
                    slug: $messageSlug,
                    tags: [$chatJid]
                );

                $createMessageAction = new CreateMessageAction($messageInput);
                $message = $createMessageAction->execute();
            }

            $previousMessage = $channel->getPreviousMessage($message);
            $timeThresholdInSeconds = $this->timeThresholdInSeconds;

            if ($previousMessage && $previousMessage->messageType->verb === MessageTypeEnum::IMAGE->value && $message->messageType->verb === MessageTypeEnum::IMAGE->value && $previousMessage->id !== $message->id) {
                $timeDifference = $message->created_at->diffInSeconds($previousMessage->created_at);

                if ($timeDifference < $timeThresholdInSeconds) {
                    $previousMessageParent = $previousMessage->parent ?? $previousMessage;
                    $message->update(['parent_id' => $previousMessageParent->id]);
                }
            }

            // If the message is not from the user, process the contact
            if (! $isFromMe) {
                /**
                 * @todo we need to create users for each user and associate with people
                 */
                $people = $this->processContactFromMessage($chatJid, $messageData);
            }

            if (isset($people) && $people instanceof People) {
                // Associate the message with the contact
                $message->addEntity($people);
            }

            // Associate message with channel
            $channel->addMessage($message);
            $lastMessage = $channel->getLastMessage();
            $lastMessageParent = $lastMessage->parent ?? null;

            if ($isDocument) {
                new DownloadMessageFileAction(
                    $channel,
                    $message,
                )->execute();
            }

            // only fire for non-document messages
            if (! $isDocument) {
                $processDocument = false;
                if ($lastMessageParent !== null) {
                    $isLastMessageDocument = MessageTypeEnum::isDocumentType($lastMessageParent->messageType->verb);
                    $processDocument = $isLastMessageDocument && $text !== null && (trim(strtolower($text)) === 'process document' || trim(strtolower($text)) === 'process');
                }

                $channel->fireWorkflow(
                    WorkflowEnum::AFTER_ADDING_MESSAGE_TO_CHANNEL->value,
                    true,
                    [
                        'message' => $message,
                        'user' => $message->user,
                        'app' => $message->app,
                        'company' => $message->company,
                        'process_document' => $processDocument,
                        'lastMessageParentDocument' => $lastMessageParent !== null ? $lastMessageParent : null,
                    ]
                );
            }

            // Add to processed results
            $processedMessages[] = [
                'message_id' => $message->getId(),
                'uuid' => $message->uuid,
                'channel_id' => $channel->getId(),
                'chat_jid' => $chatJid,
                'text' => $text,
                'is_from_me' => $isFromMe,
                'type' => $messageType,
            ];
        }

        return [
            'messages' => $processedMessages,
        ];
    }

    /**
     * Handle messages.update event (message status updates)
     */
    protected function handleMessageUpdate(array $payload): array
    {
        $data = $payload['data'] ?? [];
        $channelId = $payload['data']['key']['remoteJid'] ?? null;

        $processedUpdates = [];
        $time = $payload['timestamp'] ?? time();
        $channel = $this->getOrCreateChannel($channelId);

        foreach ($data as $updateData) {
            $key = $updateData['key'] ?? [];
            $update = $updateData['update'] ?? [];

            $messageId = $key['id'] ?? null;
            $chatJid = $key['remoteJid'] ?? null;
            $status = $update['status'] ?? null;
            $messageTime = $update['timestamp'] ?? null;

            if ($messageId && $channelId) {
                // Find the message
                $messageSlug = $this->createMessageSlug($messageId, $channelId);

                $message = Message::where('uuid', $messageSlug)
                    ->where('companies_id', $this->receiver->company->getId())
                    ->where('apps_id', $this->receiver->app->getId())
                    ->first();

                if ($message) {
                    // Update message content
                    $messageContent = $message->message;
                    $messageContent['status'] = $status;
                    $messageContent['raw_data_update'] = $updateData;

                    $message->message = $messageContent;
                    $message->save();

                    $processedUpdates[] = [
                        'message_id' => $message->getId(),
                        'uuid' => $message->uuid,
                        'status' => $status,
                    ];
                }
            }
        }

        return [
            'updates' => $processedUpdates,
        ];
    }

    /**
     * Handle messages.delete event
     */
    protected function handleMessageDelete(array $payload): array
    {
        $data = $payload['data'] ?? [];

        $processedDeletes = [];
        $keys = $data['keys'] ?? [];

        foreach ($keys as $key) {
            $messageId = $key['id'] ?? null;
            $chatJid = $key['remoteJid'] ?? null;

            if ($messageId && $chatJid) {
                // Find the message
                $messageSlug = $this->createMessageSlug($messageId, $chatJid);
                $message = Message::where('uuid', $messageSlug)
                    ->where('companies_id', $this->receiver->company->getId())
                    ->where('apps_id', $this->receiver->app->getId())
                    ->first();

                if ($message) {
                    // Soft delete the message or mark as deleted
                    $message->is_deleted = true;
                    $message->save();

                    $processedDeletes[] = [
                        'message_id' => $message->getId(),
                        'uuid' => $message->uuid,
                    ];
                }
            }
        }

        return [
            'deleted' => $processedDeletes,
        ];
    }

    /**
     * Handle messages.reaction event
     */
    protected function handleMessageReaction(array $payload): array
    {
        $data = $payload['data'] ?? [];

        $processedReactions = [];

        foreach ($data as $reactionData) {
            $key = $reactionData['key'] ?? [];
            $reaction = $reactionData['reaction'] ?? [];

            $messageId = $key['id'] ?? null;
            $chatJid = $key['remoteJid'] ?? null;
            $emoji = $reaction['text'] ?? null;

            if ($messageId && $chatJid && $emoji) {
                // Find the message
                $messageSlug = $this->createMessageSlug($messageId, $chatJid);
                $message = Message::where('uuid', $messageSlug)
                    ->where('companies_id', $this->receiver->company->getId())
                    ->where('apps_id', $this->receiver->app->getId())
                    ->first();

                if ($message) {
                    // Update message content
                    $messageContent = $message->message;
                    $messageContent['reaction'] = $emoji;
                    $messageContent['raw_data_reaction'] = $reactionData;

                    $message->message = $messageContent;
                    // Increment reaction count
                    $message->reactions_count = ($message->reactions_count ?? 0) + 1;
                    $message->save();

                    $processedReactions[] = [
                        'message_id' => $message->getId(),
                        'uuid' => $message->uuid,
                        'reaction' => $emoji,
                    ];
                }
            }
        }

        return [
            'reactions' => $processedReactions,
        ];
    }

    /**
     * Handle message-receipt.update event
     */
    protected function handleMessageReceiptUpdate(array $payload): array
    {
        $data = $payload['data'] ?? [];

        $processedReceipts = [];

        foreach ($data as $receiptData) {
            $key = $receiptData['key'] ?? [];
            $receipt = $receiptData['receipt'] ?? [];

            $messageId = $key['id'] ?? null;
            $chatJid = $key['remoteJid'] ?? null;
            $status = $receipt['status'] ?? null;

            if ($messageId && $chatJid && $status) {
                // Find the message
                $messageSlug = $this->createMessageSlug($messageId, $chatJid);
                $message = Message::where('uuid', $messageSlug)
                    ->where('companies_id', $this->receiver->company->getId())
                    ->where('apps_id', $this->receiver->app->getId())
                    ->first();

                if ($message) {
                    // Update message content
                    $messageContent = $message->message;
                    $messageContent['receipt_status'] = $status;
                    $messageContent['receipt_timestamp'] = $receipt['t'] ?? time();
                    $messageContent['raw_data_receipt'] = $receiptData;

                    $message->message = $messageContent;
                    $message->save();

                    $processedReceipts[] = [
                        'message_id' => $message->getId(),
                        'uuid' => $message->uuid,
                        'receipt_status' => $status,
                    ];
                }
            }
        }

        return [
            'receipts' => $processedReceipts,
        ];
    }

    /**
     * Handle message.sent event
     */
    protected function handleMessageSent(array $payload): array
    {
        $data = $payload['data'] ?? [];

        $key = $data['key'] ?? [];
        $messageContent = $data['message'] ?? [];
        $status = $data['status'] ?? 'sent';

        $messageType = $this->getMessageType($messageContent);
        $text = $this->extractMessageText($messageContent, $messageType);
        $chatJid = $key['remoteJid'] ?? null;

        if ($chatJid === null) {
            return [
                'error' => 'Missing chat JID',
            ];
        }

        $messageId = $key['id'] ?? Str::uuid()->toString();
        //$isFromMe = $key['fromMe'] ?? false;
        $user = $this->receiver->user;

        // Create message slug
        $messageSlug = $this->createMessageSlug($messageId, $chatJid);

        // Get or create a channel for this conversation
        $channel = $this->getOrCreateChannel($chatJid);

        // Find existing message
        $message = Message::where('uuid', $messageSlug)
            ->where('companies_id', $this->receiver->company->getId())
            ->where('apps_id', $this->receiver->app->getId())
            ->first();

        if (! $message) {
            // Get the appropriate message type
            $messageTypeModel = MessageType::where('verb', $messageType)
                ->where('apps_id', $this->receiver->app->getId())
                ->first();

            if (! $messageTypeModel) {
                $messageTypeModel = MessageType::where('apps_id', $this->receiver->app->getId())
                    ->firstOrFail();
            }

            // Create new message using the action
            $messageInput = new MessageInput(
                app: $this->receiver->app,
                company: $this->receiver->company,
                user: $this->receiver->user,
                type: $messageTypeModel,
                message: [
                    'content' => $text,
                    'raw_data' => $data,
                    'message_id' => $messageId,
                    'chat_jid' => $chatJid,
                    'status' => $status,
                    'from_me' => true,
                ],
                is_public: 1,
                slug: $messageSlug,
                tags: [$chatJid]
            );

            $createMessageAction = new CreateMessageAction($messageInput);
            $message = $createMessageAction->execute();
        } else {
            // Update existing message
            $messageContent = $message->message;
            $messageContent['status'] = $status;
            $message->message = $messageContent;
            $message->save();
        }

        // Associate message with channel
        $channel->addMessage($message, $user);

        return [
            'message_id' => $message->getId(),
            'uuid' => $message->uuid,
            'channel_id' => $channel->getId(),
            'chat_jid' => $chatJid,
            'status' => $status,
        ];
    }

    /**
     * Handle chats.upsert event
     */
    protected function handleChatUpsert(array $payload): array
    {
        $data = $payload['data'] ?? [];

        $processedChats = [];

        foreach ($data as $chatData) {
            $jid = $chatData['id'] ?? null;
            $name = $chatData['name'] ?? null;

            if ($jid) {
                // Create or update channel for all conversation types
                $channel = $this->getOrCreateChannel($jid, $name);

                // Process contact for individual chats
                if (! $this->isGroupJid($jid) && ! $this->isChannelJid($jid)) {
                    $this->processContact($jid, $name);
                }

                $processedChats[] = [
                    'channel_id' => $channel->getId(),
                    'jid' => $jid,
                    'name' => $channel->name,
                    'is_group' => $this->isGroupJid($jid),
                ];
            }
        }

        return [
            'chats' => $processedChats,
        ];
    }

    /**
     * Handle chats.update event
     */
    protected function handleChatUpdate(array $payload): array
    {
        $data = $payload['data'] ?? [];

        $processedUpdates = [];

        foreach ($data as $updateData) {
            $jid = $updateData['id'] ?? null;

            if ($jid) {
                // Find the channel for any chat type
                $channel = Channel::where('slug', $this->createChannelSlug($jid))
                    ->where('companies_id', $this->receiver->company->getId())
                    ->where('apps_id', $this->receiver->app->getId())
                    ->first();

                if ($channel) {
                    $updateFields = [];

                    if (isset($updateData['name'])) {
                        $updateFields['name'] = $updateData['name'];

                        // Update contact name if it's an individual chat
                        if (! $this->isGroupJid($jid) && ! $this->isChannelJid($jid)) {
                            $this->processContact($jid, $updateData['name']);
                        }
                    }

                    if (isset($updateData['unreadCount'])) {
                        // Store this in channel metadata if needed
                        // For now, just log it
                        $updateFields['metadata'] = [
                            'channel_id' => $channel->getId(),
                            'unread_count' => $updateData['unreadCount'],
                        ];
                    }

                    if (! empty($updateFields)) {
                        $channel->update($updateFields);
                    }

                    $processedUpdates[] = [
                        'channel_id' => $channel->getId(),
                        'jid' => $jid,
                        'updates' => $updateFields,
                    ];
                } else {
                    // Create channel if it doesn't exist
                    $channel = $this->getOrCreateChannel($jid);

                    $processedUpdates[] = [
                        'channel_id' => $channel->getId(),
                        'jid' => $jid,
                        'status' => 'created',
                    ];
                }
            }
        }

        return [
            'updates' => $processedUpdates,
        ];
    }

    /**
     * Handle chats.delete event
     */
    protected function handleChatDelete(array $payload): array
    {
        $data = $payload['data'] ?? [];

        $processedDeletes = [];

        foreach ($data as $jid) {
            // Find the channel for any type of chat
            $channel = Channel::where('slug', $this->createChannelSlug($jid))
                ->where('companies_id', $this->receiver->company->getId())
                ->where('apps_id', $this->receiver->app->getId())
                ->first();

            if ($channel) {
                // Mark as deleted or archive
                $channel->is_deleted = true;
                $channel->save();

                $processedDeletes[] = [
                    'channel_id' => $channel->getId(),
                    'jid' => $jid,
                ];
            } else {
                $processedDeletes[] = [
                    'jid' => $jid,
                    'status' => 'not_found',
                ];
            }
        }

        return [
            'deleted' => $processedDeletes,
        ];
    }

    /**
     * Handle groups.upsert event
     */
    protected function handleGroupUpsert(array $payload): array
    {
        $data = $payload['data'] ?? [];

        $processedGroups = [];

        foreach ($data as $groupData) {
            $jid = $groupData['jid'] ?? null;
            $subject = $groupData['subject'] ?? null;

            if ($jid) {
                // Create or update channel
                $channel = $this->getOrCreateChannel($jid, $subject);

                $processedGroups[] = [
                    'channel_id' => $channel->getId(),
                    'jid' => $jid,
                    'subject' => $subject,
                ];
            }
        }

        return [
            'groups' => $processedGroups,
        ];
    }

    /**
     * Handle groups.update event
     */
    protected function handleGroupUpdate(array $payload): array
    {
        $data = $payload['data'] ?? [];

        $processedUpdates = [];

        foreach ($data as $updateData) {
            $jid = $updateData['jid'] ?? null;

            if ($jid) {
                // Find the channel
                $channel = Channel::where('slug', $this->createChannelSlug($jid))
                    ->where('companies_id', $this->receiver->company->getId())
                    ->where('apps_id', $this->receiver->app->getId())
                    ->first();

                if ($channel) {
                    $updateFields = [];

                    if (isset($updateData['subject'])) {
                        $updateFields['name'] = $updateData['subject'];
                    }

                    if (isset($updateData['desc'])) {
                        $updateFields['description'] = $updateData['desc'];
                    }

                    if (! empty($updateFields)) {
                        $channel->update($updateFields);
                    }

                    $processedUpdates[] = [
                        'channel_id' => $channel->getId(),
                        'jid' => $jid,
                        'updates' => $updateFields,
                    ];
                }
            }
        }

        return [
            'updates' => $processedUpdates,
        ];
    }

    /**
     * Handle group-participants.update event
     */
    protected function handleGroupParticipantsUpdate(array $payload): array
    {
        $data = $payload['data'] ?? [];

        $jid = $data['jid'] ?? null;
        $participants = $data['participants'] ?? [];
        $action = $data['action'] ?? null;

        if (! $jid || ! $action) {
            return ['error' => 'Missing group JID or action'];
        }

        // Find the channel
        $channel = Channel::where('slug', $this->createChannelSlug($jid))
            ->where('companies_id', $this->receiver->company->getId())
            ->where('apps_id', $this->receiver->app->getId())
            ->first();

        if (! $channel) {
            // Create the channel if it doesn't exist
            $channel = $this->getOrCreateChannel($jid);
        }

        // Process participants - create People records for them
        foreach ($participants as $participantJid) {
            if (! $this->isGroupJid($participantJid) && ! $this->isChannelJid($participantJid)) {
                $this->processContact($participantJid);
            }
        }

        return [
            'channel_id' => $channel->getId(),
            'group_jid' => $jid,
            'action' => $action,
            'participants' => $participants,
        ];
    }

    /**
     * Handle contacts.upsert event
     */
    protected function handleContactUpsert(array $payload): array
    {
        $data = $payload['data'] ?? [];

        $processedContacts = [];

        foreach ($data as $contactData) {
            $jid = $contactData['jid'] ?? null;
            $name = $contactData['name'] ?? null;

            if ($jid && ! $this->isGroupJid($jid) && ! $this->isChannelJid($jid)) {
                // Create or update People record for this contact
                $peopleRecord = $this->processContact($jid, $name);

                // Create or update channel
                $channel = $this->getOrCreateChannel($jid, $name);

                $processedContacts[] = [
                    'channel_id' => $channel->getId(),
                    'jid' => $jid,
                    'name' => $name ?? $contactData['notify'] ?? null,
                    'people_id' => $peopleRecord ? $peopleRecord->getId() : null,
                ];
            } else {
                $processedContacts[] = [
                    'jid' => $jid,
                    'name' => $name ?? $contactData['notify'] ?? null,
                ];
            }
        }

        return [
            'contacts' => $processedContacts,
        ];
    }

    /**
     * Handle contacts.update event
     */
    protected function handleContactUpdate(array $payload): array
    {
        $data = $payload['data'] ?? [];

        $processedUpdates = [];

        foreach ($data as $updateData) {
            $jid = $updateData['jid'] ?? null;

            if ($jid && ! $this->isGroupJid($jid) && ! $this->isChannelJid($jid)) {
                $name = $updateData['name'] ?? null;

                // Update People record if name exists
                if ($name) {
                    $peopleRecord = $this->processContact($jid, $name);
                }

                // Update channel for this contact if it exists
                $channel = Channel::where('slug', $this->createChannelSlug($jid))
                    ->where('companies_id', $this->receiver->company->getId())
                    ->where('apps_id', $this->receiver->app->getId())
                    ->first();

                if ($channel && $name) {
                    $channel->update(['name' => $name]);

                    $processedUpdates[] = [
                        'channel_id' => $channel->getId(),
                        'jid' => $jid,
                        'name' => $name,
                        'people_id' => isset($peopleRecord) ? $peopleRecord->getId() : null,
                    ];
                } else {
                    $processedUpdates[] = [
                        'jid' => $jid,
                        'updates' => $updateData,
                    ];
                }
            } else {
                $processedUpdates[] = [
                    'jid' => $jid,
                    'updates' => $updateData,
                ];
            }
        }

        return [
            'updates' => $processedUpdates,
        ];
    }

    /**
     * Handle session.status event
     */
    protected function handleSessionStatus(array $payload): array
    {
        $data = $payload['data'] ?? [];
        $status = $data['status'] ?? 'unknown';

        // Just log the status change
        Log::info('WaSender Session Status Changed', [
            'status' => $status,
            'timestamp' => $payload['timestamp'] ?? time(),
        ]);

        return [
            'status' => $status,
        ];
    }

    /**
     * Handle qrcode.updated event
     */
    protected function handleQRCodeUpdated(array $payload): array
    {
        $data = $payload['data'] ?? [];
        $qrCode = $data['qr'] ?? null;

        return [
            'has_qr' => ! empty($qrCode),
        ];
    }

    /**
     * Handle unknown event type
     */
    protected function handleUnknownEvent(array $payload): array
    {
        return [
            'processed' => false,
            'reason' => 'Unknown event type',
            'type' => $payload['type'] ?? 'unknown',
        ];
    }

    /**
     * Create a unique slug for messages
     */
    protected function createMessageSlug(string $messageId, string $jid): string
    {
        return 'wa-' . Str::slug($messageId . '-' . $jid);
    }

    /**
     * Create a unique slug for channels (both 1-to-1 and groups)
     */
    protected function createChannelSlug(string $jid): string
    {
        // Use different prefixes for groups and 1-to-1 channels for clarity
        if ($this->isGroupJid($jid)) {
            return 'wa-group-' . Str::slug($jid);
        } elseif ($this->isChannelJid($jid)) {
            return 'wa-channel-' . Str::slug($jid);
        } else {
            return 'wa-chat-' . Str::slug($jid);
        }
    }

    /**
     * Get an existing channel or create a new one (for any conversation type)
     * with database transaction locking to prevent race conditions
     */
    protected function getOrCreateChannel(string $jid, ?string $name = null): Channel
    {
        $slug = $this->createChannelSlug($jid);

        // Use a database transaction with locking
        return DB::transaction(function () use ($slug, $jid, $name) {
            // Attempt to find the channel with a lock for update
            $channel = Channel::where('slug', $slug)
                ->where('companies_id', $this->receiver->company->getId())
                ->where('apps_id', $this->receiver->app->getId())
                ->lockForUpdate()  // This applies a database-level lock
                ->first();

            if (! $channel) {
                $channel = new Channel();

                // Set different names and descriptions based on channel type
                if ($this->isGroupJid($jid)) {
                    $channel->name = $name ?? $this->extractGroupName($jid);
                    $channel->description = 'WhatsApp Group: ' . $jid;
                } elseif ($this->isChannelJid($jid)) {
                    $channel->name = $name ?? 'WhatsApp Channel: ' . str_replace('@newsletter', '', $jid);
                    $channel->description = 'WhatsApp Channel: ' . $jid;
                } else {
                    $channel->name = $name ?? 'WhatsApp Chat: ' . str_replace('@s.whatsapp.net', '', $jid);
                    $channel->description = 'WhatsApp Chat: ' . $jid;
                }

                $channel->slug = $slug;
                $channel->companies_id = $this->receiver->company->getId();
                $channel->apps_id = $this->receiver->app->getId();
                //$channel->users_id = $this->receiver->user->getId();
                //$channel->uuid = Str::uuid()->toString();
                $channel->save();
            } elseif ($name && $channel->name !== $name) {
                $channel->name = $name;
                $channel->save();
            }

            return $channel;
        }, 5); // 5 attempts with exponential backoff
    }

    /**
     * Process a contact from a message and create/update People record
     */
    protected function processContactFromMessage(string $jid, array $messageData): ?People
    {
        // Skip processing for group chats or channels
        if ($this->isGroupJid($jid) || $this->isChannelJid($jid)) {
            return null;
        }

        // Extract contact name if available in the message
        $pushName = $messageData['pushName'] ?? null;

        return $this->processContact($jid, $pushName);
    }

    /**
     * Process a contact and create/update People record
     */
    protected function processContact(string $jid, ?string $name = null): ?People
    {
        // Skip processing for group chats or channels
        if ($this->isGroupJid($jid) || $this->isChannelJid($jid)) {
            return null;
        }

        // Extract phone number from JID
        $phoneNumber = str_replace('@s.whatsapp.net', '', $jid);

        // Prepare name parts
        $displayName = $name ?? $this->extractContactName($jid);
        $nameParts = explode(' ', $displayName, 2);
        $firstName = $nameParts[0] ?? 'WhatsApp';
        $lastName = $nameParts[1] ?? 'Contact';

        $contactData = [
            [
                'value' => $phoneNumber,
                'contacts_types_id' => ContactTypeEnum::CELLPHONE->value,
                'weight' => 100,
            ],
        ];

        // Create address data (empty collection)
        //$addressData = new DataCollection(Address::class, []);

        // Create People DTO
        $peopleDto = new PeopleDTO(
            app: $this->receiver->app,
            branch: $this->receiver->company->defaultBranch,
            user: $this->receiver->user,
            firstname: $firstName,
            contacts: Contact::collect($contactData, DataCollection::class),
            address: Address::collect([], DataCollection::class),
            lastname: $lastName,
            custom_fields: [
                'whatsapp_jid' => $jid,
            ],
            tags: ['whatsapp', 'wa-contact']
        );

        $createAction = new CreatePeopleAction($peopleDto);

        return $createAction->execute();
    }

    /**
     * Extract contact name from JID
     */
    protected function extractContactName(string $jid): string
    {
        // Remove @s.whatsapp.net suffix if present
        $jid = str_replace('@s.whatsapp.net', '', $jid);

        // For groups and channels, try to extract a readable name
        if (strpos($jid, '@g.us') !== false) {
            return $this->extractGroupName($jid);
        } elseif (strpos($jid, '@newsletter') !== false) {
            return 'WhatsApp Channel: ' . str_replace('@newsletter', '', $jid);
        }

        // For individual contacts, create a name with phone number
        return 'WhatsApp Chat: ' . $jid;
    }

    /**
     * Extract group name from group JID
     */
    protected function extractGroupName(string $jid): string
    {
        // Remove @g.us suffix if present
        $jid = str_replace('@g.us', '', $jid);

        // Try to get a more readable format
        $parts = explode('-', $jid);
        if (count($parts) >= 2) {
            return 'WhatsApp Group: ' . substr($parts[0], 0, 5) . '...' . substr($parts[1], 0, 5);
        }

        return 'WhatsApp Group: ' . $jid;
    }

    /**
     * Check if a JID is for a group
     */
    protected function isGroupJid(string $jid): bool
    {
        return strpos($jid, '@g.us') !== false;
    }

    /**
     * Check if a JID is for a channel
     */
    protected function isChannelJid(string $jid): bool
    {
        return strpos($jid, '@newsletter') !== false;
    }

    /**
     * Determine message type from content
     */
    protected function getMessageType(array $messageContent): string
    {
        if (isset($messageContent['conversation'])) {
            return 'whatsapp-text';
        } elseif (isset($messageContent['imageMessage'])) {
            return 'whatsapp-image';
        } elseif (isset($messageContent['videoMessage'])) {
            return 'whatsapp-video';
        } elseif (isset($messageContent['documentMessage'])) {
            return 'whatsapp-document';
        } elseif (isset($messageContent['audioMessage'])) {
            return 'whatsapp-audio';
        } elseif (isset($messageContent['stickerMessage'])) {
            return 'whatsapp-sticker';
        } elseif (isset($messageContent['contactMessage'])) {
            return 'whatsapp-contact';
        } elseif (isset($messageContent['locationMessage'])) {
            return 'whatsapp-location';
        } else {
            return 'whatsapp-unknown';
        }
    }

    /**
     * Extract text content from message
     */
    protected function extractMessageText(array $messageContent, string $messageType): ?string
    {
        return match ($messageType) {
            'text' => $messageContent['conversation'] ?? $messageContent['extendedTextMessage']['text'] ?? null,
            'image' => $messageContent['imageMessage']['caption'] ?? null,
            'video' => $messageContent['videoMessage']['caption'] ?? null,
            'document' => $messageContent['documentMessage']['caption'] ?? null,
            'contact' => $messageContent['contactMessage']['displayName'] ?? null,
            'location' => $messageContent['locationMessage']['name'] ?? null,
            default => null,
        };
    }

    /**
     * Get the message type ID for WaSender message types
     * Maps WaSender message types to your internal message type IDs
     */
    protected function getWasenderMessageTypeId(string $wasenderType): int
    {
        // Get the default message type ID from the receiver configuration
        $defaultMessageTypeId = (int) ($this->receiver->configuration['default_message_type_id'] ?? 1);

        // You can create a mapping between WaSender types and your internal message type IDs
        $typeMapping = $this->receiver->configuration['message_type_mapping'] ?? [];

        // If a mapping exists for this type, use it, otherwise use the default
        return (int) ($typeMapping[$wasenderType] ?? $defaultMessageTypeId);
    }
}
