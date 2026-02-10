<?php

declare(strict_types=1);

namespace Kanvas\ActionEngine\Pipelines\DataTransferObject;

use Spatie\LaravelData\Data;

class Pipeline extends Data
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $slug = null,
        public readonly ?int $weight = null,
        public readonly ?bool $is_default = null,
    ) {
    }
}
