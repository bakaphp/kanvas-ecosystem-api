<?php

declare(strict_types=1);

namespace Kanvas\Connectors\EchoPay\DataTransferObject;

use Spatie\LaravelData\Data;

class ConsumerAuthentication extends Data
{
    public function __construct(
        public readonly ?string $indicator,
        public readonly ?string $authenticationTransactionId,
        public readonly ?string $eciRaw,
        public readonly ?string $authenticationResult,
        public readonly array $strongAuthentication,
        public readonly ?string $authenticationStatusMsg,
        public readonly ?string $eci,
        public readonly string $token,
        public readonly ?string $accessToken,
        public readonly ?string $cavv,
        public readonly ?string $paresStatus,
        public readonly ?string $xid,
        public readonly ?string $directoryServerTransactionId,
        public readonly ?string $threeDSServerTransactionId,
        public readonly ?string $specificationVersion,
        public readonly ?string $acsTransactionId,
        public readonly ?string $ucafCollectionIndicator,
        public readonly ?string $ucafAuthenticationData,
        public readonly ?string $challengeRequired,
        public readonly ?string $acsUrl,
        public readonly ?string $acsReferenceNumber,
        public readonly ?string $stepUpUrl,
        public readonly ?string $pareq,
        public readonly ?string $veresEnrolled,
        public readonly ?string $acsOperatorID,
    ) {
    }
}
