<?php

declare(strict_types=1);

namespace Kanvas\Souk\Discounts\Enums;

enum DiscountConditionOperatorEnum: string
{
    case IN = 'in';
    case NOT_IN = 'not_in';

    public function label(): string
    {
        return match ($this) {
            self::IN => 'In',
            self::NOT_IN => 'Not In',
        };
    }
}
