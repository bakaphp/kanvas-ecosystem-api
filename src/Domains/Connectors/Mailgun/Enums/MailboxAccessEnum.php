<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mailgun\Enums;

/**
 * Who gets an answer when they write to an agent's mailbox.
 *
 * RESTRICTED is the default: a public address wired straight into an LLM with the agent's full
 * toolset is a spam-funded bill and an unauthenticated caller. OPEN is opt-in, for agents that
 * are meant to be handed out as a contact address (inbound lead capture).
 */
enum MailboxAccessEnum: string
{
    case RESTRICTED = 'restricted';
    case OPEN = 'open';

    public function allowsUnknownSenders(): bool
    {
        return $this === self::OPEN;
    }
}
