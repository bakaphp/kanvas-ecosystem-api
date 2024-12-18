<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Credit700\DataTransferObject;

use Kanvas\Guild\Customers\Models\People;
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

    public static function fromMultiple(People $people, string $ssn): self
    {
        $address = $people->address()->first();
        return new self(
            $people->firstname . ' ' . $people->lastname,
            $address?->address,
            $address?->city,
            $address?->state,
            $address?->zip,
            $ssn
        );
    }
}
