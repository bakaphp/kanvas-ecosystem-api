<?php

declare(strict_types=1);

namespace Kanvas\Enums;

use Baka\Contracts\EnumsInterface;

enum AppSettingsEnums implements EnumsInterface
{
    case DEFAULT_ROLE_NAME;
    case DEFAULT_COUNTRY;
    case DEFAULT_LANGUAGE;
    case SEND_WELCOME_EMAIL;

    /**
     * Get value.
     */
    public function getValue(): mixed
    {
        return match ($this) {
            self::DEFAULT_ROLE_NAME => 'default_admin_role',
            self::DEFAULT_COUNTRY => 'default_user_country',
            self::DEFAULT_LANGUAGE => 'language',
            self::SEND_WELCOME_EMAIL => 'send_welcome_email',
        };
    }
}
