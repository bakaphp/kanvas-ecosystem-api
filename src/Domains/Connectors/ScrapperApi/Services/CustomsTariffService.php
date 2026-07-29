<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ScrapperApi\Services;

use Kanvas\Connectors\ScrapperApi\DataTransferObject\TariffRate;
use Kanvas\Connectors\ScrapperApi\Enums\ArancelSourceEnum;

/**
 * Looks up the Dominican Republic customs tariff schedule (7th HS Amendment, 2022).
 */
final class CustomsTariffService
{
    /**
     * Immutable reference data with no tenant coupling, so this static cache does not
     * fall under the connector ban on static state: there are no credentials to rotate
     * and no scope that can leak across apps. Under Octane the array is built once per
     * worker instead of on every request.
     */
    private static ?array $schedule = null;

    public static function find(string $code): ?TariffRate
    {
        $digits = preg_replace('/\D/', '', $code) ?? '';

        if (strlen($digits) < 4) {
            return null;
        }

        $schedule = self::schedule();
        $exact = self::format($digits);

        if ($exact !== null && isset($schedule[$exact])) {
            return self::make($exact, $schedule[$exact]);
        }

        // Six-digit subheading: the national splits hanging off it are the same good,
        // so the residual line ("Los demas") is the one that applies.
        if (strlen($digits) >= 6) {
            $residual = self::residualFor(substr($digits, 0, 6), $schedule);

            if ($residual !== null) {
                return self::make($residual, $schedule[$residual]);
            }
        }

        // Four-digit heading: too coarse to pick a residual. The last subheading of a
        // heading is usually "Partes" at 0%, and filing a phone there would leave it
        // duty-free. Take the highest duty in the branch instead, because
        // under-charging is what LoCompro ends up paying once Customs liquidates.
        if (strlen($digits) >= 4) {
            $highest = self::highestRatedIn(substr($digits, 0, 4) . '.', $schedule);

            if ($highest !== null) {
                return self::make($highest, $schedule[$highest]);
            }
        }

        return null;
    }

    public static function has(string $code): bool
    {
        return isset(self::schedule()[self::format(preg_replace('/\D/', '', $code) ?? '') ?? '']);
    }

    public static function count(): int
    {
        return count(self::schedule());
    }

    private static function residualFor(string $digits, array $schedule): ?string
    {
        $prefix = substr($digits, 0, 4) . '.' . substr($digits, 4, 2) . '.';

        if (isset($schedule[$prefix . '00'])) {
            return $prefix . '00';
        }

        // Keys are stored sorted, and residual lines ("Los demas") come last within
        // their branch by nomenclature convention (.90, .99).
        $match = null;

        foreach ($schedule as $code => $_) {
            if (str_starts_with($code, $prefix)) {
                $match = $code;
            } elseif ($match !== null) {
                break;
            }
        }

        return $match;
    }

    private static function highestRatedIn(string $prefix, array $schedule): ?string
    {
        $match = null;
        $best = -1;

        foreach ($schedule as $code => $row) {
            if (str_starts_with($code, $prefix)) {
                if ($row['rate'] > $best) {
                    $best = $row['rate'];
                    $match = $code;
                }
            } elseif ($match !== null) {
                break;
            }
        }

        return $match;
    }

    private static function format(string $digits): ?string
    {
        if (strlen($digits) < 8) {
            return null;
        }

        return substr($digits, 0, 4) . '.' . substr($digits, 4, 2) . '.' . substr($digits, 6, 2);
    }

    private static function make(string $code, array $row): TariffRate
    {
        return new TariffRate(
            code: $code,
            rate: (int) $row['rate'],
            itbisExempt: (bool) $row['itbis_exempt'],
            name: (string) $row['name'],
            source: ArancelSourceEnum::CACHED,
        );
    }

    private static function schedule(): array
    {
        return self::$schedule ??= require __DIR__ . '/../Resources/arancel_rates.php';
    }
}
