<?php

declare(strict_types=1);

namespace Kanvas\Souk\Payments\DataTransferObject;

use Spatie\LaravelData\Data;

class CreditCard extends Data
{
    public function __construct(
        public string $name,
        public string $number,
        public int $exp_month,
        public int $exp_year,
        public int $cvv
    ) {
    }
}
