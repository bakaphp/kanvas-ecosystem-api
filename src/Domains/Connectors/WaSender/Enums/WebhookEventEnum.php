<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WaSender\Enums;

enum WebhookEventEnum: string
{
    // Message events
    case MESSAGES_UPSERT = 'messages.upsert';
    case MESSAGES_UPDATE = 'messages.update';
    case MESSAGES_DELETE = 'messages.delete';
    case MESSAGES_REACTION = 'messages.reaction';
    case MESSAGE_RECEIPT_UPDATE = 'message-receipt.update';
    case MESSAGE_SENT = 'message.sent';

    // Chat events
    case CHATS_UPSERT = 'chats.upsert';
    case CHATS_UPDATE = 'chats.update';
    case CHATS_DELETE = 'chats.delete';

    // Group events
    case GROUPS_UPSERT = 'groups.upsert';
    case GROUPS_UPDATE = 'groups.update';
    case GROUP_PARTICIPANTS_UPDATE = 'group-participants.update';

    // Contact events
    case CONTACTS_UPSERT = 'contacts.upsert';
    case CONTACTS_UPDATE = 'contacts.update';

    // Session events
    case SESSION_STATUS = 'session.status';
    case QRCODE_UPDATED = 'qrcode.updated';
}
