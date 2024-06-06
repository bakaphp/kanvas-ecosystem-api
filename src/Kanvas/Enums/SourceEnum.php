<?php

declare(strict_types=1);

namespace Kanvas\Enums;

enum SourceEnum: string
{
    case IOS = 'iosapp';
    case ANDROID = 'androidapp';
    case WEBAPP = 'webapp';
    case BAKA = 'baka';
    case FACEBOOK = 'facebook';
    case GOOGLE = 'google';
    case APPLE = 'apple';
    case TWITTER = 'twitter';
}
