<?php

declare(strict_types=1);

namespace Kanvas\Filesystem\DataTransferObject;

use Spatie\LaravelData\Data;

class FilesystemEntityUpdate extends Data
{
    public function __construct(
        public string $fieldName,
        public float $weight = 0,
    ) {
    }

    public static function fromMultiple(array $data): self
    {
        return new self(
            $data['field_name'],
            $data['weight'] ?? 0
        );
    }
}
