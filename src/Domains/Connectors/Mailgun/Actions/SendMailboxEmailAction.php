<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mailgun\Actions;

use Kanvas\Connectors\Mailgun\Client;
use Kanvas\Connectors\Mailgun\Services\AgentMailboxService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Notifications\Support\MarkdownEmailRenderer;
use Kanvas\Social\Messages\Models\Message;

/**
 * Ships an agent's reply from the agent's own address through Mailgun's API.
 *
 * Not the notification/SMTP path the shared lead inbox uses: only the account that owns the domain
 * can authorize `sofia@agents.acme.com` as a From, and the company's SMTP relay is usually a
 * different domain entirely — which gets the mail rejected or spam-foldered. Sending through the
 * API also lets us set In-Reply-To/References, so the reply lands inside the sender's existing
 * thread rather than opening a new one every turn.
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
        $mailboxService = new AgentMailboxService();
        $address = $mailboxService->addressFor($this->agent);

        if ($address === null) {
            throw new ValidationException('Agent ' . (int) $this->agent->getId() . ' has no mailbox to send from.');
        }

        $message = $this->outboundMessage;
        $content = (string) ($message->message['content'] ?? '');
        $recipient = (string) ($message->message['chat_jid'] ?? '');

        if ($content === '') {
            throw new ValidationException('Cannot send an empty email');
        }

        if ($recipient === '') {
            throw new ValidationException('Outbound email has no recipient');
        }

        $inReplyTo = (string) ($message->message['email_message_id'] ?? '');
        $references = trim((string) ($message->message['email_references'] ?? '') . ' ' . $inReplyTo);

        return new Client($this->agent->app)->sendMessage(
            domain: $mailboxService->domainFor($this->agent),
            from: $this->agent->name . ' <' . $address . '>',
            to: $recipient,
            subject: $this->subject,
            text: $content,
            html: MarkdownEmailRenderer::toEmailHtml($content),
            headers: [
                'In-Reply-To' => $inReplyTo,
                'References' => trim($references),
                'Reply-To' => $address,
            ],
        );
    }
}
