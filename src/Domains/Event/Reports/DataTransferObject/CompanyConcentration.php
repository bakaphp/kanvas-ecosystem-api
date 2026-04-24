<?php

declare(strict_types=1);

namespace Kanvas\Event\Reports\DataTransferObject;

use Spatie\LaravelData\Data;

class CompanyConcentration extends Data
{
    public function __construct(
        public ?int $organization_id,
        public string $organization_name,
        public int $count,
        public float $percentage,
    ) {
    }
}
