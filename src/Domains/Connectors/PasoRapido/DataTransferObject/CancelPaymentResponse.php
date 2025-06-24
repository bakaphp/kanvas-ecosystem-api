<?php

declare(strict_types=1);

namespace Kanvas\Connectors\PasoRapido\DataTransferObject;

use Spatie\LaravelData\Data;

class CancelPaymentResponse extends Data
{
    public function __construct(
        public readonly string $exists,
        public readonly string $reverted,
        public readonly string $description,
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            $data['exists'],
            $data['reverted'],
            $data['description'],
        );
    }
}
