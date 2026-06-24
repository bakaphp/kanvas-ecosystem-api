<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Payments\Enums;

enum PaymentStatusEnum: string
{
    case PENDING = 'pending';
    case CLEARED = 'cleared';
    case FAILED = 'failed';
    case REVERSED = 'reversed';
}
