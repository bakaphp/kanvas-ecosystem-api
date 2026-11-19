<?php

declare(strict_types=1);

namespace Kanvas\Connectors\CardNet\Ztrans\Support;

final class ThreeDSSignature
{
    public static function forAuthentication(
        string $integratorCode,
        string $cardExpiryDate,
        string $integratorTxId,
        string $purchaseAmount,
        string $apiKey
    ): string {
        return hash('sha512', $integratorCode . $cardExpiryDate . $integratorTxId . $purchaseAmount . $apiKey);
    }

    public static function forStatus(
        string $integratorCode,
        string $integratorTxId,
        string $purchaseAmount,
        string $apiKey
    ): string {
        return hash('sha512', $integratorCode . $integratorTxId . $purchaseAmount . $apiKey);
    }
}
