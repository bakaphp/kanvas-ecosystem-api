<?php

declare(strict_types=1);

namespace Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr;

/**
 * The DGII stamp URL (`ConsultaTimbre?...`) carries the security code and signing time as query parameters,
 * whoever produced it, so every provider reads them here.
 */
final class EcfQrCode
{
    /**
     * @return array<string, string>
     */
    public static function params(?string $qrUrl): array
    {
        $query = $qrUrl !== null ? parse_url($qrUrl, PHP_URL_QUERY) : null;

        if (! is_string($query) || $query === '') {
            return [];
        }

        parse_str($query, $params);

        return array_filter($params, fn ($value): bool => is_string($value) && $value !== '');
    }
}
