<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Gmail\Actions;

use Baka\Contracts\AppInterface;
use Google\Service\Gmail as GmailService;
use Kanvas\Connectors\Gmail\Support\GmailMessageParser;

/** Fetches one message's headers, body, and attachment refs (attachment bytes are fetched separately via DownloadAttachmentAction). */
class ReadEmailDetailsAction extends AbstractGmailAction
{
    public function __construct(
        AppInterface $app,
        protected string $messageId,
        ?GmailService $service = null,
    ) {
        parent::__construct($app, $service);
    }

    /**
     * @return array{
     *     from: ?string,
     *     date: ?string,
     *     subject: ?string,
     *     body: string,
     *     attachments: array<int, array{attachment_id: string, filename: string, mime_type: string}>,
     * }
     */
    public function execute(): array
    {
        $message = $this->service()->users_messages->get('me', $this->messageId, ['format' => 'full']);
        $payload = $message->getPayload();
        $headers = $payload?->getHeaders() ?? [];
        $body = $payload !== null ? GmailMessageParser::extractBody($payload) : ['plain' => null, 'html' => null];

        return [
            'from' => GmailMessageParser::findHeader($headers, 'From'),
            'date' => GmailMessageParser::findHeader($headers, 'Date'),
            'subject' => GmailMessageParser::findHeader($headers, 'Subject'),
            'body' => $body['plain'] ?? $body['html'] ?? '',
            'attachments' => $payload !== null ? GmailMessageParser::extractAttachments($payload) : [],
        ];
    }
}
