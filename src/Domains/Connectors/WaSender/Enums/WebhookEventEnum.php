<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WaSender\Enums;

enum WebhookEventEnum: string
{
    case MESSAGES_UPSERT = 'messages.upsert';
    case MESSAGES_UPDATE = 'messages.update';
    case MESSAGES_DELETE = 'messages.delete';
    case MESSAGES_REACTION = 'messages.reaction';
    case MESSAGE_RECEIPT_UPDATE = 'message-receipt.update';
    case MESSAGE_SENT = 'message.sent';

    // Inbound mirrors of messages.upsert. WaSender fans one message out across all of these —
    // a single group message arrives four times. We ingest from messages.upsert only (documented
    // as covering every message, incoming and outgoing) and recognise these so a session
    // subscribed outside ConnectWhatsAppSessionAction logs an intentional skip rather than
    // "Unknown event type" three times per message.
    case MESSAGES_RECEIVED = 'messages.received';
    case MESSAGES_GROUP_RECEIVED = 'messages-group.received';
    case MESSAGES_PERSONAL_RECEIVED = 'messages-personal.received';
    case MESSAGES_NEWSLETTER_RECEIVED = 'messages-newsletter.received';

    case CHATS_UPSERT = 'chats.upsert';
    case CHATS_UPDATE = 'chats.update';
    case CHATS_DELETE = 'chats.delete';

    case GROUPS_UPSERT = 'groups.upsert';
    case GROUPS_UPDATE = 'groups.update';
    case GROUP_PARTICIPANTS_UPDATE = 'group-participants.update';

    case CONTACTS_UPSERT = 'contacts.upsert';
    case CONTACTS_UPDATE = 'contacts.update';

    case SESSION_STATUS = 'session.status';
    case QRCODE_UPDATED = 'qrcode.updated';

    /**
     * Recognised but never acted on — messages.upsert already delivered the same message.
     *
     * @return array<int, self>
     */
    public static function duplicateMessageEvents(): array
    {
        return [
            self::MESSAGES_RECEIVED,
            self::MESSAGES_GROUP_RECEIVED,
            self::MESSAGES_PERSONAL_RECEIVED,
            self::MESSAGES_NEWSLETTER_RECEIVED,
        ];
    }

    public function isDuplicateMessageEvent(): bool
    {
        return in_array($this, self::duplicateMessageEvents(), true);
    }

    /**
     * What we ask WaSender to deliver. Narrower than the set we recognise.
     *
     * @return list<string>
     */
    public static function subscribable(): array
    {
        return array_values(array_map(
            static fn (self $event): string => $event->value,
            array_filter(self::cases(), static fn (self $event): bool => ! $event->isDuplicateMessageEvent()),
        ));
    }
}
