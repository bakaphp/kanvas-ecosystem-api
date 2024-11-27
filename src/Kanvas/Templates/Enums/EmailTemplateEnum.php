<?php

declare(strict_types=1);

namespace Kanvas\Templates\Enums;

enum EmailTemplateEnum: string
{
    case DEFAULT = 'default';
    case USER_INVITE = 'users-invite';
    case ADMIN_USER_INVITE = 'admin-users-invite';
    case CHANGE_PASSWORD = 'change-password';
    case RESET_PASSWORD = 'reset-password';
    case WELCOME = 'welcome';
    case BLANK = 'blank';
}
