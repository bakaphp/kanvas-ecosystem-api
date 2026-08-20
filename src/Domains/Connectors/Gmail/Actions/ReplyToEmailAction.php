<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Gmail\Actions;

use Baka\Contracts\AppInterface;
use Google\Service\Gmail as GmailService;
use Google\Service\Gmail\Message;
use Kanvas\Connectors\Gmail\Support\GmailMessageParser;

/**
 * Replies inside an existing email thread with an internal-only note — e.g. approval evidence.
 * The recipient list is always whatever the caller explicitly passes, never derived from the
 * original message's sender, so this can never leak internal notes back to an external vendor.
 */
class ReplyToEmailAction extends AbstractGmailAction
{
    /**
     * @param array<int, string> $to
     */
    public function __construct(
        AppInterface $app,
        protected string $messageId,
        protected array $to,
        protected string $body,
        ?GmailService $service = null,
    ) {
        parent::__construct($app, $service);
    }

    /**
     * @return array{message_id: string, thread_id: string}
     */
    public function execute(): array
    {
        $original = $this->service()->users_messages->get('me', $this->messageId, [
            'format' => 'metadata',
            'metadataHeaders' => ['Subject', 'Message-ID'],
        ]);
        $headers = $original->getPayload()?->getHeaders() ?? [];

        $subject = (string) (GmailMessageParser::findHeader($headers, 'Subject') ?? '');
        $replySubject = str_starts_with(strtolower($subject), 're:') ? $subject : 'Re: ' . $subject;
        $originalMessageId = GmailMessageParser::findHeader($headers, 'Message-ID');

        $sent = $this->service()->users_messages->send('me', new Message([
            'raw' => $this->buildRawMessage($replySubject, $originalMessageId),
            'threadId' => $original->getThreadId(),
        ]));

        return [
            'message_id' => (string) $sent->getId(),
            'thread_id' => (string) $sent->getThreadId(),
        ];
    }

    private function buildRawMessage(string $subject, ?string $originalMessageId): string
    {
        $lines = [
            'To: ' . implode(', ', $this->to),
            'Subject: ' . $subject,
            'Content-Type: text/plain; charset=UTF-8',
        ];

        if ($originalMessageId !== null) {
            $lines[] = 'In-Reply-To: ' . $originalMessageId;
            $lines[] = 'References: ' . $originalMessageId;
        }

        $lines[] = '';
        $lines[] = $this->body;

        return rtrim(strtr(base64_encode(implode("\r\n", $lines)), '+/', '-_'), '=');
    }
}
