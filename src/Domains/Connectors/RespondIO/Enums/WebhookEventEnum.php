<?php

declare(strict_types=1);

namespace Kanvas\Connectors\RespondIO\Enums;

enum WebhookEventEnum: string
{
    case NEW_INCOMING_MESSAGE = 'new_incoming_message';
    case NEW_OUTGOING_MESSAGE = 'new_outgoing_message';
    case CONTACT_UPDATED = 'contact_updated';
    case CONTACT_CREATED = 'contact_created';
    case CONVERSATION_OPENED = 'conversation_opened';
    case CONVERSATION_CLOSED = 'conversation_closed';
}
