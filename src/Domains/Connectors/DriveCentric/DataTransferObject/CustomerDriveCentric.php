<?php

declare(strict_types=1);

namespace Kanvas\Connectors\DriveCentric\DataTransferObject;

use Spatie\LaravelData\Data;

class CustomerDriveCentric extends Data
{
    public function __construct(
        public array $identifiers,
        public bool $isPrimaryBuyer,
        public string $type,
        public string $firstName,
        public string $middleName,
        public string $lastName,
        public ?string $companyName = null,
        public ?string $birthdate = null,
        public array $phones = [],
        public array $emails = [],
    ){}
}
