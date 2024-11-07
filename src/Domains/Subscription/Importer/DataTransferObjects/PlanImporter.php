<?php

declare(strict_types=1);

namespace Kanvas\Subscription\Importer\DataTransferObjects;

use Spatie\LaravelData\Data;

class PlanImporter extends Data
{
    public function __construct(
        public int $apps_id,
        public string $name,
        public string $stripe_id,
        public ?string $description = null,
        public ?bool $is_active = true,
        public ?bool $is_default = false
    ) {
    }
}
