<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Enums;

enum NotificationTemplateEnum: string
{
    case EMAIL_NEW_MESSAGE = 'email-new-message';
    case PUSH_NEW_MESSAGE = 'push-new-message';
    case EMAIL_NEW_INTERACTION_MESSAGE = 'email-interaction-message';
    case PUSH_NEW_INTERACTION_MESSAGE = 'push-interaction-message';
}
