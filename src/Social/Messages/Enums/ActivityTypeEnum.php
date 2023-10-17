<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Enums;

enum ActivityTypeEnum: int
{
    case LIKE = 1;
    case SAVE = 2;
    case SHARE = 3;
    case REPORT = 4;
}
