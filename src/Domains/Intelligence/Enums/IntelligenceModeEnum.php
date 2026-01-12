<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Enums;

enum IntelligenceModeEnum: string
{
    case FULL_ON = 'full_on';
    case SUPPORT = 'support';
    case OFF = 'off';
}
