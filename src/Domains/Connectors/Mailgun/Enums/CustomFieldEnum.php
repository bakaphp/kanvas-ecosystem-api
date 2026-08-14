<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mailgun\Enums;

/**
 * Per-agent mailbox state. Lives on the agent (not the receiver) because the agent is what a tool,
 * a resolver or an outbound send has in hand — the receiver is looked up from RECEIVER_ID.
 */
enum CustomFieldEnum: string
{
    case RECEIVER_ID = 'MAILGUN_RECEIVER_ID';
    case ROUTE_ID = 'MAILGUN_ROUTE_ID';
    case MAILBOX_ADDRESS = 'MAILGUN_MAILBOX_ADDRESS';
    case MAILBOX_ACCESS = 'MAILGUN_MAILBOX_ACCESS';
}
