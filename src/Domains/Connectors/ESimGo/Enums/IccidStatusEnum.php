<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ESimGo\Enums;

/**
 * @todo move to global esim
 */
enum IccidStatusEnum: string
{
    case PENDING = 'pending';
    case COMPLETED = 'completed';
    case ACTIVE = 'active';
    case UNAVAILABLE = 'unavailable';
    case INACTIVE = 'inactive';
    case EXPIRED = 'expired';
    case RELEASED = 'released';
    case DELETED = 'delete';
    case INSTALLED = 'installed';
    case DISABLED = 'disabled';
    case DISABLE = 'disable';

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
        return match (strtolower($string)) {
            'released' => self::PENDING->value,
            'downloaded', 'finished' => self::COMPLETED->value,
            'installed', 'active', 'enable' => self::ACTIVE->value,
            'unavailable', 'UNKNOWN' => self::UNAVAILABLE->value,
            'deleted' => self::RELEASED->value,
            'not_active' => self::INACTIVE->value,
            'expired' => self::EXPIRED->value,
            'disable', 'disabled' => self::DISABLED->value,
            default => '',
        };
    }

    public static function getStatusById(string|int $id): string
    {
        return match ($id) {
            1 => self::PENDING->value,
            2 => self::EXPIRED->value,
            3 => self::ACTIVE->value,
            99 => self::EXPIRED->value,
            default => '',
        };
    }
}
