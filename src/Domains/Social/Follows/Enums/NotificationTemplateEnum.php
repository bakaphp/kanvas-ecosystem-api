<?php

declare(strict_types=1);

namespace Kanvas\Social\Follows\Enums;

enum NotificationTemplateEnum: string
{
    case EMAIL_NEW_FOLLOWER = 'email-new-follower';
    case PUSH_NEW_FOLLOWER = 'push-new-follower';
}
