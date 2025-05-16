<?php

declare(strict_types=1);

namespace Kanvas\Connectors\PasoRapido\DataTransferObject;

use Spatie\LaravelData\Data;

class VerifyCustomerResponse extends Data
{
    public function __construct(
        public readonly string $username,
        public readonly string $lastname,
        public readonly string $device,
        public readonly string $message,
        public readonly string $document,
        public readonly float $balance,
        public readonly string $type,
        public readonly string $reference,
        public readonly string $account,
        public readonly string $status,
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            $data['username'],
            $data['lastname'],
            $data['device'],
            $data['message'],
            $data['document'],
            $data['balance'],
            $data['type'],
            $data['reference'],
            $data['account'],
            $data['status'],
        );
    }
}
