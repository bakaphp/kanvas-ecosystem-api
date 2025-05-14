<?php

declare(strict_types=1);

namespace Kanvas\Connectors\EchoPay\DataTransferObject;

use Spatie\LaravelData\Data;

class ConsultServiceQueryData extends Data
{
    public function __construct(
        public readonly ?string $merchantKey,
        public readonly ?string $channelCode,
        public readonly ?string $serviceCode,
        public readonly ?string $contract,
    ) {
    }
}
