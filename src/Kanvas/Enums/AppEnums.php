<?php
declare(strict_types=1);

namespace Kanvas\Enums;

use Kanvas\Contracts\EnumsInterface;

enum AppEnums implements EnumsInterface
{
    case DEFAULT_TIMEZONE;
    case DEFAULT_SEX;
    case DEFAULT_NAME;
    case DEFAULT_LANGUAGE;
    case DEFAULT_ROLE_ID;
    case VERSION;
    case GLOBAL_APP_ID;
    case ECOSYSTEM_APP_ID;
    case GLOBAL_COMPANY_ID;

    /**
     * Get value
     *
     * @return mixed
     */
    public function getValue() : mixed
    {
        return match ($this) {
            self::DEFAULT_TIMEZONE => 'America/New_York',
            self::DEFAULT_LANGUAGE => 'EN',
            self::DEFAULT_NAME => 'Default',
            self::DEFAULT_SEX => 'U',
            self::VERSION => config('kanvas.app.version'),
            self::GLOBAL_APP_ID => 1,
            self::ECOSYSTEM_APP_ID => 1,
            self::GLOBAL_COMPANY_ID => 0
        };
    }
}
