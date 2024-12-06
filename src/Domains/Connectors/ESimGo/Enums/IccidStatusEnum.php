<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ESimGo\Enums;

enum IccidStatusEnum: string
{
    case PENDING = 'pending';
    case COMPLETED = 'completed';
    case ACTIVE = 'active';
    case UNAVAILABLE = 'unavailable';
    case INACTIVE = 'inactive';
    case EXPIRED = 'expired';
    case RELEASED = 'released';

    /**
     * Check if a given status matches this enum case.
     */
    public function matches(string $status): bool
    {
        return $this->value === $status;
    }

    public static function isActive(string $status): bool
    {
        return self::ACTIVE->matches($status);
    }

    /**
     * Dynamically check if a status matches an enum case.
     */
    public static function checkStatus(string $status, self $enum): bool
    {
        return $enum->matches($status);
    }

    public static function getStatus(string $string): string
    {
        return match (ucfirst($string)) {
            'Released' => self::PENDING->value,
            'Downloaded', 'FINISHED' => self::COMPLETED->value,
            'Installed', 'ACTIVE' => self::ACTIVE->value,
            'Unavailable', 'UNKNOWN' => self::UNAVAILABLE->value,
            'NOT_ACTIVE' => self::INACTIVE->value,
            'EXPIRED' => self::EXPIRED->value,
            default => '',
        };
    }
}
