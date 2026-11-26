<?php

declare(strict_types=1);

namespace Kanvas\Connectors\EchoPay\Services;

use GuzzleHttp\Exception\ConnectException;
use Kanvas\Connectors\EchoPay\Exceptions\EchoPayException;
use Throwable;

class ShopperMessageResolver
{
    public static function resolve(?string $reason, ?string $gatewayMessage, string $fallback): string
    {
        return self::translatedReason($reason) ?? $gatewayMessage ?? $reason ?? $fallback;
    }

    public static function forThrowable(Throwable $e): string
    {
        if (! $e instanceof EchoPayException) {
            return self::genericMessage($e);
        }

        if (! $e->isGatewayFailure()) {
            return self::resolve($e->getReason(), $e->getGatewayMessage(), __('payment_errors.default', [], 'es'));
        }

        return self::translatedReason($e->getReason()) ?? self::genericMessage($e);
    }

    private static function translatedReason(?string $reason): ?string
    {
        if (! $reason) {
            return null;
        }

        $translationKey = 'payment_errors.' . $reason;
        $translated = __($translationKey, [], 'es');

        return $translated !== $translationKey ? $translated : null;
    }

    private static function genericMessage(Throwable $e): string
    {
        $key = self::isTimeout($e) ? 'SERVER_TIMEOUT' : 'PROCESSOR_UNAVAILABLE';

        return __('payment_errors.' . $key, [], 'es');
    }

    private static function isTimeout(Throwable $e): bool
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof ConnectException || str_contains($current->getMessage(), 'timed out')) {
                return true;
            }
        }

        return false;
    }
}
