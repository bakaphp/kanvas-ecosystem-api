<?php

declare(strict_types=1);

namespace Kanvas\Connectors\PromptMine\Enums;

enum NotificationTemplateEnum: string
{
    case PUSH_MONTHLY_PROMPT_COUNT = 'push-monthly-prompt-count';
    case PUSH_WEEKLY_FAVORITE_PROMPT = 'push-weekly-favorite-prompt';
}
