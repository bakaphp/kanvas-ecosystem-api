<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Azul\DataTransferObject;

use Spatie\LaravelData\Data;

class CardDetail extends Data
{
    public function __construct(
        public readonly ?string $number = null,
        public readonly ?string $expiration = null,
        public readonly ?string $cvc = null,
    ) {
    }
}
