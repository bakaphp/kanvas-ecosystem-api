<?php

declare(strict_types=1);

namespace Kanvas\Scribe\PaymentTerms\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Spatie\LaravelData\Data;

class PaymentTerm extends Data
{
    public function __construct(
        public readonly AppInterface $app,
        public readonly CompanyInterface $company,
        public readonly string $name,
        public readonly int $net_days,
        public readonly ?int $discount_days = null,
        public readonly ?float $discount_pct = null,
        public readonly bool $is_default = false,
        public readonly ?array $metadata = null,
    ) {
    }
}
