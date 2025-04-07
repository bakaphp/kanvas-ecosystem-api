<?php

declare(strict_types=1);

namespace Kanvas\Subscription\Importer\DataTransferObjects;

use Spatie\LaravelData\Data;

class PriceImporter extends Data
{
    public function __construct(
        public float $amount,
        public string $currency,
        public string $interval,
        public string $apps_plans_id,
        public string $stripe_id,
        public ?bool $is_active = true,
        public ?bool $is_default = false
    ) {
    }
}
