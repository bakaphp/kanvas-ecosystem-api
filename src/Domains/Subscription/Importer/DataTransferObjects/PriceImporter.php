<?php

declare(strict_types=1);

namespace Kanvas\Subscription\Importer\DataTransferObjects;

use Spatie\LaravelData\Data;

class PriceImporter extends Data
{
    public function __construct(
        public ?float $amount = null,
        public ?string $currency = null,
        public ?string $interval = null,
        public ?string $apps_plans_id = null,
        public ?string $stripe_id = null,
        public ?bool $is_active = true,
        public ?bool $is_default = false
    ) {
    }
}
