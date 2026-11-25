<?php

declare(strict_types=1);

namespace Kanvas\Notifications\Mailings\Enums;

enum AppUserMailingAudienceEnum: string
{
    case ACTIVE_USERS = 'active_users';
    case APP_USERS = 'app_users';
}
