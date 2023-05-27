<?php

declare(strict_types=1);

namespace Kanvas\Enums;

use Baka\Contracts\EnumsInterface;

enum SourceEnum: string 
{
    case IOS = 'iosapp';
    case ANDROID = 'androidapp';
    case WEBAPP = 'webapp';
}
