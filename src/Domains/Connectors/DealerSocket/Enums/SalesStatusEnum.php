<?php

declare(strict_types=1);

namespace Kanvas\Connectors\DealerSocket\Enums;

enum SalesStatusEnum: int
{
    case UNQUALIFIED = 220;
    case UP_CONTACTED = 221;
    case STORE_VISIT = 227;
    case DEMO_VEHICLE = 222;
    case WRITE_UP = 223;
    case PENDING_FI = 224;
    case SOLD = 225;
    case LOST = 226;

    public function label(): string
    {
        return match ($this) {
            self::UNQUALIFIED => '0 - Unqualified',
            self::UP_CONTACTED => '1 - Up/Contacted',
            self::STORE_VISIT => '2 - Store Visit',
            self::DEMO_VEHICLE => '3 - Demo Vehicle',
            self::WRITE_UP => '4 - Write Up',
            self::PENDING_FI => '5 - Pending F&I',
            self::SOLD => '6 - Sold',
            self::LOST => '7 - Lost',
        };
    }

    public static function fromId(int $id): ?self
    {
        return self::tryFrom($id);
    }
}
