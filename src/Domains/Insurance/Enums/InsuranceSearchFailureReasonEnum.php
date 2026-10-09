<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Enums;

enum InsuranceSearchFailureReasonEnum: string
{
    case REJECTED = 'rejected';
    case UNAVAILABLE = 'unavailable';
    case TIMEOUT = 'timeout';

    public function customerMessage(): string
    {
        return match ($this) {
            self::REJECTED => 'This insurer could not quote this vehicle',
            self::UNAVAILABLE => 'This insurer is currently unavailable',
            self::TIMEOUT => 'This insurer did not answer in time',
        };
    }
}
