<?php

declare(strict_types=1);

namespace Kanvas\Regions\DataTransferObject;

use Spatie\LaravelData\Data;

class RegionSettings extends Data
{
    public function __construct(
        public readonly ?string $language = null,
        public readonly ?string $flag = null,
        public readonly array $payment_gateways = [],
        public readonly ?string $default_payment_gateway = null,
        public readonly ?string $timezone = null,
    ) {
    }
}
