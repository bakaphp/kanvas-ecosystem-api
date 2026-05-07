<?php

declare(strict_types=1);

namespace Kanvas\Connectors\DealerSocket\Enums;

enum ServiceStatusEnum: int
{
    case UNQUALIFIED = 100165;
    case APPOINTMENT = 100166;
    case DIAGNOSIS = 100167;
    case IN_SERVICE = 100168;
    case COMPLETED = 100169;
    case LOST = 100170;

    public function label(): string
    {
        return match ($this) {
            self::UNQUALIFIED => '0 - Unqualified',
            self::APPOINTMENT => '1 - Appointment',
            self::DIAGNOSIS => '2 - Diagnosis',
            self::IN_SERVICE => '3 - In-Service',
            self::COMPLETED => '4 - Completed',
            self::LOST => '5 - Lost',
        };
    }

    public static function fromId(int $id): ?self
    {
        return self::tryFrom($id);
    }
}
