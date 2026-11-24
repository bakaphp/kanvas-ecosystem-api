<?php

declare(strict_types=1);

namespace Kanvas\Connectors\CardNet\Ztrans\Support;

use Kanvas\Connectors\CardNet\Ztrans\DataTransferObject\ZtransResponse;

final class ZtransMessage
{
    private const array VOID_WINDOW_CLOSED_CODES = ['1018', '1019', '1020'];

    public static function forCustomer(ZtransResponse $response): string
    {
        if ($response->isAuthenticationFailure()) {
            return 'Card authentication failed.';
        }

        return match ($response->responseCode) {
            '00' => 'Transaction approved.',
            '51' => 'Insufficient funds.',
            '33', '54' => 'The card is expired.',
            '14', '39', '42' => 'The card number is not valid.',
            '99' => 'The security code is not valid.',
            '57', '58', '62' => 'The card is not allowed for this transaction.',
            default => 'Transaction declined.',
        };
    }

    public static function isVoidWindowClosed(ZtransResponse $response): bool
    {
        return in_array($response->internalResponseCode, self::VOID_WINDOW_CLOSED_CODES, true);
    }
}
