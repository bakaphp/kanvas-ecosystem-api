<?php

declare(strict_types=1);

namespace Kanvas\ActionEngine\Engagements\Notifications;

use Illuminate\Notifications\Slack\SlackMessage;
use Kanvas\ActionEngine\Engagements\Enums\NotificationTemplateEnum;
use Kanvas\ActionEngine\Engagements\Models\Engagement;
use Kanvas\Notifications\Notification;
use Kanvas\Templates\Enums\EmailTemplateEnum as EnumsEmailTemplateEnum;

class EngagementStatusChangedNotification extends Notification
{
    public array $channels = ['push'];
    public string $slackChannel;

    public function __construct(
        Engagement $engagement,
        array $data,
    ) {
        parent::__construct($engagement, $data);
        $this->setType(EnumsEmailTemplateEnum::BLANK->value);
        $this->setTemplateName(NotificationTemplateEnum::ENGAGEMENT_STATUS_CHANGED->value);
        $this->setData($data);
        $this->slackChannel = $data['slack_channel'] ?? $engagement->app->get('slack_channel', 'engagements');
    }

    public function toSlack($notifiable)
    {
        return (new SlackMessage())
            ->to($this->slackChannel) // Dynamically pulled
            ->text($this->renderTemplate(NotificationTemplateEnum::ENGAGEMENT_STATUS_CHANGED_SLACK->value));
    }
}
