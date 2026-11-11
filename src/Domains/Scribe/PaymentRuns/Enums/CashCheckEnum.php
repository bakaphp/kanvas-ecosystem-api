<?php

declare(strict_types=1);

namespace Kanvas\Scribe\PaymentRuns\Enums;

enum CashCheckEnum: string
{
    case ENOUGH = 'enough';
    case SHORT = 'short';
    // No balance is known for any account in that currency (no feed, no statement yet).
    case UNKNOWN = 'unknown';
}
