<?php

declare(strict_types=1);

namespace Kanvas\Connectors\EchoPay\Services;

class ShopperMessageResolver
{
    public static function resolve(?string $reason, ?string $gatewayMessage, string $fallback): string
    {
        if ($reason) {
            $translationKey = 'payment_errors.' . $reason;
            $translated = __($translationKey, [], 'es');

            if ($translated !== $translationKey) {
                return $translated;
            }
        }

        return $gatewayMessage ?? $reason ?? $fallback;
    }
}
