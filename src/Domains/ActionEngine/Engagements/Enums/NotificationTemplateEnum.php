<?php

declare(strict_types=1);

namespace Kanvas\ActionEngine\Engagements\Enums;

enum NotificationTemplateEnum: string
{
    case ENGAGEMENT_STATUS_CHANGED = 'engagement-status-changed';
    case ENGAGEMENT_STATUS_CHANGED_SLACK = 'engagement-status-changed-slack';
}
