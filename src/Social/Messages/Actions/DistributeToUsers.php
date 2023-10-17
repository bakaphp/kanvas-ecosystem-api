<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Actions;

use Kanvas\Social\Messages\Jobs\FillUserMessage;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\Messages\Models\UserMessageActivityType;

class DistributeToUsers
{
    public function __construct(
        Message $message
    ) {

    }

    public function execute()
    {
        $activity = [];

        $activityType = UserMessageActivityType::where('name', 'follow')->firstOrFail();
        $activity = [
                    'username' => '',
                    'entity_namespace' => '',
                    'text' => ' ',
                    'type' => $activityType->id,
            ];

        FillUserMessage::dispatch($this->message, $this->message->user, $activity)->onQueue('message');
    }
}
