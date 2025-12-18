<?php

declare(strict_types=1);

namespace Kanvas\Connectors\DealerSocket\Enums;

enum EventCategoryEnum: int
{
    case SALES = 1;
    case SERVICE = 2;

    public function label(): string
    {
        return match ($this) {
            self::SALES => 'Sales',
            self::SERVICE => 'Service',
        };
    }

    public static function fromId(int $id): ?self
    {
        return self::tryFrom($id);
    }
}
