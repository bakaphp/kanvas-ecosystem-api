<?php

declare(strict_types=1);

namespace Kanvas\ActionEngine\Engagements\Notifications;

use Kanvas\ActionEngine\Engagements\Enums\NotificationTemplateEnum;
use Kanvas\ActionEngine\Engagements\Models\Engagement;
use Kanvas\Notifications\Notification;
use Kanvas\Templates\Enums\EmailTemplateEnum as EnumsEmailTemplateEnum;

class EngagementStatusChangedNotification extends Notification
{
    public array $channels = ['push'];

    public function __construct(
        Engagement $engagement,
        array $data,
    ) {
        parent::__construct($engagement, $data);
        $this->setType(EnumsEmailTemplateEnum::BLANK->value);
        $this->setTemplateName(NotificationTemplateEnum::ENGAGEMENT_STATUS_CHANGED->value);
        $this->setData($data);
    }
}
