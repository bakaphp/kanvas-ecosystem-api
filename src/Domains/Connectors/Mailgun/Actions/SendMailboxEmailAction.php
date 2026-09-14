<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mailgun\Actions;

use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Social\Messages\Models\Message;

/**
 * Ships an agent's reply to an already-persisted Message from the agent's own address.
 *
 * Sourcing recipient, body and threading headers from the stored message is what lets the same send
 * run inline (live auto-reply) or later (human approval of a locked draft). In-Reply-To/References
 * keep the reply inside the sender's existing thread rather than opening a new one every turn.
 */
class SendMailboxEmailAction
{
    public function __construct(
        private readonly Agent $agent,
        private readonly Message $outboundMessage,
        private readonly string $subject,
    ) {
    }

    public function execute(): string
    {
        $message = $this->outboundMessage;
        $content = (string) ($message->message['content'] ?? '');
        $recipient = (string) ($message->message['chat_jid'] ?? '');

        $inReplyTo = (string) ($message->message['email_message_id'] ?? '');
        $references = trim((string) ($message->message['email_references'] ?? '') . ' ' . $inReplyTo);

        return new SendAsAgentMailboxAction(
            agent: $this->agent,
            to: $recipient,
            subject: $this->subject,
            markdownBody: $content,
            headers: [
                'In-Reply-To' => $inReplyTo,
                'References' => trim($references),
            ],
        )->execute();
    }
}
