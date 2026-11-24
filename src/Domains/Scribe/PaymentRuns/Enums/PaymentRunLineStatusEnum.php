<?php

declare(strict_types=1);

namespace Kanvas\Scribe\PaymentRuns\Enums;

enum PaymentRunLineStatusEnum: string
{
    case PENDING = 'pending';
    case PARTIALLY_PAID = 'partially_paid';
    case PAID = 'paid';

    /**
     * @return list<string>
     */
    public static function openValues(): array
    {
        return [self::PENDING->value, self::PARTIALLY_PAID->value];
    }
}
