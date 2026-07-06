<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Notifications\Enums;

enum NotificationTemplateEnum: string
{
    case EMAIL_AGENT_MENTION_REPLY = 'email-agent-mention-reply';
    case PUSH_AGENT_MENTION_REPLY = 'push-agent-mention-reply';
    case DATABASE_AGENT_MENTION_REPLY = 'database-agent-mention-reply';
}
