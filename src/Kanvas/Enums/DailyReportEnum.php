<?php

declare(strict_types=1);

namespace Kanvas\Enums;

/**
 * Common tracking keys for DailyReportService
 * Add new keys here as needed for consistency across the codebase
 */
enum DailyReportEnum: string
{
    case AI_MESSAGES_SENT = 'ai_messages_sent';
    case AI_FOLLOW_UPS = 'ai_follow_ups';
    case AI_HAND_OFFS = 'ai_hand_offs';
    case WORKFLOW_TRIGGERED = 'workflow_triggered';
    case EMAILS_SENT = 'emails_sent';
    case SMS_SENT = 'sms_sent';
    case PUSH_NOTIFICATIONS = 'push_notifications';
    case WEBHOOK_CALLS = 'webhook_calls';
    case API_REQUESTS = 'api_requests';
}
