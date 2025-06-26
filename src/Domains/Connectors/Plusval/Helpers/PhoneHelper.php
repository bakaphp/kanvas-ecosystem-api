<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Plusval\Helpers;

use Baka\Support\Str;

class PhoneHelper
{
    /**
     * Format agent phone number for API consumption
     * Required format: +1 809-864-6241
     */
    public static function formatPhoneNumber(string $agentPhone): string
    {
        // Remove any extra spaces and non-numeric characters except + and -
        $agentPhone = Str::trim($agentPhone);
        $cleanPhone = Str::replaceMatches('/[^0-9]/', '', $agentPhone);

        // If we have exactly 10 digits, assume it's a Dominican number without country code
        if (Str::length($cleanPhone) === 10) {
            // Check if it starts with Dominican area codes (809, 829, 849)
            if (Str::startsWith($cleanPhone, ['809', '829', '849'])) {
                $areaCode = Str::substr($cleanPhone, 0, 3);
                $firstPart = Str::substr($cleanPhone, 3, 3);
                $lastPart = Str::substr($cleanPhone, 6, 4);

                return "+1 {$areaCode}-{$firstPart}-{$lastPart}";
            }
        }

        // If we have 11 digits and starts with 1, assume it's US/Dominican with country code
        if (Str::length($cleanPhone) === 11 && Str::startsWith($cleanPhone, '1')) {
            $areaCode = Str::substr($cleanPhone, 1, 3);
            $firstPart = Str::substr($cleanPhone, 4, 3);
            $lastPart = Str::substr($cleanPhone, 7, 4);

            return "+1 {$areaCode}-{$firstPart}-{$lastPart}";
        }

        // If phone already has proper format (+1 XXX-XXX-XXXX), return as is
        if (Str::isMatch('/^\+1\s\d{3}-\d{3}-\d{4}$/', $agentPhone)) {
            return $agentPhone;
        }

        // If phone starts with +1 but wrong format, reformat
        if (Str::startsWith($agentPhone, '+1')) {
            $phoneOnly = Str::replaceMatches('/[^0-9]/', '', Str::substr($agentPhone, 2));
            if (Str::length($phoneOnly) === 10) {
                $areaCode = Str::substr($phoneOnly, 0, 3);
                $firstPart = Str::substr($phoneOnly, 3, 3);
                $lastPart = Str::substr($phoneOnly, 6, 4);

                return "+1 {$areaCode}-{$firstPart}-{$lastPart}";
            }
        }

        // For any other format, try to extract 10 digits and format as Dominican
        if (Str::length($cleanPhone) >= 10) {
            $last10 = Str::substr($cleanPhone, -10);
            $areaCode = Str::substr($last10, 0, 3);
            $firstPart = Str::substr($last10, 3, 3);
            $lastPart = Str::substr($last10, 6, 4);

            return "+1 {$areaCode}-{$firstPart}-{$lastPart}";
        }

        // If all else fails, return original with +1 prefix if missing
        if (! Str::startsWith($agentPhone, '+1')) {
            return '+1 ' . $agentPhone;
        }

        return $agentPhone;
    }
    
}