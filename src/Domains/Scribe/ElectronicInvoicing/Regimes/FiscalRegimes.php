<?php

declare(strict_types=1);

namespace Kanvas\Scribe\ElectronicInvoicing\Regimes;

use Kanvas\Exceptions\ValidationException;
use Kanvas\Scribe\ElectronicInvoicing\Contracts\FiscalRegimeInterface;
use Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr\DrFiscalRegime;

class FiscalRegimes
{
    /**
     * @var array<string, class-string<FiscalRegimeInterface>>
     */
    private const array REGIMES = [
        DrFiscalRegime::COUNTRY => DrFiscalRegime::class,
    ];

    /**
     * @return list<FiscalRegimeInterface>
     */
    public static function all(): array
    {
        return array_values(array_map(static fn (string $class): FiscalRegimeInterface => new $class(), self::REGIMES));
    }

    public static function find(?string $country): ?FiscalRegimeInterface
    {
        $class = self::REGIMES[strtoupper(trim((string) $country))] ?? null;

        return $class !== null ? new $class() : null;
    }

    public static function for(?string $country): FiscalRegimeInterface
    {
        return self::find($country)
            ?? throw new ValidationException("Electronic invoicing is not supported for country '{$country}'.");
    }
}
