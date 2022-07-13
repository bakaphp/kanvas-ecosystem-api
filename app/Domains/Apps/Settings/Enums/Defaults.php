<?php

declare(strict_types=1);

namespace Kanvas\Apps\Settings\Enums;

use Kanvas\Contracts\EnumsInterface;

enum Defaults implements EnumsInterface
{
    case LANGUAGE;
    case TIMEZONE;
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

    public function getValue(): mixed
    {
        return match ($this) {
            self::LANGUAGE => 'EN',
            self::TIMEZONE => 'America/New_York',
            self::CURRENCY => 'USD',
            self::FILESYSTEM => 's3',
            self::ALLOW_USER_REGISTRATION => 1,
            self::BACKGROUND_IMAGE => getenv('FILESYSTEM_CDN_URL') . '/default-background-auth.jpg',
            self::LOGO => getenv('FILESYSTEM_CDN_URL') . '/gewaer-logo-dark.png',
            self::REGISTERED => 1,
            self::FAVICON => getenv('FILESYSTEM_CDN_URL') . '/gewaer-logo-dark.png',
            self::BASE_COLOR => '#61c2cc',
            self::SECONDARY_COLOR => '#9ee5b5',
            self::ALLOW_SOCIAL_AUTH => 1,
            self::ALLOWED_SOCIAL_AUTHS => '{"google": 1,"facebook": 0,"github": 0,"apple": 0}',
            self::DEFAULT_SIDEBAR_STATE => 'closed',
            self::SHOW_NOTIFICATIONS => 1,
            self::DELETE_IMAGES_ON_EMPTY_FILES_FIELD => 1,
            self::PUBLIC_IMAGES => 0,
            self::DEFAULT_FEEDS_COMMENTS => 3,
        };
    }
}
