<?php

declare(strict_types=1);

namespace Kanvas\Souk\Payments\Enums;

enum PaymentChannelEnum: string
{
    case DIGITAL = 'digital';
    case PHYSICAL = 'physical';

    public static function fromMethod(?string $method): self
    {
        return $method === PaymentMethodTypesEnum::CARD->value ? self::DIGITAL : self::PHYSICAL;
    }
}
