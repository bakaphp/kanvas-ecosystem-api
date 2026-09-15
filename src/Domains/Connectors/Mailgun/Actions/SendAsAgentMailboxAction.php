<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mailgun\Actions;

use Baka\Http\SafeUrlFetcher;
use Kanvas\Connectors\Mailgun\Client;
use Kanvas\Connectors\Mailgun\Services\AgentMailboxService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Notifications\Support\MarkdownEmailRenderer;

/**
 * Ships one email from an agent's own address through Mailgun's API.
 *
 * The From is the whole point: only the account that owns the domain can authorize
 * `sofia@agents.acme.com`, and the company's SMTP relay is usually a different domain entirely —
 * which gets the mail rejected or spam-foldered. That is why an agent identity cannot be a header
 * swap on the notification/SMTP path; it has to be a different transport.
 *
 * Everything above this (which agent, which lead, what to write) belongs to the caller. Reply-To is
 * always the mailbox so the answer comes back to the agent's own inbox and the agent handles it.
 */
class SendAsAgentMailboxAction
{
    /**
     * @param array<int, string> $cc
     * @param array<string, string> $headers
     * @param array<int, string> $attachmentUrls
     */
    public function __construct(
        private readonly Agent $agent,
        private readonly string $to,
        private readonly string $subject,
        private readonly string $markdownBody,
        private readonly array $cc = [],
        private readonly array $headers = [],
        private readonly array $attachmentUrls = [],
    ) {
    }

    public function execute(): string
    {
        $mailboxService = new AgentMailboxService();
        $address = $mailboxService->addressFor($this->agent);

        if ($address === null) {
            throw new ValidationException('Agent ' . (int) $this->agent->getId() . ' has no mailbox to send from.');
        }

        if (trim($this->markdownBody) === '') {
            throw new ValidationException('Cannot send an empty email');
        }

        if (trim($this->to) === '') {
            throw new ValidationException('Outbound email has no recipient');
        }

        return new Client($this->agent->app)->sendMessage(
            domain: $mailboxService->domainFor($this->agent),
            from: $this->agent->name . ' <' . $address . '>',
            to: $this->to,
            subject: $this->subject,
            text: $this->markdownBody,
            html: MarkdownEmailRenderer::toEmailHtml($this->markdownBody),
            // Reply-To is not caller-overridable: an answer that lands anywhere but the agent's own
            // inbox never reaches the agent, which is the entire point of giving it an address.
            headers: array_merge($this->headers, ['Reply-To' => $address]),
            cc: $this->cc,
            attachments: $this->fetchAttachments(),
        );
    }

    /**
     * @return array<int, array{filename: string, contents: string}>
     */
    private function fetchAttachments(): array
    {
        $attachments = [];

        foreach ($this->attachmentUrls as $url) {
            $filename = basename((string) parse_url($url, PHP_URL_PATH));

            $attachments[] = [
                'filename' => $filename === '' ? 'attachment' : $filename,
                'contents' => SafeUrlFetcher::fetch($url),
            ];
        }

        return $attachments;
    }
}
