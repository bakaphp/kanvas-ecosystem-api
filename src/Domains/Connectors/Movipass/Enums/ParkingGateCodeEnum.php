<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Enums;

enum ParkingGateCodeEnum: string
{
    case PAID = 'PAID';
    case ENTRY_OK = 'ENTRY_OK';
    case EXIT_OK = 'EXIT_OK';
    case NOT_FOUND = 'NOT_FOUND';
    case ALREADY_USED = 'ALREADY_USED';
    case ALREADY_INSIDE = 'ALREADY_INSIDE';
    case INSUFFICIENT_FUNDS = 'INSUFFICIENT_FUNDS';
    case NOT_PAID = 'NOT_PAID';
    case EXPIRED = 'EXPIRED';
    case FAILED = 'FAILED';
}
