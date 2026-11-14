<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mailgun\Enums;

/**
 * Who gets an answer when they write to an agent's mailbox.
 *
 * RESTRICTED is the default: a public address wired straight into an LLM with the agent's full
 * toolset is a spam-funded bill and an unauthenticated caller. OPEN is opt-in, for agents that
 * are meant to be handed out as a contact address (inbound lead capture).
 *
 * CUSTOMER_LINE is not the agent's own mailbox at all: it is the company's address, which this agent
 * answers. Whoever writes is a customer and gets a lead, the thread is the company's, and the mail
 * goes out as the company's. It is how the agent a company's email rule named keeps answering.
 */
enum MailboxAccessEnum: string
{
    case RESTRICTED = 'restricted';
    case OPEN = 'open';
    case CUSTOMER_LINE = 'customer_line';

    public function allowsUnknownSenders(): bool
    {
        return $this !== self::RESTRICTED;
    }

    /**
     * The agent's own address: it writes as itself and keeps a thread of its own with each sender.
     */
    public function isPersonal(): bool
    {
        return $this !== self::CUSTOMER_LINE;
    }
}
