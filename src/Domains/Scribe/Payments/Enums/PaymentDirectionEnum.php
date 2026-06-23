<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Payments\Enums;

enum PaymentDirectionEnum: string
{
    case INBOUND = 'inbound';
    case OUTBOUND = 'outbound';
}
