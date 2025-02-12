<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ESim\Enums;

enum PlanTypeEnum: string
{
    case UNLIMITED = 'unlimited';
    case BASIC = 'basic';
}
