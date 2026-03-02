<?php

declare(strict_types=1);

namespace Kanvas\Event\Support\Enums;

enum EventSetupTypeEnum: string
{
    case STANDARD = 'standard';
    case FULL = 'full';
}
