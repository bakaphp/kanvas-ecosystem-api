<?php

declare(strict_types=1);

namespace Kanvas\Connectors\EchoPay\DataTransferObject;

use Spatie\LaravelData\Data;

class MerchantDetailData extends Data
{
    public function __construct(
        public readonly string $id,
        public readonly string $key,
        public readonly string $secretKey,
    ) {
    }
}
