<?php

declare(strict_types=1);

namespace Kanvas\Scribe\ElectronicInvoicing\Enums;

enum FiscalDirectionEnum: string
{
    case ISSUED = 'issued';
    case RECEIVED = 'received';
}
