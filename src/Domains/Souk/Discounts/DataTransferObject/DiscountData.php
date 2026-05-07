<?php

declare(strict_types=1);

namespace Kanvas\Souk\Discounts\DataTransferObject;

use DateTime;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

class DiscountData extends Data
{
    public function __construct(
        public string $name,
        public string $description,
        public int $discount_type_id,
        public float $value,
        #[DataCollectionOf(DiscountConditionData::class)]
        public DataCollection $conditions,
        public bool $is_percentage = false,
        public bool $is_one_per_customer = false,
        public ?float $min_order_value = null,
        public ?float $max_discount_amount = null,
        public ?string $code = null,
        public ?DateTime $start_date = null,
        public ?DateTime $end_date = null,
        public bool $is_active = true,
        public ?int $usage_limit = null,
    ) {
    }
}
