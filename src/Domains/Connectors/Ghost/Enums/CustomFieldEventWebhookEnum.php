<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Ghost\Enums;

enum CustomFieldEventWebhookEnum: string
{
    case WEBHOOK_IS_REPORT_EVENT = 'event_webhook_is_report_event';
    case WEBHOOK_WEB_FORUM_EVENT = 'event_webhook_web_forum_event';
}
