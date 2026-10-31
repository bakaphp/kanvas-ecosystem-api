<?php

declare(strict_types=1);

namespace Kanvas\Connectors\EchoPay\Enums;

enum CardBrandEnum: string
{
    case VISA = 'VISA';
    case MASTERCARD = 'MASTERCARD';
    case AMEX = 'AMEX';
    case JCB = 'JCB';
    case DINERS = 'DINERS';
    case DISCOVER = 'DISCOVER';
    case CUP = 'CUP';
    case ELO = 'ELO';

    public function authenticatedEci(): string
    {
        return $this === self::MASTERCARD ? '02' : '05';
    }

    public function attemptedEci(): string
    {
        return $this === self::MASTERCARD ? '01' : '06';
    }

    public static function fromGateway(mixed $type): ?self
    {
        return is_string($type) ? self::tryFrom($type) : null;
    }

    public static function authenticatedEcis(): array
    {
        return array_values(array_unique(array_map(
            fn (self $brand): string => $brand->authenticatedEci(),
            self::cases()
        )));
    }

    public static function attemptedEcis(): array
    {
        return array_values(array_unique(array_map(
            fn (self $brand): string => $brand->attemptedEci(),
            self::cases()
        )));
    }

    public static function isAttemptedEci(?string $eci, ?self $brand): bool
    {
        if ($brand !== null) {
            return $eci === $brand->attemptedEci();
        }

        return in_array($eci, self::attemptedEcis(), strict: true);
    }
}
