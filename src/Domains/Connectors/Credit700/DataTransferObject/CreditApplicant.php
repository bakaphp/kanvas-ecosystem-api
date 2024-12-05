<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Credit700\DataTransferObject;

use Spatie\LaravelData\Data;

class CreditApplicant extends Data
{
    public function __construct(
        public readonly string $name,
        public readonly string $address,
        public readonly string $city,
        public readonly string $state,
        public readonly string $zip,
        public readonly string $ssn
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            $data['name'],
            $data['address'],
            $data['city'],
            $data['state'],
            $data['zip'],
            $data['ssn']
        );
    }
}
