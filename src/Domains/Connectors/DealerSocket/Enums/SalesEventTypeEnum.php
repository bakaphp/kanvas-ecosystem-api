<?php

declare(strict_types=1);

namespace Kanvas\Connectors\DealerSocket\Enums;

enum SalesEventTypeEnum: int
{
    case NEW_VEHICLE = 100050;
    case USED_VEHICLE = 100051;

    public function label(): string
    {
        return match ($this) {
            self::NEW_VEHICLE => 'New Vehicle',
            self::USED_VEHICLE => 'Used Vehicle',
        };
    }

    public static function fromId(int $id): ?self
    {
        return self::tryFrom($id);
    }
}
