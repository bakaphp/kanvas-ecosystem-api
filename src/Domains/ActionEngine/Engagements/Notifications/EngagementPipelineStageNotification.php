<?php

declare(strict_types=1);

namespace Kanvas\ActionEngine\Engagements\Notifications;

use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Slack\SlackMessage;

class EngagementPipelineStageNotification extends Notification
{
    public function __construct(
        protected string $slackChannel,
        protected string $messageText,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['slack'];
    }

    public function toSlack(object $notifiable): SlackMessage
    {
        return new SlackMessage()
            ->to($this->slackChannel)
            ->text($this->messageText);
    }
}
