<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WaSender\Traits;

use Baka\Support\Str;

/**
 * WaSender addresses contacts by bare digits with a country code and no `+`.
 */
trait FormatsPhoneNumberTrait
{
    protected function formatPhoneNumber(string $phoneNumber, string $defaultCountryCode = '1'): string
    {
        $cleaned = Str::digitsOnly($phoneNumber);

        // A leading `+` means the caller already supplied a country code, even when it is not the
        // default one — prefixing it again would corrupt an international number.
        if (! str_starts_with($phoneNumber, '+') && ! str_starts_with($cleaned, $defaultCountryCode)) {
            $cleaned = $defaultCountryCode . $cleaned;
        }

        return $cleaned;
    }
}
