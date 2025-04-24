<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Actions;

use Exception;
use Kanvas\Social\Messages\Models\Message;

class CheckMessagePostLimitAction
{
    /**
     * __construct.
     *
     * @return void
     */
    public function __construct(
        public Message $message,
        public int $timeFrame = 24,
        public ?int $messageTypeId = null,
        public bool $getChildrenCount = false
    ) {
    }

    /**
     * execute.
     *
     * @return void
     */
    public function execute()
    {
        $messageCount = Message::getUserMessageCountInTimeFrame(
            $this->message->user->getId(),
            $this->message->app,
            $this->timeFrame,
            $this->messageTypeId,
            $this->getChildrenCount
        );

        if ($messageCount >= $this->message->app->get('message-post-limit')) {
            throw new Exception('You have reached the limit of messages you can post in a day');
        }
    }
}
