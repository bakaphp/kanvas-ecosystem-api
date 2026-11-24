<?php

declare(strict_types=1);

namespace Kanvas\Scribe\ElectronicInvoicing\Enums;

enum FiscalRangeStatusEnum: string
{
    case ACTIVE = 'active';
    case EXHAUSTED = 'exhausted';
    case EXPIRED = 'expired';
    case SUPERSEDED = 'superseded';
}
