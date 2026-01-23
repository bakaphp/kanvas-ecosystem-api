<?php

declare(strict_types=1);

namespace Kanvas\Connectors\DealerSocket\Enums;

enum ServiceEventTypeEnum: int
{
    case CUSTOMER_PAY = 100159;
    case WARRANTY = 100158;
    case INTERNAL = 100160;

    public function label(): string
    {
        return match ($this) {
            self::CUSTOMER_PAY => 'Customer Pay',
            self::WARRANTY => 'Warranty',
            self::INTERNAL => 'Internal',
        };
    }

    public static function fromId(int $id): ?self
    {
        return self::tryFrom($id);
    }
}
