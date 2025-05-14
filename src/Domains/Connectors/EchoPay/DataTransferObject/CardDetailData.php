<?php

declare(strict_types=1);

namespace Kanvas\Connectors\EchoPay\DataTransferObject;

use Spatie\LaravelData\Data;

class CardDetailData extends Data
{
    public function __construct(
        public readonly string $number,
        public readonly string $expirationMonth,
        public readonly string $expirationYear,
        public readonly string $type,
    ) {
    }
}
