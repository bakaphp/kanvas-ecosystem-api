<?php

declare(strict_types=1);

namespace Kanvas\Connectors\EchoPay\DataTransferObject;

use Spatie\LaravelData\Data;

class ConsumerAuthenticationData extends Data
{
    public function __construct(
        public readonly ?string $indicator,
        public readonly ?string $eciRaw,
        public readonly ?string $authenticationResult,
        public readonly array $strongAuthentication,
        public readonly ?string $authenticationStatusMsg,
        public readonly ?string $eci,
        public readonly string $token,
        public readonly ?string $cavv,
        public readonly ?string $paresStatus,
        public readonly ?string $xid,
        public readonly string $directoryServerTransactionId,
        public readonly string $threeDSServerTransactionId,
        public readonly string $specificationVersion,
        public readonly string $acsTransactionId,
    ) {
    }
}
