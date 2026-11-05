<?php

namespace Kanvas\Souk\Payments\Enums;

enum PaymentStatusEnum: string
{
    case PENDING = 'pending';
    // transitional statuses
    case WAITING_DEVICE_DATA = 'waiting_device_data';
    case PENDING_AUTHORIZATION = 'pending_authorization';
    case PROCESSING = 'processing';
    // authorized
    case AUTHORIZED = 'authorized';
    // final states
    case PAID = 'paid';
    case FAILED = 'failed';
    case REVERSED = 'refunded';
    case CANCELLED = 'cancelled';

    public function isFinal(): bool
    {
        return in_array($this, [self::PAID, self::FAILED, self::REVERSED, self::CANCELLED], true);
    }

    public static function isFinalValue(mixed $status): bool
    {
        return is_string($status) && self::tryFrom($status)?->isFinal() === true;
    }
}
