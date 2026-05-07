<?php

declare(strict_types=1);

namespace Kanvas\Souk\Discounts\DataTransferObject;

use Spatie\LaravelData\Attributes\Validation\In;
use Spatie\LaravelData\Data;

class DiscountConditionData extends Data
{
    public function __construct(
        #[In(['product', 'category', 'variant', 'customer', 'customer_group'])]
        public string $type,
        #[In(['in', 'not_in'])]
        public string $operator = 'in',
        public array $values = [],
    ) {
    }
}
