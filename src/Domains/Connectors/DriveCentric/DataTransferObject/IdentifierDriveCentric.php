<?php

declare(strict_types=1);

namespace Kanvas\Connectors\DriveCentric\DataTransferObject;

use Spatie\LaravelData\Data;

class IdentifierDriveCentric extends Data
{
    public function __construct(
        public string $type,
        public string $value
    ) {
    }
}
