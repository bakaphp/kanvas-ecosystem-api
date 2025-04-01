<?php

declare(strict_types=1);

namespace Kanvas\Connectors\VinSolution\Support;

class Phone
{
    /**
     * Remove US Country Code from a given phone number
     */
    public static function removeUSCountryCode(?string $phone = null): string
    {
        if (empty($phone)) {
            return '';
        }

        // Check if the phone starts with '1'
        if (strpos($phone, '1') === 0) {
            // Remove the first '1' from the phone number
            $phone = substr($phone, 1);
        }

        return $phone;
    }
}
