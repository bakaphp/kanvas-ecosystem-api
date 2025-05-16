<?php

declare(strict_types=1);

namespace Kanvas\Connectors\DriveCentric\DataTransferObject;

use Spatie\LaravelData\Data;

class LeadSourceDriveCentric extends Data
{
    public function __construct(
        public string $name,
        public string $description,
    ) {
    }
}
