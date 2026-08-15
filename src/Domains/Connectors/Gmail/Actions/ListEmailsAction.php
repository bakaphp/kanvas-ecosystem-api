<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Gmail\Actions;

use Baka\Contracts\AppInterface;
use Google\Service\Gmail as GmailService;
use Kanvas\Connectors\Gmail\Support\GmailMessageParser;

/** Searches the mailbox with Gmail's own query syntax (e.g. "subject:Invoice has:attachment is:unread"). */
class ListEmailsAction extends AbstractGmailAction
{
    public function __construct(
        AppInterface $app,
        protected string $query,
        protected int $maxResults = 10,
        ?GmailService $service = null,
    ) {
        parent::__construct($app, $service);
    }

    /**
     * @return array<int, array{id: string, thread_id: string, subject: string}>
     */
    public function execute(): array
    {
        $response = $this->service()->users_messages->listUsersMessages('me', [
            'q' => $this->query,
            'maxResults' => $this->maxResults,
        ]);

        $emails = [];

        foreach ($response->getMessages() ?? [] as $message) {
            $details = $this->service()->users_messages->get('me', $message->getId(), [
                'format' => 'metadata',
                'metadataHeaders' => ['Subject'],
            ]);

            $emails[] = [
                'id' => $message->getId(),
                'thread_id' => $message->getThreadId(),
                'subject' => GmailMessageParser::findHeader($details->getPayload()?->getHeaders() ?? [], 'Subject') ?? '',
            ];
        }

        return $emails;
    }
}
