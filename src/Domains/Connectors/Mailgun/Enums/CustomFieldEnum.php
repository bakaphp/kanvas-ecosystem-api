<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mailgun\Enums;

/**
 * Per-agent mailbox state. Lives on the agent because the agent is what a tool, a resolver or an
 * outbound send has in hand; the provider's `accounts()` reads it to make the mailbox a connection.
 */
enum CustomFieldEnum: string
{
    case MAILBOX_ADDRESS = 'MAILGUN_MAILBOX_ADDRESS';
    case MAILBOX_ACCESS = 'MAILGUN_MAILBOX_ACCESS';
}
