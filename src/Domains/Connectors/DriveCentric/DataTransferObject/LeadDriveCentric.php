<?php

declare(strict_types=1);

namespace Kanvas\Connectors\DriveCentric\DataTransferObject;

use Spatie\LaravelData\Data;

class LeadDriveCentric extends Data
{
    public function __construct(
        public array $identifiers,
        public LeadSourceDriveCentric $leadSource,
        public CustomerDriveCentric $customer,
    ) {
    }
}
