<?php

declare(strict_types=1);

namespace Kanvas\Connectors\EchoPay\DataTransferObject;

use Spatie\LaravelData\Data;

class ConsumerAuthenticationInformationData extends Data
{
    public function __construct(
        public readonly string $deviceChannel,
        public readonly string $returnUrl,
        public readonly string $referenceId,
        public readonly string $transactionMode,
    ) {
    }
}
