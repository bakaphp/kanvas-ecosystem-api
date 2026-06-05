<?php

declare(strict_types=1);

namespace Kanvas\Connectors\RespondIO\Enums;

enum WebhookEventEnum: string
{
    case MESSAGE_RECEIVED = 'message.received';
    case MESSAGE_SENT = 'message.sent';
    case COMMENT_CREATED = 'comment.created';
    case CONVERSATION_OPENED = 'conversation.opened';
    case CONVERSATION_CLOSED = 'conversation.closed';
    case CONTACT_UPDATED = 'contact.updated';
    case CONTACT_TAG_UPDATED = 'contact.tag.updated';
    case CONTACT_ASSIGNEE_UPDATED = 'contact.assignee.updated';
    case CONTACT_LIFECYCLE_UPDATED = 'contact.lifecycle.updated';
    case CALL_ENDED = 'call.ended';
}
