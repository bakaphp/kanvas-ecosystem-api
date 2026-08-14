<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Gmail\Actions;

use Baka\Contracts\AppInterface;
use Google\Service\Gmail as GmailService;
use Google\Service\Gmail\ModifyMessageRequest;

/** Removes the UNREAD label from a message so it doesn't get picked up again by a future has:attachment is:unread search. */
class MarkEmailAsReadAction extends AbstractGmailAction
{
    public function __construct(
        AppInterface $app,
        protected string $messageId,
        ?GmailService $service = null,
    ) {
        parent::__construct($app, $service);
    }

    /**
     * @return array{message_id: string}
     */
    public function execute(): array
    {
        $this->service()->users_messages->modify(
            'me',
            $this->messageId,
            new ModifyMessageRequest(['removeLabelIds' => ['UNREAD']]),
        );

        return ['message_id' => $this->messageId];
    }
}
