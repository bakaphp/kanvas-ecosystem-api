<?php

declare(strict_types=1);

namespace Kanvas\Enums;

use Baka\Contracts\EnumsInterface;

enum AppEnums implements EnumsInterface
{
    case DEFAULT_TIMEZONE;
    case DEFAULT_SEX;
    case DEFAULT_NAME;
    case DEFAULT_LANGUAGE;
    case DEFAULT_ROLE_ID;
    case VERSION;
    case GLOBAL_APP_ID;
    case LEGACY_APP_ID;
    case ECOSYSTEM_APP_ID;
    case GLOBAL_COMPANY_ID;
    case GLOBAL_USER_ID;
    case DEFAULT_ROLE_NAME;
    case DEFAULT_ROLES_NAMES;
    case CORE_APP_ID;
    case ECOSYSTEM_COMPANY_ID;
    case DEFAULT_APP_NAME;
    case DEFAULT_COUNTRY;
    case DEFAULT_USER_LEVEL;
    case CURRENCY;
    case FILESYSTEM;
    case ALLOW_USER_REGISTRATION;
    case BACKGROUND_IMAGE;
    case LOGO;
    case REGISTERED;
    case FAVICON;
    case BASE_COLOR;
    case SECONDARY_COLOR;
    case ALLOW_SOCIAL_AUTH;
    case ALLOWED_SOCIAL_AUTHS;
    case DEFAULT_SIDEBAR_STATE;
    case SHOW_NOTIFICATIONS;
    case DELETE_IMAGES_ON_EMPTY_FILES_FIELD;
    case PUBLIC_IMAGES;
    case DEFAULT_FEEDS_COMMENTS;
    case KANVAS_APP_HEADER;
    case KANVAS_APP_KEY_HEADER;
    case KANVAS_APP_BRANCH_HEADER;
    case KANVAS_APP_COMPANY_AUTH_HEADER;
    case KANVAS_APP_REGION_HEADER;
    case DISPLAYNAME_LOGIN;
    case ANONYMOUS_USER_ID;
    case DEFAULT_APP_JWT_TOKEN_NAME;
    case CSV_DATE_FORMAT;

    case DEFAULT_PUBLIC_SEARCH_USER_ID;

    /**
     * Get value.
     */
    public function getValue(): mixed
    {
        return match ($this) {
            self::DEFAULT_TIMEZONE => 'America/New_York',
            self::DEFAULT_LANGUAGE => 'EN',
            self::DEFAULT_NAME => 'Default',
            self::DEFAULT_SEX => 'U',
            self::GLOBAL_APP_ID => 1,
            self::LEGACY_APP_ID => 0,
            self::ECOSYSTEM_APP_ID => 1,
            self::CORE_APP_ID => 1,
            self::GLOBAL_COMPANY_ID => 0,
            self::GLOBAL_USER_ID => 0,
            self::DEFAULT_ROLE_NAME => 'Admins',
            self::DEFAULT_ROLES_NAMES => ['Admin', 'Admins', 'User', 'Users', 'Agents'],
            self::ECOSYSTEM_COMPANY_ID => 1,
            self::DEFAULT_APP_NAME => 'Default',
            self::DEFAULT_COUNTRY => 'USA',
            self::DEFAULT_USER_LEVEL => 3,
            self::DEFAULT_ROLE_ID => 1,
            self::CURRENCY => 'USD',
            self::FILESYSTEM => 's3',
            self::ALLOW_USER_REGISTRATION => 1,
            self::BACKGROUND_IMAGE => config('filesystem.cdn_url') . '/default-background-auth.jpg',
            self::LOGO => config('filesystem.cdn_url') . '/gewaer-logo-dark.png',
            self::REGISTERED => 1,
            self::FAVICON => config('filesystem.cdn_url') . '/gewaer-logo-dark.png',
            self::BASE_COLOR => '#61c2cc',
            self::SECONDARY_COLOR => '#9ee5b5',
            self::ALLOW_SOCIAL_AUTH => 1,
            self::ALLOWED_SOCIAL_AUTHS => '{"google": 1,"facebook": 0,"github": 0,"apple": 0}',
            self::DEFAULT_SIDEBAR_STATE => 'closed',
            self::SHOW_NOTIFICATIONS => 1,
            self::DELETE_IMAGES_ON_EMPTY_FILES_FIELD => 1,
            self::PUBLIC_IMAGES => 0,
            self::DEFAULT_FEEDS_COMMENTS => 3,
            self::KANVAS_APP_HEADER => 'X-Kanvas-App',
            self::KANVAS_APP_KEY_HEADER => 'X-Kanvas-Key',
            self::KANVAS_APP_BRANCH_HEADER => 'X-Kanvas-Location',
            self::KANVAS_APP_REGION_HEADER => 'X-Kanvas-Region',
            self::KANVAS_APP_COMPANY_AUTH_HEADER => 'Company-Authorization', //@deprecated
            self::DISPLAYNAME_LOGIN => 'displayname_login',
            self::VERSION => '1.16.0',
            self::ANONYMOUS_USER_ID => -1,
            self::DEFAULT_APP_JWT_TOKEN_NAME => 'kanvas-login',
            self::CSV_DATE_FORMAT => 'csv_date_format',
            self::DEFAULT_PUBLIC_SEARCH_USER_ID => 'public_search_user_id',
        };
    }

    /**
     * Given the enum name get its value.
     */
    public static function fromName(string $name): mixed
    {
        return constant("self::$name")->getValue();
    }
}
