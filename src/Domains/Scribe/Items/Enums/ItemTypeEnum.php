<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Items\Enums;

enum ItemTypeEnum: string
{
    case SERVICE = 'service';
    case PRODUCT = 'product';
    case BUNDLE = 'bundle';
    case CHARGE = 'charge';
}
